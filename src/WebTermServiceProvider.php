<?php

declare(strict_types=1);

namespace Adn\WebTerm;

use Adn\WebTerm\Console\RekeyCredentialsCommand;
use Adn\WebTerm\Credentials\CredentialManager;
use Adn\WebTerm\Hooks\DeviceOverview;
use Adn\WebTerm\Hooks\Settings;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Throwable;

/**
 * Service provider for the LibreNMS WebTerm plugin.
 *
 * Registered automatically through Laravel package auto-discovery
 * (see composer.json -> extra.laravel.providers). LibreNMS is itself a Laravel
 * application, so no user action is required beyond `lnms plugin:add`.
 */
final class WebTermServiceProvider extends ServiceProvider
{
    /**
     * The plugin name as LibreNMS knows it. This is the key used for the
     * `plugins` table row, the view namespace, and the /plugin/{name} route.
     */
    public const PLUGIN_NAME = 'WebTerm';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webterm.php', 'webterm');

        $this->app->singleton(CredentialManager::class);

        // Registered here, NOT behind the pluginEnabled() gate: the diagnostic
        // commands are most needed precisely when the plugin is disabled or
        // misconfigured, and a doctor you cannot run is no use.
        if ($this->app->runningInConsole()) {
            $this->commands([
                RekeyCredentialsCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // LibreNMS auto-disables a plugin whose hook throws, so the whole of
        // boot() is defensive. A plugin that cannot boot must degrade to
        // invisible, never to a broken LibreNMS.
        try {
            $this->bootPlugin();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function bootPlugin(): void
    {
        // PluginManagerInterface is bound by LibreNMS core only. Under
        // orchestra/testbench -- and on any LibreNMS predating the v2 plugin
        // system -- it is absent, and resolving it would throw. Guarding here
        // is what lets the package be unit-tested standalone.
        if (! $this->app->bound(PluginManagerInterface::class)) {
            return;
        }

        /** @var PluginManagerInterface $plugins */
        $plugins = $this->app->make(PluginManagerInterface::class);

        // Hooks MUST be published before the pluginEnabled() gate below.
        // PluginManager::cleanupPlugins() deletes the DB row of any plugin that
        // registered no hooks, which would make us vanish from Plugin Admin and
        // leave the operator with no way to switch us back on.
        //
        // The second argument MUST be the *interface* FQCN: core looks hooks up
        // by that exact string (MenuComposer, OverviewController, ...), so
        // passing an abstract base class silently registers a hook that is
        // never called.
        $plugins->publishHook(self::PLUGIN_NAME, DeviceOverviewHook::class, DeviceOverview::class);
        $plugins->publishHook(self::PLUGIN_NAME, SettingsHook::class, Settings::class);
        $plugins->publishHook(self::PLUGIN_NAME, SinglePageHook::class, Terminal::class);

        if (! $plugins->pluginEnabled(self::PLUGIN_NAME)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', self::PLUGIN_NAME);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->publishes([
            __DIR__.'/../config/webterm.php' => config_path('webterm.php'),
        ], 'webterm-config');

    }
}
