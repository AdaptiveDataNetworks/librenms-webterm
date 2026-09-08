<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Credentials\CredentialEncrypter;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialRequest;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
use AdaptiveDataNetworks\WebTerm\Credentials\Drivers\DatabaseCredentialProvider;
use AdaptiveDataNetworks\WebTerm\Credentials\Exceptions\NoCredentialConfiguredException;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function credentialAt(CredentialScope $scope, int $ref, string $username): Credential
{
    $encrypter = new CredentialEncrypter;

    return Credential::create([
        'scope_type' => $scope->value,
        'scope_ref' => $ref,
        'protocol' => 'ssh',
        'method' => 'password',
        'username' => $username,
        'payload' => $encrypter->encrypt(['password' => 'secret-for-'.$username]),
        'cipher' => $encrypter->cipher(),
        'key_id' => $encrypter->keyId(),
    ]);
}

/** @param list<int> $groupIds */
function resolveFor(int $deviceId, array $groupIds = []): string
{
    $resolved = (new DatabaseCredentialProvider)->resolve(
        new CredentialRequest($deviceId, 'netops', new FakeUser(1), null, 'ssh', $groupIds)
    );

    return $resolved->username;
}

it('uses the global default when nothing more specific exists', function (): void {
    credentialAt(CredentialScope::Global, CredentialScope::UNTARGETED, 'fleet-account');

    expect(resolveFor(42))->toBe('fleet-account');
});

it('prefers a group credential over the global default', function (): void {
    credentialAt(CredentialScope::Global, CredentialScope::UNTARGETED, 'fleet-account');
    credentialAt(CredentialScope::Group, 7, 'group-account');

    expect(resolveFor(42, [7]))->toBe('group-account');
});

it('prefers a device credential over both', function (): void {
    credentialAt(CredentialScope::Global, CredentialScope::UNTARGETED, 'fleet-account');
    credentialAt(CredentialScope::Group, 7, 'group-account');
    credentialAt(CredentialScope::Device, 42, 'device-account');

    expect(resolveFor(42, [7]))->toBe('device-account');
});

it('ignores a group credential for a group the device is not in', function (): void {
    credentialAt(CredentialScope::Global, CredentialScope::UNTARGETED, 'fleet-account');
    credentialAt(CredentialScope::Group, 9, 'other-group');

    expect(resolveFor(42, [7]))->toBe('fleet-account');
});

it('breaks a multi-group tie on the lowest group id, deterministically', function (): void {
    // A device in several groups must resolve the same way every time, or the
    // credential it uses depends on row order -- which is undebuggable.
    credentialAt(CredentialScope::Group, 9, 'group-nine');
    credentialAt(CredentialScope::Group, 3, 'group-three');

    expect(resolveFor(42, [9, 3]))->toBe('group-three')
        ->and(resolveFor(42, [3, 9]))->toBe('group-three');
});

it('still refuses when no scope applies, and says how to fix it', function (): void {
    expect(fn () => resolveFor(42))
        ->toThrow(NoCredentialConfiguredException::class, '--global');
});

it('allows one credential per scope, not one per device', function (): void {
    credentialAt(CredentialScope::Device, 42, 'a');
    credentialAt(CredentialScope::Device, 43, 'b');
    credentialAt(CredentialScope::Group, 7, 'c');
    credentialAt(CredentialScope::Global, CredentialScope::UNTARGETED, 'd');

    expect(Credential::query()->count())->toBe(4);

    // The unique key is now (scope_type, scope_ref, protocol). This is why the
    // global scope stores 0 rather than null: a unique index treats NULLs as
    // distinct, so a nullable reference would accept a second global row and
    // leave which one applies down to insertion order.
    expect(fn () => credentialAt(CredentialScope::Global, CredentialScope::UNTARGETED, 'second-global'))
        ->toThrow(QueryException::class);
});

it('reports a device it cannot resolve rather than throwing', function (): void {
    // There is no LibreNMS Device model standalone, so explain cannot look the
    // device up. It must say so cleanly; the resolution itself is covered by
    // the precedence tests above, which drive the provider directly.
    $this->artisan('webterm:credentials:explain --device=42')
        ->expectsOutputToContain('No such device')
        ->assertFailed();
});

it('refuses ambiguous or absent scope options', function (): void {
    $this->artisan('webterm:credentials:forget --global --group=7 --force')
        ->expectsOutputToContain('mutually exclusive')
        ->assertFailed();

    $this->artisan('webterm:credentials:forget --force')
        ->expectsOutputToContain('exactly one of --device, --group or --global')
        ->assertFailed();
});

it('forgets a global credential without touching device ones', function (): void {
    credentialAt(CredentialScope::Global, CredentialScope::UNTARGETED, 'fleet-account');
    credentialAt(CredentialScope::Device, 42, 'device-account');

    $this->artisan('webterm:credentials:forget --global --force')->assertSuccessful();

    expect(Credential::query()->count())->toBe(1)
        ->and(Credential::query()->first()->scope_type)->toBe(CredentialScope::Device);
});
