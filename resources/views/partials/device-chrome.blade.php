{{-- The tab body inside LibreNMS's device chrome.

     Kept in its own view because <x-device.page> is resolved by Blade's
     COMPILER, not at runtime -- so an @if around the tag does not help; the
     compiler fails on it whether or not the branch is taken. A view that is
     never included is never compiled, which is what makes this safe to ship in
     a package that must also render without LibreNMS present. --}}
<x-device.page :device="$device">
    @include('WebTerm::partials.device-terminal-tab')
</x-device.page>
