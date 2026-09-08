<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Database\MigrationRunner;
use AdaptiveDataNetworks\WebTerm\Tests\Fakes\FakePluginManager;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Our migrations are no longer discoverable by `./lnms migrate`, because they
 * live in a repository table of our own. The listener is what compensates:
 * LibreNMS's daily.sh runs `./lnms migrate` on every update, and we follow
 * along behind it. If this stops working, plugin schema silently stops being
 * upgraded on every install in the field.
 */
function bootProviderWith(FakePluginManager $manager): void
{
    app()->instance(PluginManagerInterface::class, $manager);

    (new WebTermServiceProvider(app()))->boot();
}

beforeEach(function (): void {
    // Start from nothing so we can watch the listener build the schema.
    app(MigrationRunner::class)->rollback();

    expect(Schema::hasTable('webterm_grants'))->toBeFalse();
});

it('migrates when a core migration run ends', function (): void {
    bootProviderWith(new FakePluginManager(enabled: true));

    event(new MigrationsEnded('up', []));

    expect(Schema::hasTable('webterm_grants'))->toBeTrue()
        ->and(app(MigrationRunner::class)->pending())->toBe([]);
});

it('migrates when core had nothing pending', function (): void {
    // The common case by far: a routine LibreNMS update with no core schema
    // change fires NoPendingMigrations, never MigrationsEnded. Listening only
    // for the latter would mean our migrations almost never run.
    bootProviderWith(new FakePluginManager(enabled: true));

    event(new NoPendingMigrations('up'));

    expect(Schema::hasTable('webterm_grants'))->toBeTrue();
});

it('ignores a rollback', function (): void {
    bootProviderWith(new FakePluginManager(enabled: true));

    event(new MigrationsEnded('down', []));

    expect(Schema::hasTable('webterm_grants'))->toBeFalse();
});

it('creates no tables for a plugin that was never enabled', function (): void {
    bootProviderWith(new FakePluginManager(enabled: false));

    event(new NoPendingMigrations('up'));

    expect(Schema::hasTable('webterm_grants'))->toBeFalse();
});

it('keeps an already-installed plugin current even while it is disabled', function (): void {
    // Once our repository exists the install is ours to maintain. Letting a
    // temporarily disabled plugin fall behind on schema means it breaks at the
    // moment it is switched back on -- the worst possible time.
    app(MigrationRunner::class)->createRepository();

    bootProviderWith(new FakePluginManager(enabled: false));

    event(new NoPendingMigrations('up'));

    expect(Schema::hasTable('webterm_grants'))->toBeTrue();
});

it('never lets a plugin failure break ./lnms migrate', function (): void {
    bootProviderWith(new FakePluginManager(enabled: true, throw: true));

    // A plugin that cannot migrate must degrade to a doctor warning, not to a
    // LibreNMS update that dies half way through.
    $broke = false;

    try {
        event(new NoPendingMigrations('up'));
    } catch (Throwable) {
        $broke = true;
    }

    expect($broke)->toBeFalse();
});

it('does not write to core\'s migrations table', function (): void {
    Schema::create('migrations', function ($table): void {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });

    bootProviderWith(new FakePluginManager(enabled: true));

    event(new NoPendingMigrations('up'));

    expect(DB::table('migrations')->count())->toBe(0)
        ->and(DB::table(MigrationRunner::TABLE)->count())->toBeGreaterThan(0);
});
