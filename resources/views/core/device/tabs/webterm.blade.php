{{-- Resolved as 'device.tabs.webterm' through a View::addLocation() path.

     This directory contains exactly ONE file, deliberately. addLocation appends
     our path to the DEFAULT view namespace, which means anything in it becomes a
     global fallback for any view name core fails to resolve -- including names
     core builds from request parameters. It cannot SHADOW a core view (core's
     paths are searched first), but it can answer for one that does not exist,
     so nothing else may live here. --}}
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-terminal" aria-hidden="true"></i> {{ __('Terminal') }}</h3>
    </div>
    <div class="panel-body">
        @if (($webtermState ?? 'denied') !== 'ready')
            <p>{{ $webtermReason ?? __('The terminal is unavailable for this device.') }}</p>
            @if (! empty($webtermFix))
                <pre style="margin-bottom: 0;">{{ $webtermFix }}</pre>
            @endif
        @else
            {{-- Nothing is minted until this is clicked: opening a device page
                 must stay free of consequence. --}}
            <p class="text-muted">{{ __('Opens an SSH session to this device from the LibreNMS server.') }}</p>
            <a class="btn btn-primary" href="{{ url('plugin/WebTerm?device='.$webtermDeviceId) }}">
                <i class="fa fa-terminal" aria-hidden="true"></i> {{ __('Open terminal') }}
            </a>
        @endif
    </div>
</div>
