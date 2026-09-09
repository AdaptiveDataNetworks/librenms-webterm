{{-- The terminal itself, embeddable anywhere.

     Included by both the full-page view and the device-page tab, so the two
     cannot drift. The tab is the one that matters: a terminal that replaces the
     whole page loses the device header, the breadcrumb and any way back, which
     is the entire reason the tab exists.

     The terminal is served by the gateway and embedded in an iframe, so xterm.js
     never has to be published into LibreNMS's html/ tree. The ticket is handed
     over by postMessage rather than in the iframe URL: a query string is written
     to the proxy access log and leaks through Referer, and the ticket is a
     credential.

     Three values may be supplied by the includer:

       $webtermDeviceId    the device to connect to; the full-page view leaves it
                           unset and takes it from the query string instead.
       $webtermAutoConnect whether to mint on load. The full-page route was
                           reached with an explicit ?device=, so it may; the
                           device tab may not -- opening a tab is not consent to
                           start an audited SSH session and spend a concurrency
                           slot. DeviceTabPresenter says the same thing in its
                           own comment: "a session is created when the operator
                           clicks, not when the tab renders".
       $webtermFrameHeight how tall the frame is, because the chrome above it
                           differs between the two.

     Every translated string reaches JavaScript through @json, never through a
     quoted {{ }}: Blade escapes with ENT_QUOTES, HTML entities are not decoded
     inside a <script>, and the first translation containing an apostrophe would
     otherwise render "&#039;" to the operator. --}}
@php
    $webtermAutoConnect = $webtermAutoConnect ?? true;
    $webtermFrameHeight = $webtermFrameHeight ?? '70vh';
@endphp

{{-- Hidden only when we are about to mint anyway. --}}
<div id="webterm-start" @if ($webtermAutoConnect) style="display: none;" @endif>
    <button type="button" class="btn btn-primary" id="webterm-open">
        <i class="fa fa-terminal fa-fw" aria-hidden="true"></i> {{ __('Open terminal') }}
    </button>
    <span class="text-muted"><small>{{ __('Opens an SSH session and records it in the audit log.') }}</small></span>
</div>

<div id="webterm-status" class="alert alert-info" role="status" aria-live="polite" aria-atomic="true"
     style="display: none;">
    <span id="webterm-status-text">{{ __('Requesting a terminal session…') }}</span>
    <button type="button" class="btn btn-default btn-xs" id="webterm-retry"
            style="display: none; margin-left: 8px;">{{ __('Try again') }}</button>
</div>

<form id="webterm-stepup" class="well well-sm" style="display: none;">
    <p><strong>{{ __('Confirm your identity to open a terminal') }}</strong></p>
    <div class="form-group" style="margin-bottom: 0;">
        <label for="webterm-code">{{ __('Authenticator code') }}</label>
        <div class="input-group" style="max-width: 320px;">
            <input type="text" id="webterm-code" class="form-control"
                   inputmode="numeric" autocomplete="one-time-code" maxlength="8"
                   spellcheck="false" aria-describedby="webterm-stepup-help">
            <span class="input-group-btn">
                <button class="btn btn-primary" type="submit" id="webterm-verify">{{ __('Confirm') }}</button>
            </span>
        </div>
        <span class="help-block" id="webterm-stepup-help"><small>
            {{ __('WebTerm asks for a second factor before opening a terminal, independently of your login session, and the attempt is recorded. This is the code from the authenticator you enrolled in LibreNMS; if you have not enrolled one, a terminal cannot be opened for you.') }}
        </small></span>
    </div>
</form>

