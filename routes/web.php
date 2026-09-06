<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| All routes sit behind LibreNMS's own 'web' and 'auth' middleware. The
| session-mint route additionally carries CSRF, the plugin kill switch, the
| step-up gate and a rate limit.
|
| These are loaded via loadRoutesFrom() only when the plugin is enabled, so a
| disabled plugin has no attack surface at all.
*/

Route::middleware(['web', 'auth'])
    ->prefix('plugin/webterm')
    ->name('webterm.')
    ->group(function (): void {
        // Placeholder. Real endpoints land in Phase 7 (session mint) and
        // Phase 10 (admin console).
    });
