<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Http\Middleware;

use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the admin console.
 *
 * Three things this does NOT do, each for a reason.
 *
 * It does not use a core Gate ability. LibreNMS registers a Gate::before that
 * returns true for every ability when the user has the admin role, so a
 * `can:` check would grant the console to every LibreNMS admin regardless of
 * whether they hold WebTerm's own admin ability. Authorization here is always
 * our own table.
 *
 * It does not rely on the routes being absent when the plugin is disabled.
 * `lnms plugin:enable` runs `route:cache`; `lnms plugin:disable` updates a
 * column and nothing else. So after a disable -- including the automatic
 * disable LibreNMS performs when a hook throws -- the cached route table still
 * serves these paths. An operator disabling the plugin to contain an incident
 * must not be left with a live grant-writing surface, so the check is made per
 * request rather than assumed from registration.
 *
 * It does not return 403. A 403 confirms the console exists and that this
 * account is merely not admin enough, which is a fact worth withholding from an
 * authenticated but unprivileged user. 404 is what a nonexistent path returns,
 * and that is what an unauthorised one should look like.
 */
final class EnsureWebTermAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            abort(404);
        }

        if (! $this->pluginEnabled()) {
            abort(404);
        }

        $holdsAdmin = Ability::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('ability', Ability::ADMIN)
            ->exists();

        if (! $holdsAdmin) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * Whether LibreNMS still considers the plugin enabled.
     *
     * Absent outside LibreNMS core (Testbench, or a LibreNMS predating the v2
     * plugin system), where there is nothing to consult and nothing to gate.
     */
    private function pluginEnabled(): bool
    {
        if (! app()->bound(PluginManagerInterface::class)) {
            return true;
        }

        return app(PluginManagerInterface::class)->pluginEnabled(WebTermServiceProvider::PLUGIN_NAME);
    }
}
