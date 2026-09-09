{{-- The WebTerm admin console.

     Server-rendered with no build step and no new dependency: the plugin's
     runtime requirements are php and librenms/plugin-interfaces, and a CI guard
     fails the build if that changes. Everything visual here is Bootstrap 3 and
     Font Awesome, both supplied by layouts.librenmsv1 -- see DESIGN.md for why
     a `tw:` class would produce no CSS in a vendored plugin.

     Every value below comes from either the monitoring database or an operator,
     so all of it is attacker-influenced and all of it goes through {{ }}.
     Nothing here renders a credential payload, a shared secret, a ticket or a
     host key blob.

     Column headers are deliberately NOT translated. Core does the same
     (resources/views/plugins/admin.blade.php ships literal <th>Name</th>), and
     the alternative is worse: __('Device') resolves against LibreNMS's
     lang/en/device.php on a case-insensitive filesystem and returns an array,
     which is fatal inside {{ }}. --}}
@extendsFirst(['layouts.librenmsv1', 'WebTerm::layouts.standalone'])

@php
    /**
     * Device ids mean nothing to an operator. Resolved once in the controller
     * and rendered here as a link to the device page. A credential or grant can
     * outlive its device, so a missing name degrades to the id rather than a
     * blank cell -- that row is exactly the one somebody is looking for.
     *
     * Returns MARKUP, so every interpolated value is escaped by hand. The
     * "(removed)" note sits inside the anchor: outside it, a screen reader
     * enumerating links announces a bare database id for the one row an
     * auditor is hunting for.
     */
    $deviceLabel = function ($id) use ($deviceNames) {
        $id = (int) $id;

        if ($id <= 0) {
            return '-';
        }

        $name = $deviceNames[$id] ?? null;
        $url = e(url('device/'.$id));

        return $name === null
            ? '<a href="'.$url.'">#'.e((string) $id).' <span class="text-muted">('.e(__('removed from LibreNMS')).')</span></a>'
            : '<a href="'.$url.'">'.e($name).'</a>';
    };

    /**
     * The same identity as plain text, for a confirm() dialog or an accessible
     * name. Never interpolate $deviceLabel into either -- it is markup.
     */
    $deviceName = fn ($id) => $deviceNames[(int) $id] ?? '#'.(int) $id;

    /**
     * Tab labels are mapped, never computed.
     *
     * This was `__(ucfirst($name))`, which ViewTranslationKeyTest cannot see:
     * its regex matches a literal __('...') only. That mattered, because the
     * settings tab produced __('Settings') and LibreNMS ships lang/en/settings.php
     * -- on a case-insensitive filesystem that resolves and returns the whole
     * file as an array, which is fatal inside {{ }}. Mapping also lets the tab
     * read "Host keys" rather than the machine-derived non-word "Hostkeys".
     */
    $tabLabels = [
        'targets' => __('Targets'),
        'credentials' => __('Credentials'),
        'access' => __('Access'),
        'hostkeys' => __('Host keys'),
        'sessions' => __('Sessions'),
        // NOT __('Settings') -- see above.
        'settings' => __('Runtime settings'),
        'audit' => __('Audit'),
    ];

    /**
     * A stored value, rendered verbatim, wrapped in a Bootstrap label only when
     * it is the weaker or more surprising choice. The word stays the visible
     * text so it still matches CLI output and the database character for
     * character, and no meaning is carried by colour alone.
     */
    $mark = fn (string $value, ?string $level) => $level === null
        ? e($value)
        : '<span class="label label-'.e($level).'">'.e($value).'</span>';

    $statusLevel = fn ($status) => match ((string) $status) {
        'rejected' => 'danger',
        'superseded' => 'warning',
        default => null,
    };

    $sessionLevel = fn ($state) => match ((string) $state) {
        'active' => 'success',
        'pending' => 'warning',
        default => 'default',
    };
@endphp

@section('title', __('WebTerm administration').' — '.($tabLabels[$tab] ?? ''))

