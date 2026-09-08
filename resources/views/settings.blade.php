{{-- Read-only status. LibreNMS stores the plugin settings bag as plaintext
     JSON and echoes values into value="" attributes, so no secret is ever
     rendered or stored here. Configuration lives in config/webterm.php and the
     webterm:config command. --}}
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">{{ __('WebTerm status') }}</h3>
    </div>
    <div class="panel-body">
        <p>
            {{ __('This page cannot configure WebTerm: LibreNMS stores plugin settings as plaintext JSON, so nothing sensitive may be kept here.') }}
        </p>
        @if (! empty($webtermConsole))
            <p>
                <a class="btn btn-primary btn-sm" href="{{ url('plugin/webterm/admin') }}">{{ __('Open the WebTerm console') }}</a>
                <span class="text-muted"><small>{{ __('targets, access, host keys, sessions and audit') }}</small></span>
            </p>
        @else
            <p class="text-muted">
                {{ __('The WebTerm console needs WebTerm\'s own admin ability, which is separate from being a LibreNMS administrator. Grant it from the command line:') }}
            </p>
            <pre style="margin-bottom: 12px;">./lnms webterm:ability grant --user=&lt;you&gt; --ability=admin</pre>
        @endif
        <p class="text-muted">
            {{ __('Credentials are deliberately not settable from a browser: obtaining one should require shell access to this host.') }}
        </p>
        <pre style="margin-bottom: 12px;">./lnms webterm:doctor</pre>
        <p class="text-muted" style="margin-bottom: 0;">
            <small>
                {{ __('No credential or shared secret is ever stored in plugin settings: LibreNMS keeps this bag as plaintext JSON.') }}
                <a href="https://adaptivedatanetworks.github.io/librenms-webterm/" target="_blank" rel="noopener">{{ __('Documentation') }}</a>
            </small>
        </p>
    </div>
</div>
