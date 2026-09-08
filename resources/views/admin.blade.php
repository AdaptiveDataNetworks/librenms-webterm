{{-- The WebTerm admin console.

     Server-rendered with no build step and no new dependency: the plugin's
     runtime requirements are php and librenms/plugin-interfaces, and a CI guard
     fails the build if that changes.

     Every value below comes from either the monitoring database or an operator,
     so all of it is attacker-influenced and all of it goes through {{ }}.
     Nothing here renders a credential payload, a shared secret, a ticket or a
     host key blob. --}}
@extendsFirst(['layouts.librenmsv1', 'WebTerm::layouts.standalone'])

@php
    /**
     * Device ids mean nothing to an operator. Resolved once in the controller
     * and rendered here as a link to the device page. A credential or grant can
     * outlive its device, so a missing name degrades to the id rather than a
     * blank cell -- that row is exactly the one somebody is looking for.
     */
    $deviceLabel = function ($id) use ($deviceNames) {
        $id = (int) $id;

        if ($id <= 0) {
            return '-';
        }

        $name = $deviceNames[$id] ?? null;
        $url = e(url('device/'.$id));

        return $name === null
            ? '<a href="'.$url.'">#'.e((string) $id).'</a> <span class="text-muted"><small>(removed)</small></span>'
            : '<a href="'.$url.'">'.e($name).'</a>';
    };
@endphp

@section('title', __('WebTerm administration'))

