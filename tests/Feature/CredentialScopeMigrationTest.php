<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
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
