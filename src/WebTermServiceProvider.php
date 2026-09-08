<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm;

use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\GroupSource;
use AdaptiveDataNetworks\WebTerm\Authorization\StepUpGate;
use AdaptiveDataNetworks\WebTerm\Authorization\TotpStepUp;
use AdaptiveDataNetworks\WebTerm\Console\AbilityCommand;
use AdaptiveDataNetworks\WebTerm\Console\ConfigCommand;
use AdaptiveDataNetworks\WebTerm\Console\DoctorCommand;
use AdaptiveDataNetworks\WebTerm\Console\ExplainCredentialCommand;
use AdaptiveDataNetworks\WebTerm\Console\ForgetCredentialCommand;
use AdaptiveDataNetworks\WebTerm\Console\GrantCommand;
use AdaptiveDataNetworks\WebTerm\Console\HostKeyResetCommand;
use AdaptiveDataNetworks\WebTerm\Console\HostKeyScanCommand;
use AdaptiveDataNetworks\WebTerm\Console\ListCredentialsCommand;
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
use AdaptiveDataNetworks\WebTerm\Hooks\MenuEntry;
use AdaptiveDataNetworks\WebTerm\Hooks\Settings;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceGroups;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTab;
use AdaptiveDataNetworks\WebTerm\Support\RuntimeSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
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
        $this->app->bind(GroupSource::class, DeviceGroups::class);

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
                ExplainCredentialCommand::class,
                ForgetCredentialCommand::class,
                GrantCommand::class,
                HostKeyResetCommand::class,
                HostKeyScanCommand::class,
                ListCredentialsCommand::class,
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
        $plugins->publishHook(self::PLUGIN_NAME, MenuEntryHook::class, MenuEntry::class);
        $plugins->publishHook(self::PLUGIN_NAME, SinglePageHook::class, Terminal::class);

        // Registered ahead of the enabled gate so that a plugin switched off
        // for a while does not silently fall behind on schema, and then fail
        // the moment it is switched back on.
        $this->followCoreMigrations($plugins);
        $this->scheduleReconciler();

        if (! $plugins->pluginEnabled(self::PLUGIN_NAME)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', self::PLUGIN_NAME);
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->registerDeviceTab();

        $this->publishes([
            __DIR__.'/../config/webterm.php' => config_path('webterm.php'),
        ], 'webterm-config');

    }

    /**
     * Put the terminal on the device page as a tab.
     *
     * Core offers no hook for this -- the plugin interfaces cover device
     * overview, port tabs, settings, menu entries and single pages, but not
     * device tabs -- so this appends to App\View\Components\Device\PageTabs::$tabsClasses,
     * which is a public static array. That is not a supported extension point,
     * so every step is guarded and any failure leaves LibreNMS exactly as it
     * was, minus a tab.
     *
     * The load-bearing guard is the view check. DeviceController resolves a
     * tab's view as `device.tabs.{slug}` and, when that view does not exist,
     * falls through to renderLegacyTab() which `include`s
     * includes/html/pages/device/{slug}.inc.php with no file_exists guard. For
     * a slug of ours that file never exists, so a registered tab whose view
     * fails to resolve is not a blank panel -- it is a 500 on the device page,
     * after loading the entire legacy bootstrap to get there. So the slug is
     * registered only once the view is known to resolve.
     */
    private function registerDeviceTab(): void
    {
        try {
            // Makes resources/views/core/device/tabs/webterm.blade.php resolve
            // as 'device.tabs.webterm'. Appended, never prepended: core's own
            // paths must keep priority so this can never shadow a core view.
            //
            // Done first and unconditionally. The view has to be resolvable
            // before registering the slug is safe, and adding a path costs
            // nothing when core is absent.
            View::addLocation(__DIR__.'/../resources/views/core');

            $tabs = 'App\View\Components\Device\PageTabs';

            if (! class_exists($tabs) || ! property_exists($tabs, 'tabsClasses')) {
                return;
            }

            if (! View::exists('device.tabs.webterm')) {
                return;
            }

            $existing = $tabs::$tabsClasses;

            if (isset($existing['webterm'])) {
                return;
            }

            $tabs::$tabsClasses = $this->spliceTabAfter($existing, 'notes', 'webterm', DeviceTab::class);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Insert a tab after a named one, falling back to the end.
     *
     * Order is pure insertion order -- core's blade just iterates the array, so
     * there is no weight or priority to set. The union operator is left-wins
     * and position-preserving, which makes this idempotent.
     *
     * @param  array<string, class-string>  $tabs
     * @return array<string, class-string>
     */
    private function spliceTabAfter(array $tabs, string $anchor, string $slug, string $class): array
    {
        $keys = array_keys($tabs);
        $at = array_search($anchor, $keys, true);

        if ($at === false) {
            return $tabs + [$slug => $class];
        }

        return array_slice($tabs, 0, $at + 1, true)
            + [$slug => $class]
            + array_slice($tabs, $at + 1, null, true);
    }

    /**
     * Run the reconciler from LibreNMS's scheduler.
     *
     * Nothing did. The reconciler is what returns a session's concurrency slot
     * once the gateway no longer holds it -- including a pending session whose
     * SSH dial failed, which otherwise counts against the operator's limit
     * forever. Every failed connection attempt permanently consumed a slot, and
     * the only recovery was running the command by hand.
     *
     * LibreNMS ships dist/librenms-scheduler.cron, which runs
     * `artisan schedule:run` every minute, so this needs no cron of its own on
     * a standard install. withoutOverlapping matters because the pass talks to
     * the gateway over HTTP and a slow gateway must not stack up runs.
     */
    private function scheduleReconciler(): void
    {
        $this->app->booted(function (): void {
            if (! $this->app->bound(Schedule::class)) {
                return;
            }

            $this->app->make(Schedule::class)
                ->command('webterm:reconcile')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();
        });
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
