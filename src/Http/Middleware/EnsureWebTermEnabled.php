<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every WebTerm route while the kill switch is off.
 *
 * ShellAuthorizer checks this too. The duplication is deliberate: a route that
 * is reachable when the feature is disabled is a route that has to be
 * individually correct, and this makes the whole surface disappear at once.
 */
final class EnsureWebTermEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('webterm.enabled', false)) {
            abort(403, 'WebTerm is switched off.');
        }

        return $next($request);
    }
}
