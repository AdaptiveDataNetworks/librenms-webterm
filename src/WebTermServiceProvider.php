<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm;

use AdaptiveDataNetworks\WebTerm\Authorization\StepUpGate;
use AdaptiveDataNetworks\WebTerm\Authorization\TotpStepUp;
use AdaptiveDataNetworks\WebTerm\Console\AbilityCommand;
use AdaptiveDataNetworks\WebTerm\Console\ConfigCommand;
use AdaptiveDataNetworks\WebTerm\Console\DoctorCommand;
use AdaptiveDataNetworks\WebTerm\Console\GrantCommand;
use AdaptiveDataNetworks\WebTerm\Console\HostKeyResetCommand;
use AdaptiveDataNetworks\WebTerm\Console\HostKeyScanCommand;
use AdaptiveDataNetworks\WebTerm\Console\MigrateCommand;
use AdaptiveDataNetworks\WebTerm\Console\ReconcileCommand;
use AdaptiveDataNetworks\WebTerm\Console\RekeyCredentialsCommand;
use AdaptiveDataNetworks\WebTerm\Console\SessionsCommand;
use AdaptiveDataNetworks\WebTerm\Console\SetCredentialCommand;
use AdaptiveDataNetworks\WebTerm\Console\TargetCommand;
use AdaptiveDataNetworks\WebTerm\Console\WhyCommand;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialManager;
use AdaptiveDataNetworks\WebTerm\Database\MigrationRunner;
use AdaptiveDataNetworks\WebTerm\Hooks\DeviceOverview;
use AdaptiveDataNetworks\WebTerm\Hooks\Settings;
use AdaptiveDataNetworks\WebTerm\Support\RuntimeSettings;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
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

        // The TOTP gate is the real implementation of step-up. Until this
        // binding existed, ShellAuthorizer fell back to AlwaysChallengeStepUp,
        // which is satisfied by nothing -- so with step_up on (the default)
        // every session mint denied with StepUpRequired and no operator could
        // ever open a terminal.
        $this->app->bind(StepUpGate::class, TotpStepUp::class);

        $this->app->singleton(MigrationRunner::class, static fn ($app): MigrationRunner => new MigrationRunner(
            $app['db'],
            $app['files'],
        ));

        // Registered here, NOT behind the pluginEnabled() gate: the diagnostic
        // commands are most needed precisely when the plugin is disabled or
        // misconfigured, and a doctor you cannot run is no use.
        if ($this->app->runningInConsole()) {
            $this->commands([
                AbilityCommand::class,
                ConfigCommand::class,
                DoctorCommand::class,
                GrantCommand::class,
                HostKeyResetCommand::class,
                HostKeyScanCommand::class,
                MigrateCommand::class,
                ReconcileCommand::class,
                RekeyCredentialsCommand::class,
                SessionsCommand::class,
                SetCredentialCommand::class,
                TargetCommand::class,
                WhyCommand::class,
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
        // Runtime settings override the config file, and must be applied before
        // anything consults them -- the kill switch included. Without this,
        // `webterm:config set` wrote a row nothing ever read.
        RuntimeSettings::apply();

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

        // Registered ahead of the enabled gate so that a plugin switched off
        // for a while does not silently fall behind on schema, and then fail
        // the moment it is switched back on.
        $this->followCoreMigrations($plugins);

        if (! $plugins->pluginEnabled(self::PLUGIN_NAME)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', self::PLUGIN_NAME);
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->publishes([
            __DIR__.'/../config/webterm.php' => config_path('webterm.php'),
        ], 'webterm-config');

    }

    /**
     * Keep our schema current whenever `./lnms migrate` runs.
     *
     * Our migrations are recorded in a table of our own rather than in core's,
     * because `./validate.php` reports every row in core's `migrations` table
     * that core does not itself ship as an extra migration -- see
     * {@see MigrationRunner}. The consequence is that `./lnms migrate` no
     * longer finds them, and LibreNMS's daily.sh runs exactly that on every
     * update. So we follow along behind it.
     *
     * Both events are needed. MigrationsEnded fires only when core actually had
     * something to run; when it did not, Laravel fires NoPendingMigrations
     * instead, which is the far more common case on a routine update.
     */
    private function followCoreMigrations(PluginManagerInterface $plugins): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $handler = function (object $event) use ($plugins): void {
            if (($event->method ?? null) !== 'up') {
                return;
            }

            try {
                /** @var MigrationRunner $runner */
                $runner = $this->app->make(MigrationRunner::class);

                // Don't create tables for a plugin the operator installed but
                // never switched on. Once our repository exists the install is
                // ours to keep current either way.
                if (! $runner->repositoryExists() && ! $plugins->pluginEnabled(self::PLUGIN_NAME)) {
                    return;
                }

                $runner->migrate();
            } catch (Throwable $e) {
                // A plugin must never be able to fail `./lnms migrate`. The
                // operator is told about pending migrations by webterm:doctor.
                report($e);
            }
        };

        $this->app['events']->listen(MigrationsEnded::class, $handler);
        $this->app['events']->listen(NoPendingMigrations::class, $handler);
    }
}
