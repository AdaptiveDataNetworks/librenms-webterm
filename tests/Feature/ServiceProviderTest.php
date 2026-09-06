<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;

/*
| Testbench boots a bare Laravel app with NO LibreNMS core, so
| PluginManagerInterface is unbound. Booting must therefore be a no-op rather
| than a fatal -- that guard is also what keeps the plugin from taking down a
| LibreNMS install that predates the v2 plugin system.
*/

it('boots without LibreNMS core present', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(WebTermServiceProvider::class);
});

it('merges its config with closed defaults', function () {
    expect(config('webterm.enabled'))->toBeFalse()
        ->and(config('webterm.credentials.driver'))->toBe('database')
        ->and(config('webterm.security.host_key_policy'))->toBe('pin');
});

it('ships no allowed origins by default', function () {
    // Deny-by-default: with no origin allow-listed, no browser may open a socket.
    expect(config('webterm.security.allowed_origins'))->toBe([]);
});

it('requires step-up authentication by default', function () {
    expect(config('webterm.security.step_up'))->toBeTrue();
});

it('does not register routes when the plugin manager is absent', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter(fn ($n) => is_string($n) && str_starts_with($n, 'webterm.'));

    expect($routes)->toBeEmpty();
});
