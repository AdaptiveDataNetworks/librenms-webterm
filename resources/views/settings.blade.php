{{-- Rendered inside LibreNMS's plugins/settings.blade.php wrapper.
     Read-only status only: this bag is stored as plaintext JSON by core. --}}
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">{{ __('WebTerm status') }}</h3>
    </div>
    <div class="panel-body">
        <p class="text-muted">
            {{ __('Configuration lives in config/webterm.php and the environment, not here. No secret is ever stored in plugin settings.') }}
        </p>
    </div>
</div>