@section('content')
<div class="container-fluid">
    <h2>{{ __('WebTerm') }}</h2>

    @if (session('webterm_status'))
        <div class="alert alert-info">{{ session('webterm_status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul style="margin: 0; padding-left: 18px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <ul class="nav nav-tabs" style="margin-bottom: 14px;">
        @foreach ($tabs as $name)
            <li class="{{ $tab === $name ? 'active' : '' }}">
                <a href="{{ url('plugin/webterm/admin') }}?tab={{ $name }}">{{ __(ucfirst($name)) }}</a>
            </li>
        @endforeach
    </ul>

    @if ($tab === 'targets')
        <p class="text-muted">
            {{ __('Which devices may be reached, and how. Credentials are set from the command line only.') }}
        </p>
        <table class="table table-condensed table-striped">
            <tr><th>Device</th><th>Principal</th><th>Flow</th><th>Host key policy</th><th>Enabled</th><th></th></tr>
            @forelse ($targets as $target)
                <tr>
                    <td>{!! $deviceLabel($target->device_id) !!}</td>
                    <td>{{ $target->principal }}</td>
                    <td>{{ $target->flow }}</td>
                    <td>{{ $target->host_key_policy }}</td>
                    <td>{{ $target->enabled ? __('Yes') : __('No') }}</td>
                    <td>
                        <form method="POST" action="{{ url('plugin/webterm/admin/targets') }}">
                            @csrf
                            <input type="hidden" name="device_id" value="{{ $target->device_id }}">
                            <input type="hidden" name="enabled" value="{{ $target->enabled ? 0 : 1 }}">
                            <button class="btn btn-xs btn-default" type="submit">
                                {{ $target->enabled ? __('Disable') : __('Enable') }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">{{ __('No targets. Create one with webterm:target:enable.') }}</td></tr>
            @endforelse
        </table>

        <form method="POST" action="{{ url('plugin/webterm/admin/targets/save') }}" class="form-inline" style="margin-bottom: 18px;">
            @csrf
            <select name="device_id" id="webterm-target-device" class="form-control input-sm" required style="min-width: 260px;"></select>
            <input name="principal" class="form-control input-sm" placeholder="{{ __('SSH login (principal)') }}" required>
            <select name="flow" class="form-control input-sm">
                <option value="database">database</option>
                <option value="ssh_signer">ssh_signer</option>
                <option value="kv2">kv2</option>
                <option value="private_key">private_key</option>
            </select>
            <select name="host_key_policy" class="form-control input-sm">
                <option value="pin">pin</option>
                <option value="tofu_first_connect">tofu_first_connect</option>
            </select>
            <select name="algorithm_profile" class="form-control input-sm">
                <option value="modern">modern</option>
                <option value="legacy">legacy</option>
            </select>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Enable device') }}</button>
        </form>
        <script>
            if (typeof init_select2 === 'function') { init_select2('#webterm-target-device', 'device', {}); }
        </script>

        <h4>{{ __('Stored credentials') }}</h4>
        <p class="text-muted">
            {{ __('Shown, never set, from here: obtaining a device credential should require shell access to this host, not an admin session in a browser. Most specific wins - device, then group, then global.') }}
        </p>
        <table class="table table-condensed table-striped">
            <tr><th>Applies to</th><th>Method</th><th>Stored as</th></tr>
            @forelse ($credentials as $credential)
                <tr>
                    <td>{!! $credential->scope_type->value === 'device'
                        ? $deviceLabel($credential->scope_ref)
                        : e($credential->scope_type->label($credential->scope_ref)) !!}</td>
                    <td>{{ $credential->method }}</td>
                    <td>{{ $credential->username }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-muted">{{ __('None stored.') }}</td></tr>
            @endforelse
        </table>

    @elseif ($tab === 'credentials')
        <p class="text-muted">
            {{ __('Most specific wins: device, then device group, then the fleet-wide default. A stored secret is never shown again - these fields write, they do not read.') }}
        </p>

        <table class="table table-condensed table-striped">
            <tr><th>Applies to</th><th>Method</th><th>Stored as</th><th>Key</th><th></th></tr>
            @forelse ($credentials as $credential)
                <tr>
                    <td>{!! $credential->scope_type->value === 'device'
                        ? $deviceLabel($credential->scope_ref)
                        : e($credential->scope_type->label($credential->scope_ref)) !!}</td>
                    <td>{{ $credential->method }}</td>
                    <td>{{ $credential->username }}</td>
                    <td>{{ $credential->key_id }}</td>
                    <td>
                        <form method="POST" action="{{ url('plugin/webterm/admin/credentials/delete') }}">
                            @csrf
                            <input type="hidden" name="scope_type" value="{{ $credential->scope_type->value }}">
                            <input type="hidden" name="scope_ref" value="{{ $credential->scope_ref }}">
                            <button class="btn btn-xs btn-danger" type="submit">{{ __('Remove') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">{{ __('None stored.') }}</td></tr>
            @endforelse
        </table>

        <h4>{{ __('Store a credential') }}</h4>
        <form method="POST" action="{{ url('plugin/webterm/admin/credentials') }}" autocomplete="off">
            @csrf
            <div class="form-inline" style="margin-bottom: 8px;">
                <select name="scope_type" id="webterm-cred-scope" class="form-control input-sm">
                    <option value="device">device</option>
                    <option value="group">device group</option>
                    <option value="global">fleet-wide default</option>
                </select>
                <select name="scope_ref" id="webterm-cred-ref" class="form-control input-sm" style="min-width: 260px;"></select>
                <input name="username" class="form-control input-sm" placeholder="{{ __('SSH login') }}" required autocomplete="off">
                <select name="secret_kind" id="webterm-cred-kind" class="form-control input-sm">
                    <option value="password">password</option>
                    <option value="private_key">private key</option>
                </select>
            </div>
            {{-- Write-only. Never populated from a stored value, and the failure
                 path does not flash it back. --}}
            <div style="margin-bottom: 8px;">
                <input type="password" name="secret" id="webterm-cred-secret" class="form-control"
                       placeholder="{{ __('Password') }}" required autocomplete="new-password" style="max-width: 420px;">
                <textarea name="secret" id="webterm-cred-secret-key" class="form-control" rows="6"
                          placeholder="{{ __('-----BEGIN OPENSSH PRIVATE KEY-----') }}"
                          style="display: none; max-width: 620px; font-family: monospace;"></textarea>
            </div>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Store credential') }}</button>
            <span class="text-muted"><small>{{ __('Encrypted at rest. It is never rendered back to this page.') }}</small></span>
        </form>

        <script>
            (function () {
                var scope = document.getElementById('webterm-cred-scope');
                var ref = document.getElementById('webterm-cred-ref');
                var kind = document.getElementById('webterm-cred-kind');
                var pw = document.getElementById('webterm-cred-secret');
                var key = document.getElementById('webterm-cred-secret-key');

                function bindRef() {
                    if (typeof init_select2 !== 'function' || !window.jQuery) { return; }
                    if (jQuery(ref).data('select2')) { jQuery(ref).select2('destroy').empty(); }

                    if (scope.value === 'global') {
                        ref.innerHTML = '<option value="0" selected>fleet-wide</option>';
                        ref.disabled = true;
                        return;
                    }

                    ref.disabled = false;
                    init_select2('#webterm-cred-ref', scope.value === 'group' ? 'device-group' : 'device', {});
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
                }

                scope.addEventListener('change', bindRef);
                kind.addEventListener('change', bindKind);
                bindRef();
                bindKind();
            })();
        </script>

    @elseif ($tab === 'access')
        <h4>{{ __('Grants') }}</h4>
        <p class="text-muted">{{ __('Shell access is always narrower than being able to see a device. A deny always beats an allow.') }}</p>
        <table class="table table-condensed table-striped">
            <tr><th>Subject</th><th>Object</th><th>Effect</th><th></th></tr>
            @forelse ($grants as $grant)
                <tr>
                    <td>{{ $grant->subject_type }} {{ $grant->subject_ref }}</td>
                    <td>{{ $grant->object_type }}
                        {!! $grant->object_type === 'device' ? $deviceLabel($grant->object_id) : e((string) $grant->object_id) !!}</td>
                    <td>{{ $grant->effect }}</td>
                    <td>
                        <form method="POST" action="{{ url('plugin/webterm/admin/grants/delete') }}">
                            @csrf
                            <input type="hidden" name="id" value="{{ $grant->id }}">
                            <button class="btn btn-xs btn-danger" type="submit">{{ __('Remove') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">{{ __('No grants. Nobody can open a terminal.') }}</td></tr>
            @endforelse
        </table>

        <form method="POST" action="{{ url('plugin/webterm/admin/grants') }}" class="form-inline">
            @csrf
            {{-- These labels are NOT translated. They are the stored values an
                 operator matches against webterm:grant output and the database,
                 so translating them would misdescribe what is written. It also
                 avoids a real trap: __('device') collides with LibreNMS's
                 lang/en/device.php and returns that whole file as an array,
                 which is fatal inside {{ }}. --}}
            <select name="subject_type" class="form-control input-sm">
                <option value="user">user</option>
                <option value="role">role</option>
            </select>
            <input name="subject_ref" class="form-control input-sm" placeholder="{{ __('user id or role name') }}" required>
            <select name="object_type" id="webterm-object-type" class="form-control input-sm">
                <option value="device">device</option>
                <option value="group">group</option>
            </select>
            {{-- Driven by core's own /ajax/select endpoints via init_select2,
                 which layouts.librenmsv1 already loads along with jQuery and
                 select2. No asset of ours, and the endpoint filters by the
                 requesting user's device visibility, so the picker cannot list
                 a device the operator could not already see. --}}
            <select name="object_id" id="webterm-object-id" class="form-control input-sm" required
                    style="min-width: 260px;"></select>
            <select name="effect" class="form-control input-sm">
                <option value="allow">allow</option>
                <option value="deny">deny</option>
            </select>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Add grant') }}</button>
        </form>
        <noscript>
            <p class="text-muted"><small>
                {{ __('The device picker needs JavaScript. Without it, use the command line:') }}
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
                    init_select2(target, type.value === 'group' ? 'device-group' : 'device', {});
                }

                type.addEventListener('change', bind);
                bind();
            })();
        </script>

        <h4 style="margin-top: 18px;">{{ __('Abilities') }}</h4>
        <table class="table table-condensed table-striped">
            <tr><th>User</th><th>Ability</th><th></th></tr>
            @forelse ($abilities as $ability)
                <tr>
                    <td>{{ $ability->user_id }}</td>
                    <td>{{ $ability->ability }}</td>
                    <td>
                        <form method="POST" action="{{ url('plugin/webterm/admin/abilities/delete') }}">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $ability->user_id }}">
                            <input type="hidden" name="ability" value="{{ $ability->ability }}">
                            <button class="btn btn-xs btn-danger" type="submit">{{ __('Revoke') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-muted">{{ __('Nobody holds a WebTerm ability.') }}</td></tr>
            @endforelse
        </table>

        <form method="POST" action="{{ url('plugin/webterm/admin/abilities') }}" class="form-inline">
            @csrf
            <input name="user_id" type="number" min="1" class="form-control input-sm" placeholder="{{ __('user id') }}" required>
            <select name="ability" class="form-control input-sm">
                <option value="use">use</option>
                <option value="admin">admin</option>
                <option value="audit.view">audit.view</option>
            </select>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Grant ability') }}</button>
        </form>

    @elseif ($tab === 'hostkeys')
        <p class="text-muted">
            {{ __('Read-only here on purpose: resetting a pin and switching a target to trust-on-first-connect are individually reasonable and together amount to turning off host key verification from a browser. Use webterm:hostkey-scan and webterm:hostkey-reset.') }}
        </p>
        <table class="table table-condensed table-striped">
            <tr><th>Device</th><th>Algorithm</th><th>Fingerprint</th><th>Status</th></tr>
            @forelse ($hostKeys as $key)
                <tr>
                    <td>{!! $deviceLabel($key->device_id) !!}</td>
                    <td>{{ $key->algorithm }}</td>
                    <td><code>{{ $key->fingerprint }}</code></td>
                    <td>{{ $key->status }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">{{ __('No host keys pinned.') }}</td></tr>
            @endforelse
        </table>

    @elseif ($tab === 'sessions')
        <table class="table table-condensed table-striped">
            <tr><th>Session</th><th>User</th><th>Device</th><th>State</th><th>Started</th><th></th></tr>
            @forelse ($sessions as $session)
                <tr>
                    <td><code>{{ $session->session_id }}</code></td>
                    <td>{{ $session->user_id }}</td>
                    <td>{!! $deviceLabel($session->device_id) !!}</td>
                    <td>{{ $session->state }}</td>
                    <td>{{ $session->started_at }}</td>
                    <td>
                        @if ($session->state !== 'closed')
                            <form method="POST" action="{{ url('plugin/webterm/admin/sessions/kill') }}">
                                @csrf
                                <input type="hidden" name="session_id" value="{{ $session->session_id }}">
                                <button class="btn btn-xs btn-danger" type="submit">{{ __('Terminate') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">{{ __('No sessions recorded.') }}</td></tr>
            @endforelse
        </table>

    @elseif ($tab === 'settings')
        <p class="text-muted">
            {{ __('Changes take effect on the next request. Anything not editable here is listed below with the reason - a control that appears to work and does nothing is worse than no control.') }}
        </p>

        <table class="table table-condensed table-striped">
            <tr><th style="width: 30%;">Setting</th><th style="width: 22%;">Value</th><th></th></tr>
            @foreach ($editable as $key => [$type, $label, $help])
                <tr>
                    <td><strong>{{ $label }}</strong><br><small class="text-muted"><code>{{ $key }}</code></small></td>
                    <td>
                        <form method="POST" action="{{ url('plugin/webterm/admin/settings') }}" class="form-inline">
                            @csrf
                            <input type="hidden" name="key" value="{{ $key }}">
                            @if ($type === 'bool')
                                <select name="value" class="form-control input-sm">
                                    <option value="true" @selected(config('webterm.'.$key))>true</option>
                                    <option value="false" @selected(! config('webterm.'.$key))>false</option>
                                </select>
                            @elseif (str_starts_with($type, 'enum:'))
                                <select name="value" class="form-control input-sm">
                                    @foreach (explode(',', substr($type, 5)) as $option)
                                        <option value="{{ $option }}" @selected(config('webterm.'.$key) === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input name="value" class="form-control input-sm" style="width: 120px;"
                                       value="{{ config('webterm.'.$key) }}">
                            @endif
                            <button class="btn btn-xs btn-primary" type="submit">{{ __('Save') }}</button>
                        </form>
                    </td>
                    <td class="text-muted"><small>{{ $help }}</small></td>
                </tr>
            @endforeach
        </table>

        <h4>{{ __('Not editable here') }}</h4>
        <table class="table table-condensed">
            @foreach ($readOnlySettings as $key => $why)
                <tr>
                    <td style="width: 30%;"><code>{{ $key }}</code></td>
                    <td class="text-muted"><small>{{ $why }}</small></td>
                </tr>
            @endforeach
        </table>

    @else
        <p class="text-muted">{{ __('The 100 most recent events. The off-box copy, if you configured one, is the record that survives a compromise of this host.') }}</p>
        <table class="table table-condensed table-striped">
            <tr><th>When</th><th>Event</th><th>User</th><th>Device</th><th>Reason</th></tr>
            @forelse ($audit as $entry)
                <tr>
                    <td>{{ $entry->occurred_at }}</td>
                    <td>{{ $entry->event }}</td>
                    <td>{{ $entry->username }}</td>
                    <td>{!! $entry->device_id ? $deviceLabel($entry->device_id) : '-' !!}</td>
                    <td>{{ $entry->reason_code }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">{{ __('Nothing recorded yet.') }}</td></tr>
            @endforelse
        </table>
    @endif
</div>
@endsection
