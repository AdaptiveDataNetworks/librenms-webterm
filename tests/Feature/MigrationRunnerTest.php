<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Database\MigrationRunner;
use Illuminate\Support\Facades\Schema;

/**
 * LibreNMS's ./validate.php reports every row in core's `migrations` table that
 * core does not itself ship as an "extra migration". It has no allow-list, so a
 * plugin recording migrations there is flagged permanently. These tests pin the
 * behaviour that keeps us out of that table.
 */
function coreMigrationsTable(): void
{
    if (! Schema::hasTable('migrations')) {
        Schema::create('migrations', function ($table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }
}

it('records migrations in its own table, never in core\'s', function (): void {
    expect(Schema::hasTable(MigrationRunner::TABLE))->toBeTrue();

    coreMigrationsTable();

    expect(DB::table('migrations')->count())->toBe(0);
    expect(DB::table(MigrationRunner::TABLE)->count())->toBeGreaterThan(0);
});

it('reports nothing pending once migrated', function (): void {
    expect(app(MigrationRunner::class)->pending())->toBe([]);
});

it('creates every table the plugin owns', function (): void {
    foreach (['webterm_config', 'webterm_grants', 'webterm_targets', 'webterm_audit'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('is idempotent -- a second run changes nothing', function (): void {
    $runner = app(MigrationRunner::class);
    $before = DB::table(MigrationRunner::TABLE)->count();

    expect($runner->migrate())->toBe([]);
    expect(DB::table(MigrationRunner::TABLE)->count())->toBe($before);
});

it('reclaims rows left in core\'s table by an older release', function (): void {
    $runner = app(MigrationRunner::class);
    coreMigrationsTable();

    // Reproduce an install from <= 1.0.6: the schema is already in place and
    // only the bookkeeping lives in the wrong table.
    $applied = DB::table(MigrationRunner::TABLE)->pluck('migration')->all();
    Schema::drop(MigrationRunner::TABLE);
    foreach ($applied as $name) {
        DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
    }

    expect($runner->legacyRows())->toHaveCount(count($applied));

    $reclaimed = $runner->adoptLegacyRows();

    expect($reclaimed)->toHaveCount(count($applied))
        ->and(DB::table('migrations')->count())->toBe(0)
        ->and(DB::table(MigrationRunner::TABLE)->count())->toBe(count($applied))
        ->and($runner->legacyRows())->toBe([])
        // The whole point: nothing re-ran, so the tables still stand.
        ->and(Schema::hasTable('webterm_grants'))->toBeTrue()
        ->and($runner->pending())->toBe([]);
});

it('leaves migrations belonging to anybody else alone', function (): void {
    coreMigrationsTable();

    DB::table('migrations')->insert([
        ['migration' => '2014_10_12_000000_create_users_table', 'batch' => 1],
        ['migration' => '2026_02_02_000001_create_someone_elses_table', 'batch' => 1],
    ]);

    $runner = app(MigrationRunner::class);

    expect($runner->legacyRows())->toBe([]);
    expect($runner->adoptLegacyRows())->toBe([]);
    expect(DB::table('migrations')->count())->toBe(2);
});

it('survives an install that has no core migrations table at all', function (): void {
    Schema::dropIfExists('migrations');

    $runner = app(MigrationRunner::class);

    expect($runner->legacyRows())->toBe([]);
    expect($runner->adoptLegacyRows())->toBe([]);
    expect($runner->pending())->toBe([]);
});

it('rolls everything back, its own repository table included', function (): void {
    $runner = app(MigrationRunner::class);

    $rolled = $runner->rollback();

    expect($rolled)->not->toBeEmpty()
        ->and(Schema::hasTable('webterm_grants'))->toBeFalse()
        ->and(Schema::hasTable('webterm_audit'))->toBeFalse()
        // An operator removing the plugin should be left with nothing of ours,
        // and that includes the table recording what we ran.
        ->and(Schema::hasTable(MigrationRunner::TABLE))->toBeFalse();
});

it('reports adopted-but-missing files as pending nothing, not as a crash', function (): void {
    coreMigrationsTable();

    // A migration from a future release, recorded but with no file on disk.
    DB::table('migrations')->insert([
        'migration' => '2027_01_01_000001_create_webterm_future_table',
        'batch' => 1,
    ]);

    $runner = app(MigrationRunner::class);

    expect($runner->adoptLegacyRows())->toBe(['2027_01_01_000001_create_webterm_future_table']);
    expect($runner->pending())->toBe([]);
    expect(DB::table('migrations')->count())->toBe(0);
});
