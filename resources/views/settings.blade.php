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
            {{ __('WebTerm is configured from the command line, not from this page.') }}
        </p>
        <pre style="margin-bottom: 12px;">./lnms webterm:doctor</pre>
        <p class="text-muted" style="margin-bottom: 0;">
            <small>
                {{ __('No credential or shared secret is ever stored in plugin settings: LibreNMS keeps this bag as plaintext JSON.') }}
                <a href="https://adn.github.io/librenms-webterm/" target="_blank" rel="noopener">{{ __('Documentation') }}</a>
            </small>
        </p>
    </div>
</div>
