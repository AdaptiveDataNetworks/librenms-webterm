{{-- Panel on the device overview tab. Renders without any network call.

     One card in the left column of core's two-column overview grid, beside
     core's own Device / Ports / Processors panels, so it uses core's panel
     vocabulary and adds no colour of its own. The `state` of `hidden` never
     reaches here: DeviceOverview::handle returns an empty string for it rather
     than putting a permanent box on every device in the estate.

     The id and data attribute are a deliberate hook for an operator's own
     stylesheet or userscript. Nothing in this package reads them. --}}
<div class="panel panel-default" id="webterm-panel" data-device-id="{{ $panel['device_id'] }}">
    <div class="panel-heading">
        <h3 class="panel-title">
            <i class="fa fa-terminal fa-fw fa-lg icon-theme" aria-hidden="true"></i>
            {{ __('Terminal') }}
        </h3>
    </div>
    <div class="panel-body">
        @if ($panel['state'] === 'ready')
            <a href="{{ url('plugin/WebTerm?device='.$panel['device_id']) }}"
               class="btn btn-primary"
               target="_blank" rel="noopener">
                <i class="fa fa-terminal fa-fw" aria-hidden="true"></i>
                {{ __('Open terminal') }}<span class="sr-only"> ({{ __('opens in a new tab') }})</span>
            </a>
            @if (($panel['reason'] ?? null) === 'step_up_required')
                {{-- Not a refusal: the presenter keeps this distinct on purpose,
                     because sending the user to an administrator who has nothing
                     to fix is worse than telling them what to expect. --}}
                <p style="margin: 8px 0 0;">
                    <small class="text-muted">{{ __('You will be asked to confirm your identity before the terminal opens.') }}</small>
                </p>
            @endif
            <p style="margin: 8px 0 0;">
                <small class="text-muted">{{ __('WebTerm records that you opened a terminal here, and when. Keystrokes and terminal output are not recorded.') }}</small>
            </p>
        @elseif ($panel['state'] === 'denied')
            <p style="margin-bottom: 0;">
                <i class="fa fa-lock fa-fw" aria-hidden="true"></i>
                {{ $panel['message'] }}
            </p>
            <p style="margin: 8px 0 0;">
                <small class="text-muted">{{ __('Terminal access is granted separately from your LibreNMS permissions. Ask a WebTerm administrator.') }}</small>
            </p>
        @elseif ($panel['state'] === 'disabled')
            <p style="margin-bottom: 0;">
                <i class="fa fa-power-off fa-fw" aria-hidden="true"></i>
                {{ $panel['message'] }}
            </p>
        @else
            {{-- The presenter's Guard fallback: WebTerm threw rather than
                 decided. Say which of the two it was, because "unavailable" on
                 its own sends the reader looking for a permission they have. --}}
            <p style="margin-bottom: 0;">
                <i class="fa fa-exclamation-triangle fa-fw" aria-hidden="true"></i>
                {{ $panel['message'] }}
            </p>
            <p style="margin: 8px 0 0;">
                <small class="text-muted">{{ __('This is a fault, not a decision about your access.') }}</small>
            </p>
        @endif
    </div>
</div>
