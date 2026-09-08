<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
use AdaptiveDataNetworks\WebTerm\Database\MigrationRunner;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The scope migration is the only one here that alters an existing table, and
 * it does so in several statements. Laravel wraps a migration in a transaction
 * only where the grammar reports supportsSchemaTransactions(), which is true
 * for PostgreSQL and SQL Server and false for MySQL, MariaDB and SQLite -- so
 * on every database this plugin supports these statements run one at a time
 * with no rollback. An interrupted run is never recorded, so the next run
 * starts from the top and must not die on work it already did.
 *
 * Engine-specific ALTER behaviour is covered by tools/migration-check.php
 * against real MySQL and MariaDB; this pins the logic.
 */
function scopeMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_09_08_000001_add_scope_to_webterm_credentials.php';
}

it('is idempotent -- running up twice is not an error', function (): void {
    $migration = scopeMigration();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('webterm_credentials', 'scope_type'))->toBeTrue()
        ->and(Schema::hasColumn('webterm_credentials', 'device_id'))->toBeFalse();
});

it('preserves an encrypted payload across down and up', function (): void {
    $payload = base64_encode(random_bytes(64));

    Credential::create([
        'scope_type' => CredentialScope::Device->value,
        'scope_ref' => 4242,
        'protocol' => 'ssh',
        'method' => 'password',
        'username' => 'netops',
        'payload' => $payload,
        'cipher' => 'aes-256-gcm',
        'key_id' => 'checkkey00000000',
    ]);

    $migration = scopeMigration();
    $migration->down();

    expect(Schema::hasColumn('webterm_credentials', 'device_id'))->toBeTrue();

    $migration->up();

    $row = Credential::query()->where('scope_ref', 4242)->first();

    expect($row)->not->toBeNull()
        ->and($row->payload)->toBe($payload)
        ->and($row->scope_type)->toBe(CredentialScope::Device);
});

it('drops shared credentials on rollback rather than mis-assigning them', function (): void {
    // A group or global row has no representation in the pre-scope shape.
    // Rewriting them into device rows would point a secret at equipment it was
    // never meant for, so down() removes them instead.
    Credential::create([
        'scope_type' => CredentialScope::Global->value,
        'scope_ref' => CredentialScope::UNTARGETED,
        'protocol' => 'ssh',
        'method' => 'password',
        'username' => 'fleet',
        'payload' => 'x',
        'cipher' => 'aes-256-gcm',
        'key_id' => 'k',
    ]);

    scopeMigration()->down();

    expect(DB::table('webterm_credentials')->count())->toBe(0);
});

it('rolls back only the newest migration, leaving the rest applied', function (): void {
    // The way back from a development build. Without it, downgrading is a
    // one-way door: released code that predates a migration queries columns the
    // migration has already changed, and the full rollback takes the audit
    // trail with it.
    $runner = app(MigrationRunner::class);

    expect(Schema::hasColumn('webterm_credentials', 'scope_type'))->toBeTrue();

    // Computed rather than hardcoded to 1: this assertion is about the scope
    // migration's round trip, and any later migration would otherwise make it
    // roll back the wrong thing.
    $applied = $runner->ran();
    sort($applied);
    $position = array_search('2026_09_08_000001_add_scope_to_webterm_credentials', $applied, true);
    $steps = count($applied) - (int) $position;

    $rolled = $runner->rollbackSteps($steps);

    expect($rolled)->toHaveCount($steps)
        // The scope migration is undone...
        ->and(Schema::hasColumn('webterm_credentials', 'scope_type'))->toBeFalse()
        ->and(Schema::hasColumn('webterm_credentials', 'device_id'))->toBeTrue()
        // ...and everything else still stands, audit trail included.
        ->and(Schema::hasTable('webterm_audit'))->toBeTrue()
        ->and(Schema::hasTable('webterm_grants'))->toBeTrue()
        // The repository must survive, or the next migrate re-runs everything.
        ->and(Schema::hasTable(MigrationRunner::TABLE))->toBeTrue();

    // And it is re-appliable, which is what returning to the dev build needs.
    $runner->migrate();
    expect(Schema::hasColumn('webterm_credentials', 'scope_type'))->toBeTrue();
});

it('detects a schema newer than the code, which otherwise fails silently', function (): void {
    // Downgrading past a migration leaves the columns changed and nothing
    // pending, so every check that looks for pending work reports green while
    // credential resolution dies on a missing column.
    $runner = app(MigrationRunner::class);

    expect($runner->appliedWithoutFiles())->toBe([]);

    DB::table(MigrationRunner::TABLE)->insert([
        'migration' => '2027_01_01_000001_create_webterm_something_newer',
        'batch' => 99,
    ]);

    expect($runner->appliedWithoutFiles())->toBe(['2027_01_01_000001_create_webterm_something_newer'])
        ->and($runner->pending())->toBe([]);

    $this->artisan('webterm:doctor')
        ->expectsOutputToContain('newer than the code')
        ->assertFailed();
});
