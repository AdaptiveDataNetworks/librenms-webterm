{{-- Rendered inside a <li> that core supplies, so this must not add one.

     The <a> must also stay the root element: both themes style this entry
     through a `> li > a` direct-child selector, so wrapping it in anything at
     all would drop its colour, padding and focus ring in light and dark at
     once, and the failure would look like a theme bug rather than a markup one.

     "WebTerm console" rather than the bare product name: this is the only
     discoverable route to it, for a visitor who does not open it often. --}}
<a href="{{ url('plugin/webterm/admin') }}"
   title="{{ __('Targets, credentials, access, host keys, sessions, runtime settings and audit') }}">
    <i class="fa fa-terminal fa-fw fa-lg" aria-hidden="true"></i> {{ __('WebTerm console') }}
</a>
