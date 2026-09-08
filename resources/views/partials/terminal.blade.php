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

     $webtermDeviceId may be supplied by the includer (the tab does); the
     full-page view leaves it unset and the device comes from the query string. --}}
<div class="row">
    <div class="col-md-12">
        <div id="webterm-status" class="alert alert-info">{{ __('Requesting a terminal session…') }}</div>
        <div id="webterm-stepup" style="display: none;" class="panel panel-default">
            <div class="panel-body">
                <label for="webterm-code">{{ __('Confirm your identity to open a terminal') }}</label>
                <div class="input-group" style="max-width: 320px;">
                    <input type="text" id="webterm-code" class="form-control"
                           inputmode="numeric" autocomplete="one-time-code"
                           placeholder="{{ __('Authenticator code') }}">
                    <span class="input-group-btn">
                        <button class="btn btn-primary" id="webterm-verify">{{ __('Confirm') }}</button>
                    </span>
                </div>
            </div>
        </div>
        <iframe id="webterm-frame"
                title="{{ __('Terminal') }}"
                style="display: none; width: 100%; height: 70vh; border: 1px solid #30363d; border-radius: 4px;"
                sandbox="allow-scripts allow-same-origin"></iframe>
    </div>
</div>

<script>
(function () {
    'use strict';

    var deviceId = @json($webtermDeviceId ?? null)
        || new URLSearchParams(window.location.search).get('device');
    var statusEl = document.getElementById('webterm-status');
    var stepUpEl = document.getElementById('webterm-stepup');
    var frame = document.getElementById('webterm-frame');
    var token = document.querySelector('meta[name="csrf-token"]');
    var pendingTicket = null;

    function status(message, level) {
        statusEl.className = 'alert alert-' + (level || 'info');
        statusEl.textContent = message;
        statusEl.style.display = '';
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
        status('{{ __('Requesting a terminal session…') }}');

        post('{{ route('webterm.session.store') }}', { device_id: deviceId })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                if (result.status === 428) {
                    statusEl.style.display = 'none';
                    stepUpEl.style.display = '';
                    document.getElementById('webterm-code').focus();
                    return;
                }

                if (result.status !== 200) {
                    status(result.data.message || '{{ __('Could not open a terminal.') }}', 'danger');
                    return;
                }

                connect(result.data);
            })
            .catch(function () {
                status('{{ __('Could not reach LibreNMS.') }}', 'danger');
            });
    }

    function connect(session) {
        pendingTicket = session.ticket;
        statusEl.style.display = 'none';
        stepUpEl.style.display = 'none';
        frame.style.display = '';

        // The gateway page tells us when it is ready; only then does the ticket
        // cross, and it crosses by postMessage, never in the URL.
        frame.src = session.ui_url + '?ws=' + encodeURIComponent(
            (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + session.ws_url
        );
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
    });

    document.getElementById('webterm-verify').addEventListener('click', function () {
        var code = document.getElementById('webterm-code').value;

        post('{{ route('webterm.stepup') }}', { code: code })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.satisfied) {
                    stepUpEl.style.display = 'none';
                    mint();
                    return;
                }
                status(data.message, 'warning');
                statusEl.style.display = '';
            });
    });

    if (!deviceId) {
        status('{{ __('No device selected.') }}', 'warning');
    } else {
        mint();
    }
})();
</script>
