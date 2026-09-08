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
        {{-- Read from $data, not from top-level variables. DeviceController
             nests a tab's data() return under a 'data' key rather than
             spreading it:

                 $data_array = ['title' =>, 'device' =>, 'data' => $data, ...];
                 return view('device.tabs.'.$current_tab, $data_array);

             so $webtermState is never defined. Core's own tabs read $data[...]
             the same way -- see device/tabs/config.blade.php. --}}
        @if (($data['webtermState'] ?? 'denied') !== 'ready')
            <p>{{ $data['webtermReason'] ?? __('The terminal is unavailable for this device.') }}</p>
            @if (! empty($data['webtermFix']))
                <pre style="margin-bottom: 0;">{{ $data['webtermFix'] }}</pre>
            @endif
        @else
            {{-- The terminal itself, in the page. Navigating to this tab is
                 the deliberate act that opens a session -- the same weight as
                 clicking a button -- so it connects on arrival rather than
                 making the operator click twice. The device overview panel is
                 still a link, so merely browsing devices mints nothing. --}}
            @include('WebTerm::partials.terminal', ['webtermDeviceId' => $data['webtermDeviceId']])
        @endif
    </div>
</div>
