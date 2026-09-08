{{-- The WebTerm admin console.

     Server-rendered with no build step and no new dependency: the plugin's
     runtime requirements are php and librenms/plugin-interfaces, and a CI guard
     fails the build if that changes.

     Every value below comes from either the monitoring database or an operator,
     so all of it is attacker-influenced and all of it goes through {{ }}.
     Nothing here renders a credential payload, a shared secret, a ticket or a
     host key blob. --}}
@extendsFirst(['layouts.librenmsv1', 'WebTerm::layouts.standalone'])

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
                    <td>{{ $target->device_id }}</td>
                    <td>{{ $target->principal }}</td>
                    <td>{{ $target->flow }}</td>
                    <td>{{ $target->host_key_policy }}</td>
                    <td>{{ $target->enabled ? __('yes') : __('no') }}</td>
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

        <h4>{{ __('Stored credentials') }}</h4>
        <p class="text-muted">
            {{ __('Shown, never set, from here: obtaining a device credential should require shell access to this host, not an admin session in a browser. Most specific wins - device, then group, then global.') }}
        </p>
        <table class="table table-condensed table-striped">
            <tr><th>Applies to</th><th>Method</th><th>Stored as</th></tr>
            @forelse ($credentials as $credential)
                <tr>
                    <td>{{ $credential->scope_type->label($credential->scope_ref) }}</td>
                    <td>{{ $credential->method }}</td>
                    <td>{{ $credential->username }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-muted">{{ __('None stored.') }}</td></tr>
            @endforelse
        </table>

    @elseif ($tab === 'access')
        <h4>{{ __('Grants') }}</h4>
        <p class="text-muted">{{ __('Shell access is always narrower than being able to see a device. A deny always beats an allow.') }}</p>
        <table class="table table-condensed table-striped">
            <tr><th>Subject</th><th>Object</th><th>Effect</th><th></th></tr>
            @forelse ($grants as $grant)
                <tr>
                    <td>{{ $grant->subject_type }} {{ $grant->subject_ref }}</td>
                    <td>{{ $grant->object_type }} {{ $grant->object_id }}</td>
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
            <select name="subject_type" class="form-control input-sm">
                <option value="user">{{ __('user') }}</option>
                <option value="role">{{ __('role') }}</option>
            </select>
            <input name="subject_ref" class="form-control input-sm" placeholder="{{ __('user id or role') }}" required>
            <select name="object_type" class="form-control input-sm">
                <option value="device">{{ __('device') }}</option>
                <option value="group">{{ __('group') }}</option>
            </select>
            <input name="object_id" type="number" min="1" class="form-control input-sm" placeholder="{{ __('id') }}" required>
            <select name="effect" class="form-control input-sm">
                <option value="allow">{{ __('allow') }}</option>
                <option value="deny">{{ __('deny') }}</option>
            </select>
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Add grant') }}</button>
        </form>

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
                    <td>{{ $key->device_id }}</td>
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
                    <td>{{ $session->device_id }}</td>
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

    @else
        <p class="text-muted">{{ __('The 100 most recent events. The off-box copy, if you configured one, is the record that survives a compromise of this host.') }}</p>
        <table class="table table-condensed table-striped">
            <tr><th>When</th><th>Event</th><th>User</th><th>Device</th><th>Reason</th></tr>
            @forelse ($audit as $entry)
                <tr>
                    <td>{{ $entry->occurred_at }}</td>
                    <td>{{ $entry->event }}</td>
                    <td>{{ $entry->username }}</td>
                    <td>{{ $entry->device_id }}</td>
                    <td>{{ $entry->reason_code }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">{{ __('Nothing recorded yet.') }}</td></tr>
            @endforelse
        </table>
    @endif
</div>
@endsection
