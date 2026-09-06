{{-- Panel on the device overview tab. Renders without any network call. --}}
<div class="panel panel-default" id="webterm-panel" data-device-id="{{ $panel['device_id'] }}">
    <div class="panel-heading">
        <h3 class="panel-title">
            <i class="fa fa-terminal fa-fw" aria-hidden="true"></i>
            {{ __('Terminal') }}
        </h3>
    </div>
    <div class="panel-body">
        @if ($panel['state'] === 'ready')
            <a href="{{ url('plugin/WebTerm?device='.$panel['device_id']) }}"
               class="btn btn-primary btn-sm"
               target="_blank" rel="noopener">
                <i class="fa fa-terminal fa-fw" aria-hidden="true"></i>
                {{ __('Open terminal') }}
            </a>
            <p class="text-muted" style="margin: 8px 0 0;">
                <small>{{ __('Your session is recorded in the audit log.') }}</small>
            </p>
        @elseif ($panel['state'] === 'denied')
            <p class="text-muted" style="margin-bottom: 0;">
                <i class="fa fa-lock fa-fw" aria-hidden="true"></i>
                {{ $panel['message'] }}
            </p>
        @else
            <p class="text-muted" style="margin-bottom: 0;">{{ $panel['message'] }}</p>
        @endif
    </div>
</div>
