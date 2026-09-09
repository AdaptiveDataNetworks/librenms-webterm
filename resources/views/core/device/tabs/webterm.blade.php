{{-- Resolved as 'device.tabs.webterm' through a View::addLocation() path.

     This directory contains exactly ONE file, deliberately. addLocation appends
     our path to the DEFAULT view namespace, which means anything in it becomes a
     global fallback for any view name core fails to resolve -- including names
     core builds from request parameters. It cannot SHADOW a core view (core's
     paths are searched first), but it can answer for one that does not exist,
     so nothing else may live here.

     The shape below is core's own, and both halves are load-bearing:

       @extends('layouts.librenmsv1') supplies the page, including the
       <meta name="csrf-token"> the terminal needs to mint a session. Without
       it the tab rendered a bare panel and every connection failed with a CSRF
       token mismatch.

       <x-device.page> draws the device header and the tab bar, which is the
       entire point of being a tab rather than a page: you can see which device
       you are connected to, and get back.

     Both are guarded so the standalone test suite -- where neither core's
     layout nor its components exist -- still renders this view. --}}
@extendsFirst(['layouts.librenmsv1', 'WebTerm::layouts.standalone'])

@section('content')
    @if (class_exists(\App\View\Components\Device\Page::class) && ! empty($device))
        {{-- Included, not inlined: <x-device.page> is resolved by Blade's
             compiler, so an @if around the tag itself would still fail to
             compile without LibreNMS. A view that is never included is never
             compiled. --}}
        @include('WebTerm::partials.device-chrome')
    @else
        @include('WebTerm::partials.device-terminal-tab')
    @endif
@endsection
