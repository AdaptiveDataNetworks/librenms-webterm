{{-- The body of the device Terminal tab.

     Separate from the view that wraps it so the wrapper can put it inside
     core's device chrome when that exists and render it bare when it does not
     -- which is what makes this testable without LibreNMS. --}}
<div class="panel panel-default" style="margin-top: 14px;">
    <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-terminal" aria-hidden="true"></i> {{ __('Terminal') }}</h3>
    </div>
    <div class="panel-body">
        @if (($data['webtermState'] ?? 'denied') !== 'ready')
            <p>{{ $data['webtermReason'] ?? __('The terminal is unavailable for this device.') }}</p>
            @if (! empty($data['webtermFix']))
                <pre style="margin-bottom: 0;">{{ $data['webtermFix'] }}</pre>
            @endif
        @else
            @include('WebTerm::partials.terminal', ['webtermDeviceId' => $data['webtermDeviceId']])
        @endif
    </div>
</div>
