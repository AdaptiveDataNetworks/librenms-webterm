<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Http\Controllers\SessionController;
use AdaptiveDataNetworks\WebTerm\Http\Middleware\EnsureWebTermEnabled;
use Illuminate\Support\Facades\Route;

/*
| Loaded only when the plugin is enabled in LibreNMS, so a disabled plugin
| presents no attack surface at all.
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