{{-- No border of its own: the panel that wraps this on the device tab already
     draws the host's, and a literal colour would follow neither theme. --}}
<iframe id="webterm-frame"
        title="{{ __('Terminal') }}"
        style="display: none; width: 100%; height: {{ $webtermFrameHeight }}; border: 0;"
        sandbox="allow-scripts allow-same-origin"></iframe>

<noscript>
    <p class="text-muted">
        {{ __('The terminal needs JavaScript. Without it, connect with an SSH client instead.') }}
    </p>
</noscript>

<script>
(function () {
    'use strict';

    var suppliedId = @json($webtermDeviceId ?? null);
    var deviceId = suppliedId === null
        ? new URLSearchParams(window.location.search).get('device')
        : suppliedId;

    var autoConnect = @json($webtermAutoConnect);
    var startEl = document.getElementById('webterm-start');
    var statusEl = document.getElementById('webterm-status');
    var statusText = document.getElementById('webterm-status-text');
    var retryEl = document.getElementById('webterm-retry');
    var stepUpEl = document.getElementById('webterm-stepup');
    var codeEl = document.getElementById('webterm-code');
    var verifyEl = document.getElementById('webterm-verify');
    var frame = document.getElementById('webterm-frame');
    var token = document.querySelector('meta[name="csrf-token"]');
    var pendingTicket = null;

    var TEXT = {
        requesting: @json(__('Requesting a terminal session…')),
        confirming: @json(__('Confirming…')),
        confirm: @json(__('Confirm')),
        couldNotOpen: @json(__('Could not open a terminal.')),
        couldNotReach: @json(__('Could not reach LibreNMS.')),
        couldNotCheck: @json(__('Could not reach LibreNMS to check that code.')),
        rejected: @json(__('That code was not accepted.')),
        noDevice: @json(__('No device selected.')),
        noAnswer: @json(__('The gateway did not answer. The terminal may still be reachable — try again.'))
    };

    function status(message, level, retryable) {
        statusEl.className = 'alert alert-' + (level || 'info');
        statusText.textContent = message;
        retryEl.style.display = retryable ? '' : 'none';
        statusEl.style.display = '';
        startEl.style.display = 'none';
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
            },
            body: JSON.stringify(body)
        });
    }

    function mint() {
        status(TEXT.requesting, 'info', false);

        post(@json(route('webterm.session.store')), { device_id: deviceId })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                if (result.status === 428) {
                    statusEl.style.display = 'none';
                    stepUpEl.style.display = '';
                    codeEl.focus();
                    return;
                }

                if (result.status !== 200) {
                    // The reason code is rendered verbatim beside the message so
                    // it can be matched against webterm:why and the audit trail.
                    var message = result.data.message || TEXT.couldNotOpen;

                    if (result.data.reason) {
                        message += ' (' + result.data.reason + ')';
                    }

                    status(message, 'danger', true);
                    return;
                }

                connect(result.data);
            })
            .catch(function () {
                status(TEXT.couldNotReach, 'danger', true);
            });
    }

    function connect(session) {
        pendingTicket = session.ticket;
        statusEl.style.display = 'none';
        stepUpEl.style.display = 'none';
        startEl.style.display = 'none';
        // display:'' would restore the UA default of inline, which leaves the
        // frame sitting on a text baseline with dead space beneath it.
        frame.style.display = 'block';

        // The gateway page tells us when it is ready; only then does the ticket
        // cross, and it crosses by postMessage, never in the URL.
        frame.src = session.ui_url + '?ws=' + encodeURIComponent(
            (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + session.ws_url
        );

        // A misconfigured proxy 404s the gateway page, webterm.ready never
        // arrives, and the surface would otherwise be a permanently blank box.
        window.setTimeout(function () {
            if (pendingTicket !== null) {
                frame.style.display = 'none';
                status(TEXT.noAnswer, 'danger', true);
            }
        }, 10000);
    }

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.type !== 'webterm.ready' || !pendingTicket) {
            return;
        }

        // Same-origin only: the gateway is proxied under the LibreNMS origin.
        if (event.origin !== window.location.origin) {
            return;
        }

        frame.contentWindow.postMessage(
            { type: 'webterm.connect', ticket: pendingTicket },
            window.location.origin
        );
        pendingTicket = null;
        frame.focus();
    });

    // A real form, so Enter submits the one-time code -- which is the muscle
    // memory of every other code field, and what autocomplete="one-time-code"
    // sets the platform up to expect.
    stepUpEl.addEventListener('submit', function (event) {
        event.preventDefault();

        verifyEl.disabled = true;
        verifyEl.textContent = TEXT.confirming;

        post(@json(route('webterm.stepup')), { code: codeEl.value })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.satisfied) {
                    stepUpEl.style.display = 'none';
                    mint();
                    return;
                }

                status(data.message || TEXT.rejected, 'warning', false);
                statusEl.style.display = '';
                stepUpEl.style.display = '';
                codeEl.value = '';
                codeEl.focus();
            })
            .catch(function () {
                status(TEXT.couldNotCheck, 'danger', false);
                stepUpEl.style.display = '';
            })
            .then(function () {
                verifyEl.disabled = false;
                verifyEl.textContent = TEXT.confirm;
            });
    });

    document.getElementById('webterm-open').addEventListener('click', mint);
    retryEl.addEventListener('click', mint);

    if (!deviceId) {
        status(TEXT.noDevice, 'warning', false);
    } else if (autoConnect) {
        mint();
    }
})();
</script>
