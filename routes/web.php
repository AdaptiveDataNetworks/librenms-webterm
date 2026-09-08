<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Http\Controllers\AdminController;
use AdaptiveDataNetworks\WebTerm\Http\Controllers\SessionController;
use AdaptiveDataNetworks\WebTerm\Http\Middleware\EnsureWebTermAdmin;
use AdaptiveDataNetworks\WebTerm\Http\Middleware\EnsureWebTermEnabled;
use Illuminate\Support\Facades\Route;

/*
| Registered while the plugin is enabled -- but NOT unregistered when it is
| disabled. `lnms plugin:enable` runs route:cache; `lnms plugin:disable` updates
| a column and nothing else, so a cached route table keeps serving these paths
| after a disable, including the automatic disable LibreNMS performs when a hook
| throws. Every route here therefore re-checks state per request rather than
| relying on having been absent from registration.
|
| 'web' brings session and CSRF; 'auth' is LibreNMS's own guard. The throttle
| bounds how fast an attacker with a valid session can probe device ids or
| grind step-up codes.
*/

Route::middleware(['web', 'auth', EnsureWebTermEnabled::class])
    ->prefix('plugin/webterm')
    ->name('webterm.')
    ->group(function (): void {
        Route::post('session', [SessionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('session.store');

        Route::post('stepup', [SessionController::class, 'stepUp'])
            ->middleware('throttle:10,1')
            ->name('stepup');
    });

/*
| The admin console. Gated on WebTerm's own admin ability, never on a core Gate
| ability: LibreNMS registers a Gate::before that returns true for every ability
| when the user holds the admin role, which would hand the console to every
| LibreNMS admin. EnsureWebTermAdmin also re-checks that the plugin is still
| enabled, and returns 404 rather than 403 so an unauthorised account cannot
| tell the console apart from a path that does not exist.
|
| No closures: a closure in a route file breaks route:cache for the whole
| LibreNMS application, not just for this plugin.
*/
Route::middleware(['web', 'auth', EnsureWebTermEnabled::class, EnsureWebTermAdmin::class])
    ->prefix('plugin/webterm/admin')
    ->name('webterm.admin.')
    ->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');

        Route::post('targets', [AdminController::class, 'toggleTarget'])->name('targets.toggle');
        Route::post('grants', [AdminController::class, 'storeGrant'])->name('grants.store');
        Route::post('grants/delete', [AdminController::class, 'destroyGrant'])->name('grants.destroy');
        Route::post('abilities', [AdminController::class, 'storeAbility'])->name('abilities.store');
        Route::post('abilities/delete', [AdminController::class, 'destroyAbility'])->name('abilities.destroy');
        Route::post('sessions/kill', [AdminController::class, 'killSession'])->name('sessions.kill');

        // Writes that carry or destroy a secret are throttled harder than the
        // read-only console: an admin session should not be usable to grind
        // through credential writes unnoticed.
        Route::post('targets/save', [AdminController::class, 'storeTarget'])
            ->middleware('throttle:30,1')->name('targets.save');
        Route::post('credentials', [AdminController::class, 'storeCredential'])
            ->middleware('throttle:10,1')->name('credentials.store');
        Route::post('credentials/delete', [AdminController::class, 'destroyCredential'])
            ->middleware('throttle:10,1')->name('credentials.destroy');
        Route::post('settings', [AdminController::class, 'storeSetting'])
            ->middleware('throttle:30,1')->name('settings.store');
    });