@section('content')
<div class="container-fluid">
    <h2><i class="fa fa-terminal fa-fw" aria-hidden="true"></i> {{ __('WebTerm') }}</h2>

    @unless (config('webterm.enabled', false))
        {{-- The kill switch is off. Say so before anything else on the page:
             every table below still renders, and none of it can open a shell. --}}
        <div class="alert alert-warning" role="alert">
            <strong>{{ __('WebTerm is switched off.') }}</strong>
            {{ __('Nothing here can open a terminal until it is switched back on. Everything below is still readable, and the kill switch is the first row under Runtime settings.') }}
        </div>
    @endunless

    <div class="panel with-nav-tabs panel-default">
        <div class="panel-heading">
            {{-- These are seven real page loads, not a JavaScript tab widget,
                 so the active one is marked with aria-current="page" rather
                 than role="tab"/aria-selected. --}}
            <ul class="nav nav-tabs">
                @foreach ($tabs as $name)
                    <li class="{{ $tab === $name ? 'active' : '' }}">
                        <a href="{{ url('plugin/webterm/admin') }}?tab={{ $name }}"
                           @if ($tab === $name) aria-current="page" @endif>{{ $tabLabels[$name] ?? $name }}</a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="panel-body">
            @if (session('webterm_status'))
                {{-- The level comes from the controller rather than from
                     matching on the message text. A refusal painted blue was
                     the previous behaviour and it read as success. --}}
                <div class="alert alert-{{ session('webterm_status_level', 'info') }}" role="status" aria-live="polite">
                    {{ session('webterm_status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <strong>{{ __('That was not saved.') }}</strong>
                    <ul style="margin: 6px 0 0; padding-left: 18px;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

    @if ($tab === 'targets')
        <h3>{{ __('Enabled devices') }}</h3>
        <p>
            {{ __('Which devices may be reached, and how. A device is unreachable until it is listed here.') }}
        </p>
        <p>
            <span class="text-muted">
                {{ trans_choice('{0}No devices are enabled.|{1}One device is enabled for terminal access.|[2,*]:count devices are listed.', $targets->count(), ['count' => $targets->count()]) }}
                @if ($targets->count() > 0)
                    {{ trans_choice('{0}None is enabled.|{1}One is enabled.|[2,*]:count are enabled.', $targets->where('enabled', true)->count(), ['count' => $targets->where('enabled', true)->count()]) }}
                    {{ trans_choice('{0}None came from a device group.|{1}One came from a device group.|[2,*]:count came from a device group.', $targets->where('source', 'group')->count(), ['count' => $targets->where('source', 'group')->count()]) }}
                @endif
            </span>
        </p>

        @if ($targets->count() > 6)
            <div class="form-group">
                <label for="webterm-filter-targets">{{ __('Filter these rows') }}</label>
                <input type="search" id="webterm-filter-targets" class="form-control input-sm"
                       data-webterm-filter="#webterm-targets-table"
                       placeholder="{{ __('Device, principal or flow') }}" style="max-width: 320px;">
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped" id="webterm-targets-table">
                <thead>
                    <tr>
                        <th scope="col">Device</th>
                        <th scope="col">Principal</th>
                        <th scope="col">Flow</th>
                        <th scope="col">Host key policy</th>
                        <th scope="col">Algorithms</th>
                        <th scope="col">How enabled</th>
                        <th scope="col">Enabled</th>
                        <th scope="col"><span class="sr-only">{{ __('Action') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($targets as $target)
                    <tr>
                        <td>{!! $deviceLabel($target->device_id) !!}</td>
                        <td>{{ $target->principal }}</td>
                        <td>{{ $target->flow }}</td>
                        <td>{!! $mark((string) $target->host_key_policy, $target->host_key_policy === 'pin' ? null : 'warning') !!}</td>
                        <td>{!! $mark((string) ($target->algorithm_profile ?? 'modern'), ($target->algorithm_profile ?? 'modern') === 'legacy' ? 'warning' : null) !!}</td>
                        <td>
                            @if ($target->source === 'group')
                                <span class="text-muted">{{ __('From device group') }} {{ $target->source_ref }}</span>
                            @else
                                <span class="text-muted">{{ __('Chosen individually') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($target->enabled)
                                {{ __('Yes') }}
                            @else
                                <span class="text-muted">{{ __('No') }}</span>
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ url('plugin/webterm/admin/targets') }}"
                                  @unless ($target->enabled)
                                      data-confirm="{{ __('Enable terminal access to') }} {{ $deviceName($target->device_id) }}?"
                                      onsubmit="return confirm(this.dataset.confirm)"
                                  @endunless>
                                @csrf
                                <input type="hidden" name="device_id" value="{{ $target->device_id }}">
                                <input type="hidden" name="enabled" value="{{ $target->enabled ? 0 : 1 }}">
                                <button class="btn btn-xs btn-default" type="submit">
                                    {{ $target->enabled ? __('Disable') : __('Enable') }}
                                    <span class="sr-only">{{ $deviceName($target->device_id) }}</span>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <strong>{{ __('No device can be reached yet.') }}</strong>
                            {{ __('WebTerm grants nothing until a device is listed here. Enable one below, or run webterm:target:enable on the LibreNMS server.') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <h3>{{ __('Enable one device') }}</h3>
        <p class="text-muted">
            {{ __('Choosing a device that is already listed above replaces its principal, flow, host key policy and algorithm profile, and re-enables it.') }}
        </p>
        <form method="POST" action="{{ url('plugin/webterm/admin/targets/save') }}" style="margin-bottom: 18px;">
            @csrf
            <div class="row">
                <div class="col-sm-4 col-md-3">
                    <div class="form-group">
                        <label for="webterm-target-device">{{ __('Device') }}</label>
                        <select name="device_id" id="webterm-target-device" class="form-control input-sm" required
                                style="min-width: 260px;">
                            <option value=""></option>
                        </select>
                        <span class="help-block"><small>{{ __('Searches the devices you can already see in LibreNMS.') }}</small></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="form-group">
                        <label for="webterm-target-principal">{{ __('SSH login') }}</label>
                        <input name="principal" id="webterm-target-principal" class="form-control input-sm" required>
                        <span class="help-block"><small>{{ __('The account the shell runs as on the device. Audited as the principal.') }}</small></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <div class="form-group">
                        <label for="webterm-target-flow">{{ __('Flow') }}</label>
                        <select name="flow" id="webterm-target-flow" class="form-control input-sm">
                            <option value="database">database</option>
                            <option value="ssh_signer">ssh_signer</option>
                            <option value="kv2">kv2</option>
                            <option value="private_key">private_key</option>
                        </select>
                        <span class="help-block"><small>{{ __('Where the credential comes from. ssh_signer mints a short-lived certificate from Vault; kv2 reads one from Vault; database and private_key use a row stored by this plugin.') }}</small></span>
                    </div>
                </div>
                <div class="col-sm-6 col-md-2">
                    <div class="form-group">
                        <label for="webterm-target-policy">{{ __('Host key policy') }}</label>
                        <select name="host_key_policy" id="webterm-target-policy" class="form-control input-sm">
                            <option value="pin">pin</option>
                            <option value="tofu_first_connect">tofu_first_connect</option>
                        </select>
                        <span class="help-block"><small>{{ __('pin refuses to connect until the key is pinned. tofu_first_connect trusts whatever answers first.') }}</small></span>
                    </div>
                </div>
                <div class="col-sm-6 col-md-2">
                    <div class="form-group">
                        <label for="webterm-target-algorithms">{{ __('Algorithm profile') }}</label>
                        <select name="algorithm_profile" id="webterm-target-algorithms" class="form-control input-sm">
                            <option value="modern">modern</option>
                            <option value="legacy">legacy</option>
                        </select>
                        <span class="help-block"><small>{{ __('modern refuses obsolete ciphers. Choose legacy only for kit that cannot negotiate them.') }}</small></span>
                    </div>
                </div>
            </div>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Enable device') }}</button>
            <span class="text-muted"><small>{{ __('Pin its host key before connecting: webterm:hostkey-scan.') }}</small></span>
        </form>
        <script>
            if (typeof init_select2 === 'function') {
                init_select2('#webterm-target-device', 'device', {}, null, '{{ __('Search devices by name') }}');
            }
        </script>

        <h3>{{ __('Enable a whole device group') }}</h3>
        <p class="text-muted">
            {{ __('Writes one target per member device, recorded as coming from the group. Devices you configured by hand are left alone. Adding a device to the group later does not enable it -- re-apply the group, which is safe to repeat.') }}
            @if (config('webterm.security.refuse_dynamic_groups', true))
                {{ __('Static groups only. LibreNMS recomputes dynamic group membership on every poll, so a device could gain terminal access with nobody deciding it should — allow them in Runtime settings if that trade suits your fleet.') }}
            @else
                <strong>{{ __('Dynamic groups are allowed.') }}</strong>
                {{ __('Enabling one captures the devices in it right now; it is a snapshot, not a standing rule, so devices matching the rule later gain nothing until you re-apply it.') }}
            @endif
        </p>
        <form method="POST" action="{{ url('plugin/webterm/admin/targets/group') }}" style="margin-bottom: 18px;">
            @csrf
            <div class="row">
                <div class="col-sm-4 col-md-3">
                    <div class="form-group">
                        <label for="webterm-group">{{ __('Device group') }}</label>
                        <select name="group_id" id="webterm-group" class="form-control input-sm" required
                                style="min-width: 260px;">
                            <option value=""></option>
                        </select>
                        <span class="help-block"><small>{{ __('Re-applying a group rewrites its rows, including ones you disabled here.') }}</small></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="form-group">
                        <label for="webterm-group-principal">{{ __('SSH login') }}</label>
                        <input name="principal" id="webterm-group-principal" class="form-control input-sm" required>
                        <span class="help-block"><small>{{ __('Applied to every member device.') }}</small></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <div class="form-group">
                        <label for="webterm-group-flow">{{ __('Flow') }}</label>
                        <select name="flow" id="webterm-group-flow" class="form-control input-sm">
                            <option value="database">database</option>
                            <option value="ssh_signer">ssh_signer</option>
                            <option value="kv2">kv2</option>
                            <option value="private_key">private_key</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-6 col-md-2">
                    <div class="form-group">
                        <label for="webterm-group-policy">{{ __('Host key policy') }}</label>
                        <select name="host_key_policy" id="webterm-group-policy" class="form-control input-sm">
                            <option value="pin">pin</option>
                            <option value="tofu_first_connect">tofu_first_connect</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-6 col-md-2">
                    <div class="form-group">
                        <label for="webterm-group-algorithms">{{ __('Algorithm profile') }}</label>
                        <select name="algorithm_profile" id="webterm-group-algorithms" class="form-control input-sm">
                            <option value="modern">modern</option>
                            <option value="legacy">legacy</option>
                        </select>
                    </div>
                </div>
            </div>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Enable group') }}</button>
        </form>
        <script>
            if (typeof init_select2 === 'function') {
                // Restricted to static groups when the plugin refuses dynamic
                // ones, so the picker cannot offer a group the server will
                // reject: core's /ajax/select/device-group takes a type filter.
                init_select2('#webterm-group', 'device-group',
                    @json(config('webterm.security.refuse_dynamic_groups', true) ? ['type' => 'static'] : []),
                    null, '{{ __('Search device groups by name') }}');
            }
        </script>

        <h3>{{ __('Stored credentials') }}</h3>
        <p class="text-muted">
            {{ __('Shown here, not set here. Store or remove one on the Credentials tab, or with webterm:credentials:set on the LibreNMS server. Most specific wins - device, then device group, then the fleet-wide default.') }}
        </p>
        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th scope="col">Applies to</th>
                        <th scope="col">Method</th>
                        <th scope="col">Stored as</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($credentials as $credential)
                    <tr>
                        <td>{!! $credential->scope_type->value === 'device'
                            ? $deviceLabel($credential->scope_ref)
                            : e($credential->scope_type->label($credential->scope_ref)) !!}</td>
                        <td>{{ $credential->method }}</td>
                        <td>{{ $credential->username }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ __('None stored. A target whose flow is database or private_key cannot connect without one.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

    @elseif ($tab === 'credentials')
        <h3>{{ __('Stored credentials') }}</h3>
        <p>
            {{ __('Most specific wins: device, then device group, then the fleet-wide default. A stored secret is never shown again - these fields write, they do not read.') }}
        </p>
        @php
            $vaultTargets = $targets->whereIn('flow', ['ssh_signer', 'kv2'])->count();
        @endphp
        @if ($vaultTargets > 0)
            <p class="text-muted">
                {{ trans_choice(
                    '{1}One enabled target uses a Vault flow and never reads these rows.|[2,*]:count enabled targets use a Vault flow (ssh_signer, kv2) and never read these rows.',
                    $vaultTargets,
                    ['count' => $vaultTargets]
                ) }}
                {{ __('Which store is consulted is decided by credentials.driver, listed under Runtime settings.') }}
            </p>
        @endif

        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th scope="col">Applies to</th>
                        <th scope="col">Method</th>
                        <th scope="col">Stored as</th>
                        <th scope="col">Encryption key</th>
                        <th scope="col"><span class="sr-only">{{ __('Action') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($credentials as $credential)
                    @php
                        $scopeName = $credential->scope_type->value === 'device'
                            ? $deviceName($credential->scope_ref)
                            : $credential->scope_type->label($credential->scope_ref);
                    @endphp
                    <tr>
                        <td>{!! $credential->scope_type->value === 'device'
                            ? $deviceLabel($credential->scope_ref)
                            : e($credential->scope_type->label($credential->scope_ref)) !!}</td>
                        <td>{{ $credential->method }}</td>
                        <td>{{ $credential->username }}</td>
                        <td>{{ $credential->key_id }}</td>
                        <td>
                            {{-- The scope name goes through a data- attribute, not
                                 into a JS string literal: it can contain a quote. --}}
                            <form method="POST" action="{{ url('plugin/webterm/admin/credentials/delete') }}"
                                  data-confirm="{{ __('Remove the stored credential for') }} {{ $scopeName }}? {{ __('Any device that resolved to it will fail to open a shell until another credential applies.') }}"
                                  onsubmit="return confirm(this.dataset.confirm)">
                                @csrf
                                <input type="hidden" name="scope_type" value="{{ $credential->scope_type->value }}">
                                <input type="hidden" name="scope_ref" value="{{ $credential->scope_ref }}">
                                <button class="btn btn-xs btn-danger" type="submit">
                                    {{ __('Remove') }}<span class="sr-only"> {{ $scopeName }}</span>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">{{ __('None stored. Devices whose flow is database or private_key cannot connect until one is.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <h3>{{ __('Store a credential') }}</h3>
        <p class="text-muted">
            {{ __('One credential per scope: storing over a scope that already has one replaces it. Nothing you type here is remembered if saving fails -- deliberately, because a failed submission that remembered the secret would write it to the session store in plaintext.') }}
        </p>
        <form method="POST" action="{{ url('plugin/webterm/admin/credentials') }}" autocomplete="off">
            @csrf
            <div class="row">
                <div class="col-sm-4 col-md-2">
                    <div class="form-group">
                        <label for="webterm-cred-scope">{{ __('Applies to') }}</label>
                        <select name="scope_type" id="webterm-cred-scope" class="form-control input-sm">
                            <option value="device">device</option>
                            <option value="group">device group</option>
                            <option value="global">global (fleet-wide)</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <div class="form-group">
                        <label for="webterm-cred-ref">{{ __('Device or group') }}</label>
                        <select name="scope_ref" id="webterm-cred-ref" class="form-control input-sm" required
                                style="min-width: 260px;">
                            <option value=""></option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <div class="form-group">
                        <label for="webterm-cred-username">{{ __('SSH login') }}</label>
                        <input name="username" id="webterm-cred-username" class="form-control input-sm" required autocomplete="off">
                    </div>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="form-group">
                        <label for="webterm-cred-kind">{{ __('Secret type') }}</label>
                        <select name="secret_kind" id="webterm-cred-kind" class="form-control input-sm">
                            <option value="password">password</option>
                            <option value="private_key">private key</option>
                        </select>
                    </div>
                </div>
            </div>
            {{-- Write-only. Never populated from a stored value, and the failure
                 path does not flash it back. Exactly one field named "secret" is
                 ever enabled; the textarea ships disabled so that holds even
                 when the script below does not run. --}}
            <div class="form-group">
                <label for="webterm-cred-secret" id="webterm-cred-secret-label">{{ __('Password') }}</label>
                <input type="password" name="secret" id="webterm-cred-secret" class="form-control input-sm"
                       required autocomplete="new-password" style="max-width: 420px;">
                <textarea name="secret" id="webterm-cred-secret-key" class="form-control input-sm" rows="6" disabled
                          aria-labelledby="webterm-cred-secret-label"
                          placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                          style="display: none; max-width: 620px; font-family: monospace;"></textarea>
                <span class="help-block"><small>{{ __('Encrypted at rest. It is never rendered back to this page, and it is never written to the audit detail.') }}</small></span>
            </div>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Store credential') }}</button>
        </form>

        <script>
            (function () {
                var scope = document.getElementById('webterm-cred-scope');
                var ref = document.getElementById('webterm-cred-ref');
                var kind = document.getElementById('webterm-cred-kind');
                var pw = document.getElementById('webterm-cred-secret');
                var key = document.getElementById('webterm-cred-secret-key');
                var label = document.getElementById('webterm-cred-secret-label');

                function bindRef() {
                    if (typeof init_select2 !== 'function' || !window.jQuery) { return; }
                    if (jQuery(ref).data('select2')) { jQuery(ref).select2('destroy').empty(); }

                    if (scope.value === 'global') {
                        // NOT disabled: a disabled control is not submitted, and
                        // the controller requires scope_ref -- which made every
                        // fleet-wide write fail on a field nobody can see.
                        // Read-only is expressed by there being one option.
                        ref.innerHTML = '<option value="0" selected>{{ __('the whole fleet') }}</option>';
                        ref.setAttribute('aria-readonly', 'true');
                        return;
                    }

                    ref.removeAttribute('aria-readonly');
                    init_select2('#webterm-cred-ref', scope.value === 'group' ? 'device-group' : 'device', {}, null,
                        scope.value === 'group' ? '{{ __('Search device groups by name') }}' : '{{ __('Search devices by name') }}');
                }

                function bindKind() {
                    var isKey = kind.value === 'private_key';
                    // Only one of the two is ever enabled, so exactly one field
                    // named "secret" is submitted.
                    pw.style.display = isKey ? 'none' : '';
                    pw.disabled = isKey;
                    pw.required = !isKey;
                    key.style.display = isKey ? '' : 'none';
                    key.disabled = !isKey;
                    key.required = isKey;
                    label.textContent = isKey ? '{{ __('Private key') }}' : '{{ __('Password') }}';
                    label.setAttribute('for', isKey ? 'webterm-cred-secret-key' : 'webterm-cred-secret');
                }

                scope.addEventListener('change', bindRef);
                kind.addEventListener('change', bindKind);
                bindRef();
                bindKind();
            })();
        </script>

    @elseif ($tab === 'access')
        <h3>{{ __('Grants') }}</h3>
        <p>
            {{ __('A user needs three things to open a shell: the "use" ability below, a device enabled on the Targets tab, and an allow grant here with no deny matching it.') }}
            {{ __('Shell access is always narrower than being able to see a device. A deny always beats an allow.') }}
        </p>
        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th scope="col">Subject</th>
                        <th scope="col">Object</th>
                        <th scope="col">Effect</th>
                        <th scope="col"><span class="sr-only">{{ __('Action') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($grants as $grant)
                    @php
                        $grantSubject = $grant->subject_type.' '.$grant->subject_ref;
                    @endphp
                    <tr>
                        <td>{{ $grantSubject }}</td>
                        <td>{{ $grant->object_type }}
                            {!! $grant->object_type === 'device' ? $deviceLabel($grant->object_id) : e((string) $grant->object_id) !!}</td>
                        <td>{!! $mark((string) $grant->effect, $grant->effect === 'deny' ? 'danger' : null) !!}</td>
                        <td>
                            <form method="POST" action="{{ url('plugin/webterm/admin/grants/delete') }}"
                                  data-confirm="{{ $grant->effect === 'deny'
                                      ? __('Removing a deny widens access: anyone matched by an allow grant will be able to open a shell here.')
                                      : __('Removing this allow revokes shell access for this subject.') }} {{ __('Continue?') }}"
                                  onsubmit="return confirm(this.dataset.confirm)">
                                @csrf
                                <input type="hidden" name="id" value="{{ $grant->id }}">
                                <button class="btn btn-xs btn-danger" type="submit">
                                    {{ __('Remove') }}<span class="sr-only"> {{ $grantSubject }}</span>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <strong>{{ __('No grants. Nobody can open a terminal.') }}</strong>
                            {{ __('That is the default. Add one below to change it.') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <h4>{{ __('Add a grant') }}</h4>
        <form method="POST" action="{{ url('plugin/webterm/admin/grants') }}">
            @csrf
            {{-- The option values below are NOT translated. They are the stored
                 values an operator matches against webterm:grant output and the
                 database, so translating them would misdescribe what is written.
                 It also avoids a real trap: __('device') collides with LibreNMS's
                 lang/en/device.php and returns that whole file as an array,
                 which is fatal inside {{ }}. --}}
            <div class="row">
                <div class="col-sm-3 col-md-2">
                    <div class="form-group">
                        <label for="webterm-subject-type">{{ __('Subject type') }}</label>
                        <select name="subject_type" id="webterm-subject-type" class="form-control input-sm">
                            <option value="user">user</option>
                            <option value="role">role</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-3 col-md-2">
                    <div class="form-group">
                        <label for="webterm-subject-ref">{{ __('Subject') }}</label>
                        <input name="subject_ref" id="webterm-subject-ref" class="form-control input-sm" required>
                        <span class="help-block"><small>{{ __('For a user, the numeric LibreNMS user id -- that is what is stored and matched. webterm:grant accepts a username; this form does not.') }}</small></span>
                    </div>
                </div>
                <div class="col-sm-3 col-md-2">
                    <div class="form-group">
                        <label for="webterm-object-type">{{ __('Object type') }}</label>
                        <select name="object_type" id="webterm-object-type" class="form-control input-sm">
                            <option value="device">device</option>
                            <option value="group">group</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-3 col-md-3">
                    <div class="form-group">
                        <label for="webterm-object-id">{{ __('Device or group') }}</label>
                        {{-- Driven by core's own /ajax/select endpoints via init_select2,
                             which layouts.librenmsv1 already loads along with jQuery and
                             select2. No asset of ours, and the endpoint filters by the
                             requesting user's device visibility, so the picker cannot list
                             a device the operator could not already see. --}}
                        <select name="object_id" id="webterm-object-id" class="form-control input-sm" required
                                style="min-width: 260px;">
                            <option value=""></option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-3 col-md-2">
                    <div class="form-group">
                        <label for="webterm-effect">{{ __('Effect') }}</label>
                        <select name="effect" id="webterm-effect" class="form-control input-sm">
                            <option value="allow">allow</option>
                            <option value="deny">deny</option>
                        </select>
                        <span class="help-block"><small>{{ __('A deny always wins, whatever else matches.') }}</small></span>
                    </div>
                </div>
            </div>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Add grant') }}</button>
        </form>
        <noscript>
            <p class="text-muted"><small>
                {{ __('The device picker needs JavaScript. Without it, use the command line on the LibreNMS server, as the librenms user:') }}
                <code>./lnms webterm:grant --user=&lt;user&gt; --device=&lt;hostname&gt;</code>
            </small></p>
        </noscript>

        <script>
            (function () {
                // init_select2 is defined by LibreNMS's own html/js/librenms.js,
                // loaded as a blocking script by layouts.librenmsv1. Guarded so
                // that a core change degrades to an inert field rather than a
                // console error on an admin page.
                if (typeof init_select2 !== 'function') { return; }

                var type = document.getElementById('webterm-object-type');
                var target = '#webterm-object-id';

                function bind() {
                    if (window.jQuery && jQuery(target).data('select2')) {
                        jQuery(target).select2('destroy').empty();
                    }
                    // Core exposes 'device' and 'device-group' select types.
                    var isGroup = type.value === 'group';
                    init_select2(target, isGroup ? 'device-group' : 'device', {}, null,
                        isGroup ? '{{ __('Search device groups by name') }}' : '{{ __('Search devices by name') }}');
                }

                type.addEventListener('change', bind);
                bind();
            })();
        </script>

        <h3 style="margin-top: 24px;">{{ __('Abilities') }}</h3>
        <p>
            {{ __('"use" lets somebody open a terminal at all -- every grant above is inert without it. "admin" opens this console. "audit.view" is reserved and currently grants nothing.') }}
        </p>
        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th scope="col">User ID</th>
                        <th scope="col">Ability</th>
                        <th scope="col"><span class="sr-only">{{ __('Action') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($abilities as $ability)
                    <tr>
                        <td>{{ $ability->user_id }}</td>
                        <td>{{ $ability->ability }}</td>
                        <td>
                            @if ($ability->ability === 'admin' && (int) $ability->user_id === (int) auth()->id())
                                <button class="btn btn-xs btn-default" type="button" disabled>{{ __('Revoke') }}</button>
                                <span class="text-muted"><small>{{ __('Your own admin ability. Remove it with webterm:ability, so you cannot lock yourself out of the console that grants it back.') }}</small></span>
                            @else
                                <form method="POST" action="{{ url('plugin/webterm/admin/abilities/delete') }}"
                                      data-confirm="{{ __('Revoke') }} {{ $ability->ability }} {{ __('from user') }} {{ $ability->user_id }}?"
                                      onsubmit="return confirm(this.dataset.confirm)">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $ability->user_id }}">
                                    <input type="hidden" name="ability" value="{{ $ability->ability }}">
                                    <button class="btn btn-xs btn-danger" type="submit">
                                        {{ __('Revoke') }}<span class="sr-only"> {{ $ability->ability }} {{ __('from user') }} {{ $ability->user_id }}</span>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">
                            <strong>{{ __('Nobody holds a WebTerm ability.') }}</strong>
                            {{ __('Without "use", no grant above can open a terminal.') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <h4>{{ __('Grant an ability') }}</h4>
        <form method="POST" action="{{ url('plugin/webterm/admin/abilities') }}">
            @csrf
            <div class="row">
                <div class="col-sm-3 col-md-2">
                    <div class="form-group">
                        <label for="webterm-ability-user">{{ __('LibreNMS user id') }}</label>
                        <input name="user_id" id="webterm-ability-user" type="number" min="1" class="form-control input-sm" required>
                    </div>
                </div>
                <div class="col-sm-3 col-md-2">
                    <div class="form-group">
                        <label for="webterm-ability">{{ __('Ability') }}</label>
                        <select name="ability" id="webterm-ability" class="form-control input-sm">
                            <option value="use">use</option>
                            <option value="admin">admin</option>
                            <option value="audit.view">audit.view</option>
                        </select>
                    </div>
                </div>
            </div>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Grant ability') }}</button>
        </form>

    @elseif ($tab === 'hostkeys')
        <h3>{{ __('Pinned host keys') }}</h3>
        <p>
            {{ __('Read-only here on purpose: resetting a pin and switching a target to trust-on-first-connect are individually reasonable and together amount to turning off host key verification from a browser.') }}
        </p>
        <p class="text-muted">
            {{ __('Run these on the LibreNMS server, as the librenms user:') }}
            <code>./lnms webterm:hostkey-scan</code>
            <code>./lnms webterm:hostkey-reset</code>
        </p>
        @php
            $needReview = $hostKeys->where('status', '!=', 'pinned')->count();
        @endphp
        @if ($hostKeys->count() > 0)
            <p>
                <span class="text-muted">{{ trans_choice('{1}One key is stored.|[2,*]:count keys are stored.', $hostKeys->count(), ['count' => $hostKeys->count()]) }}</span>
                @if ($needReview > 0)
                    <strong>{{ trans_choice('{1}One needs review.|[2,*]:count need review.', $needReview, ['count' => $needReview]) }}</strong>
                @endif
            </p>
        @endif

        @if ($hostKeys->count() > 6)
            <div class="form-group">
                <label for="webterm-filter-hostkeys">{{ __('Filter these rows') }}</label>
                <input type="search" id="webterm-filter-hostkeys" class="form-control input-sm"
                       data-webterm-filter="#webterm-hostkeys-table"
                       placeholder="{{ __('Device, algorithm or fingerprint') }}" style="max-width: 320px;">
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped" id="webterm-hostkeys-table">
                <thead>
                    <tr>
                        <th scope="col">Device</th>
                        <th scope="col">Algorithm</th>
                        <th scope="col">Fingerprint</th>
                        <th scope="col">Status</th>
                        <th scope="col">Pinned</th>
                        <th scope="col">First seen</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($hostKeys as $key)
                    <tr>
                        <td>{!! $deviceLabel($key->device_id) !!}</td>
                        <td>{{ $key->algorithm }}</td>
                        {{-- Not <code>: core renders that red on pink in both
                             themes, and a whole column of it reads as errors.
                             The monospace is for the character grid only. --}}
                        <td style="font-family: monospace;">{{ $key->fingerprint }}</td>
                        <td>
                            {!! $mark((string) $key->status, $statusLevel($key->status)) !!}
                            @if ($key->status === 'rejected')
                                <br><small class="text-muted">{{ __('The device presented a different key. Review before re-pinning.') }}</small>
                            @elseif ($key->status === 'superseded')
                                <br><small class="text-muted">{{ __('Replaced by a newer key, kept so you can see what it was.') }}</small>
                            @endif
                        </td>
                        <td>{{ $key->pinned_at ?? '-' }}</td>
                        <td>{{ $key->first_seen_at ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <strong>{{ __('No host keys are pinned.') }}</strong>
                            {{ __('Any target whose policy is pin will refuse to connect until one is. Pin them with webterm:hostkey-scan, on the LibreNMS server, as the librenms user.') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

    @elseif ($tab === 'sessions')
        <h3>{{ __('Recent sessions') }}</h3>
        <p>
            {{ __('The 50 most recent sessions, newest first.') }}
            {{ __('Without LibreNMS\'s scheduler installed nothing reaps them, so sessions sit at pending forever - including ones you closed. Clear the backlog with webterm:reconcile on the LibreNMS server, as the librenms user.') }}
        </p>
        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th scope="col">State</th>
                        <th scope="col">User ID</th>
                        <th scope="col">Device</th>
                        <th scope="col">Login</th>
                        <th scope="col">Started</th>
                        <th scope="col">Session</th>
                        <th scope="col"><span class="sr-only">{{ __('Action') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($sessions as $session)
                    <tr>
                        <td>{!! $mark((string) $session->state, $sessionLevel($session->state)) !!}</td>
                        <td>{{ $session->user_id }}</td>
                        <td>{!! $deviceLabel($session->device_id) !!}</td>
                        <td>{{ $session->principal ?? '-' }}</td>
                        <td>{{ $session->started_at }}</td>
                        <td><small class="text-muted" style="font-family: monospace;">{{ $session->session_id }}</small></td>
                        <td>
                            @if ($session->state !== 'closed')
                                <form method="POST" action="{{ url('plugin/webterm/admin/sessions/kill') }}"
                                      data-confirm="{{ __('Terminate this session on') }} {{ $deviceName($session->device_id) }}? {{ __('The shell is cut immediately and cannot be restored.') }}"
                                      onsubmit="return confirm(this.dataset.confirm)">
                                    @csrf
                                    <input type="hidden" name="session_id" value="{{ $session->session_id }}">
                                    <button class="btn btn-xs btn-danger" type="submit">
                                        {{ __('Terminate') }}<span class="sr-only"> {{ $deviceName($session->device_id) }}</span>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <strong>{{ __('No sessions recorded.') }}</strong>
                            {{ __('A row appears here when somebody opens a terminal from a device page.') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

    @elseif ($tab === 'settings')
        <h3>{{ __('Runtime settings') }}</h3>
        <p>
            {{ __('Changes made here take effect on the next request. A change made with webterm:config on the host waits out a 60-second cache instead.') }}
            {{ __('Anything not editable here is listed below with the reason - a control that appears to work and does nothing is worse than no control.') }}
        </p>

        @php
            $killSwitch = $editable['enabled'] ?? null;
            $groupTitles = [
                'session' => __('Session limits'),
                'security' => __('Security'),
                'audit' => __('Audit'),
                'gateway' => __('Gateway'),
            ];
            $lastGroup = null;
        @endphp

        @if ($killSwitch !== null)
            {{-- Lifted out of the table: it is the one setting that switches the
                 whole product off, and in a striped row it looked exactly like
                 the gateway request timeout. --}}
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h4 class="panel-title">{{ $killSwitch[1] }}</h4>
                </div>
                <div class="panel-body">
                    <p>{{ $killSwitch[2] }}</p>
                    <p class="text-muted">
                        {{ __('Switching it off leaves this console readable so you can switch it back on, but refuses every other write until you do. On the LibreNMS server, as the librenms user, the equivalent is:') }}
                        <code>./lnms webterm:config set enabled true</code>
                    </p>
                    <form method="POST" action="{{ url('plugin/webterm/admin/settings') }}" class="form-inline"
                          data-confirm="{{ __('Switch WebTerm off? Every terminal closes and no new one can open until it is switched back on.') }}"
                          onsubmit="return this.value.value !== 'false' || confirm(this.dataset.confirm)">
                        @csrf
                        <input type="hidden" name="key" value="enabled">
                        <div class="form-group">
                            <label for="webterm-set-enabled" class="sr-only">{{ $killSwitch[1] }}</label>
                            <select name="value" id="webterm-set-enabled" class="form-control input-sm">
                                <option value="true" @selected(config('webterm.enabled'))>true</option>
                                <option value="false" @selected(! config('webterm.enabled'))>false</option>
                            </select>
                        </div>
                        <button class="btn btn-sm btn-primary" type="submit">
                            {{ __('Save') }}<span class="sr-only"> {{ $killSwitch[1] }}</span>
                        </button>
                    </form>
                </div>
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th scope="col" style="width: 30%;">Setting</th>
                        <th scope="col" style="width: 22%;">Value</th>
                        <th scope="col">What it does</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($editable as $key => [$type, $label, $help])
                    @continue ($key === 'enabled')
                    @php
                        $group = str_contains($key, '.') ? explode('.', $key)[0] : '';
                        $slug = \Illuminate\Support\Str::slug($key);
                    @endphp
                    @if ($group !== $lastGroup)
                        @php $lastGroup = $group; @endphp
                        <tr class="active">
                            <td colspan="3"><strong>{{ $groupTitles[$group] ?? $group }}</strong></td>
                        </tr>
                    @endif
                    <tr>
                        <td>
                            <label for="webterm-set-{{ $slug }}" style="margin: 0;"><strong>{{ $label }}</strong></label>
                            <br><small class="text-muted">{{ $key }}</small>
                        </td>
                        <td>
                            <form method="POST" action="{{ url('plugin/webterm/admin/settings') }}" class="form-inline">
                                @csrf
                                <input type="hidden" name="key" value="{{ $key }}">
                                @if ($type === 'bool')
                                    <select name="value" id="webterm-set-{{ $slug }}" class="form-control input-sm"
                                            aria-describedby="webterm-help-{{ $slug }}">
                                        <option value="true" @selected(config('webterm.'.$key))>true</option>
                                        <option value="false" @selected(! config('webterm.'.$key))>false</option>
                                    </select>
                                @elseif (str_starts_with($type, 'enum:'))
                                    <select name="value" id="webterm-set-{{ $slug }}" class="form-control input-sm"
                                            aria-describedby="webterm-help-{{ $slug }}">
                                        @foreach (explode(',', substr($type, 5)) as $option)
                                            <option value="{{ $option }}" @selected(config('webterm.'.$key) === $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    {{-- The server contract is ctype_digit, so the
                                         browser can refuse a bad value before it
                                         costs a round trip. --}}
                                    <input name="value" id="webterm-set-{{ $slug }}" type="number" min="0" step="1"
                                           inputmode="numeric" class="form-control input-sm" style="width: 120px;"
                                           aria-describedby="webterm-help-{{ $slug }}"
                                           value="{{ config('webterm.'.$key) }}">
                                @endif
                                <button class="btn btn-sm btn-primary" type="submit">
                                    {{ __('Save') }}<span class="sr-only"> {{ $label }}</span>
                                </button>
                            </form>
                        </td>
                        <td><small class="text-muted" id="webterm-help-{{ $slug }}">{{ $help }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <h3>{{ __('Not editable here') }}</h3>
        <p class="text-muted">
            {{ __('These are set on the LibreNMS server, not from a browser. Read the current values with webterm:config list.') }}
        </p>
        <div class="table-responsive">
            <table class="table table-hover table-condensed">
                <thead>
                    <tr>
                        <th scope="col" style="width: 30%;">Setting</th>
                        <th scope="col">Why it is not editable here</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($readOnlySettings as $key => $why)
                    <tr>
                        <td><strong class="text-muted">{{ $key }}</strong></td>
                        <td><small class="text-muted">{{ $why }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

    @else
        <h3>{{ __('Recent events') }}</h3>
        <p>
            {{ __('The 100 most recent events. The off-box copy, if you configured one, is the record that survives a compromise of this host.') }}
            @if (config('webterm.audit.syslog', true))
                {{ __('This install writes every event to syslog as LOG_AUTHPRIV.') }}
            @else
                <strong>{{ __('This install is not writing to syslog, so nothing here survives a compromise of this host.') }}</strong>
            @endif
        </p>

        @if ($audit->count() > 6)
            <div class="form-group">
                <label for="webterm-filter-audit">{{ __('Filter these rows') }}</label>
                <input type="search" id="webterm-filter-audit" class="form-control input-sm"
                       data-webterm-filter="#webterm-audit-table"
                       placeholder="{{ __('Event, user, device or reason') }}" style="max-width: 320px;">
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover table-condensed table-striped" id="webterm-audit-table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col"><span class="sr-only">{{ __('Severity') }}</span></th>
                        <th scope="col">Event</th>
                        <th scope="col">User</th>
                        <th scope="col">Device</th>
                        <th scope="col">Reason</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($audit as $entry)
                    <tr>
                        <td>{{ $entry->occurred_at }}</td>
                        <td>
                            {{-- Only the exceptions are marked, so what is marked
                                 is what the eye should find. The severity scale is
                                 LibreNMS's own. --}}
                            @if ((int) $entry->severity >= 5)
                                <span class="label label-danger">{{ __('Error') }}</span>
                            @elseif ((int) $entry->severity === 4)
                                <span class="label label-warning">{{ __('Warning') }}</span>
                            @endif
                        </td>
                        <td>{{ $entry->event }}</td>
                        <td>
                            @if ($entry->username !== null && $entry->username !== '')
                                {{ $entry->username }}
                            @else
                                <span class="text-muted">{{ __('System') }}</span>
                            @endif
                        </td>
                        <td>{!! $entry->device_id ? $deviceLabel($entry->device_id) : '-' !!}</td>
                        <td>
                            @if ($entry->reason_code !== null && $entry->reason_code !== '')
                                {{ $entry->reason_code }}
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <strong>{{ __('Nothing recorded yet.') }}</strong>
                            {{ __('WebTerm appends a record when somebody requests, opens, is refused or closes a session, and when a target, credential, grant or setting changes. Rows older than the audit retention are pruned, so an idle install looks like this too.') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
        </div>
    </div>
</div>

{{-- One filter for every table that asked for one. jQuery comes from the host
     layout; nothing is added. Guarded so a core change leaves an inert box
     rather than a console error on an admin page. --}}
<script>
    (function () {
        if (!window.jQuery) { return; }

        jQuery('[data-webterm-filter]').each(function () {
            var input = jQuery(this);
            var rows = jQuery(input.data('webterm-filter')).find('tbody tr');

            input.on('input', function () {
                var needle = input.val().toLowerCase();

                rows.each(function () {
                    var row = jQuery(this);
                    row.toggle(needle === '' || row.text().toLowerCase().indexOf(needle) !== -1);
                });
            });
        });
    })();
</script>
@endsection
