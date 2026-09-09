{{-- Read-only status. LibreNMS stores the plugin settings bag as plaintext
     JSON and echoes values into value="" attributes, so no secret is ever
     rendered or stored here. Configuration lives in config/webterm.php and the
     webterm:config command.

     Core @includes this into a bare @yield('content') with no container of its
     own, so the container below is ours to supply -- without it the panel
     renders edge to edge and its border is clipped by the viewport. The width
     mirrors the Plugin Admin page this is reached from, one step wider because
     this panel holds prose and command blocks rather than a two-column table.

     This is the one WebTerm view with no @extendsFirst, deliberately: it is
     included, not rendered as a page. --}}
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2 col-sm-10 col-sm-offset-1 col-xs-12">
            <div class="panel panel-default" style="margin-top: 15px;">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-terminal fa-fw" aria-hidden="true"></i>
                        {{ __('WebTerm is configured elsewhere') }}
                    </h3>
                </div>
                <div class="panel-body">
                    <p>
                        {{ __('This page cannot configure WebTerm: LibreNMS stores plugin settings as plaintext JSON, so nothing sensitive may be kept here.') }}
                    </p>

                    @if (! empty($webtermConsole))
                        <p>
                            <a class="btn btn-primary" href="{{ url('plugin/webterm/admin') }}">{{ __('Open the WebTerm console') }}</a>
                        </p>
                        <p>
                            <small class="text-muted">{{ __('Targets, credentials, access, host keys, sessions, runtime settings and audit.') }}</small>
                        </p>
                    @else
                        <p>
                            {{ __('The WebTerm console needs WebTerm\'s own') }} <strong>admin</strong>
                            {{ __('ability, which is separate from being a LibreNMS administrator. It covers targets, credentials, access, host keys, sessions, runtime settings and audit.') }}
                        </p>
                        <p>{{ __('Grant it on the LibreNMS server, as the librenms user:') }}</p>
                        <pre># LibreNMS server, as the librenms user
./lnms webterm:ability grant --user=&lt;you&gt; --ability=admin</pre>
                    @endif

                    <p>
                        {{ __('Credentials are deliberately not settable from this page: obtaining one should require either shell access to this host or WebTerm\'s own admin ability.') }}
                    </p>

                    <p>
                        {{ __('webterm:doctor reports what is configured and what is still missing. On a new install it lists several failures, because nothing is enabled yet.') }}
                    </p>
                    <pre># LibreNMS server, as the librenms user
./lnms webterm:doctor</pre>

                    <p style="margin-bottom: 0;">
                        <a href="https://adaptivedatanetworks.github.io/librenms-webterm/" target="_blank" rel="noopener">{{ __('Documentation') }}</a>
                        <span class="text-muted">({{ __('opens in a new tab') }})</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
