<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Authorization\ReasonCode;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialEncrypter;
use AdaptiveDataNetworks\WebTerm\Models\AuditEntry;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

const SECRET_PASSWORD = 'correct-horse-battery-staple';

function storeCredentialFor(int $deviceId, string $username = 'netops'): Credential
{
    $encrypter = new CredentialEncrypter;

    return Credential::create([
        'device_id' => $deviceId,
        'protocol' => 'ssh',
        'method' => 'password',
        'username' => $username,
        'payload' => $encrypter->encrypt(['password' => SECRET_PASSWORD]),
        'cipher' => $encrypter->cipher(),
        'key_id' => $encrypter->keyId(),
    ]);
}

it('says plainly when nothing is stored, and how to store something', function (): void {
    $this->artisan('webterm:credentials:list')
        ->expectsOutputToContain('No credentials stored.')
        ->assertSuccessful();
});

it('never prints the secret it is describing', function (): void {
    storeCredentialFor(42);

    expect(Artisan::call('webterm:credentials:list'))->toBe(0);

    $output = Artisan::output();

    expect($output)->not->toContain(SECRET_PASSWORD)
        ->and($output)->toContain('netops');
});

it('flags a credential stored under a different login than the target connects as', function (): void {
    storeCredentialFor(42, 'wrong-login');
    Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => 'database', 'host_key_policy' => Target::POLICY_PIN, 'principal' => 'netops',
    ]);

    $this->artisan('webterm:credentials:list')
        ->expectsOutputToContain('stored under a different login')
        ->assertSuccessful();
});

it('flags a credential still encrypted under a superseded key', function (): void {
    $credential = storeCredentialFor(42);
    $credential->update(['key_id' => 'an-older-key']);

    $this->artisan('webterm:credentials:list')
        ->expectsOutputToContain('superseded encryption key')
        ->assertSuccessful();
});

it('deletes a credential and records it in the audit trail', function (): void {
    storeCredentialFor(42);

    $this->artisan('webterm:credentials:forget --device=42 --force')->assertSuccessful();

    expect(Credential::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'credential.removed')->count())->toBe(1);
});

it('will not delete without confirmation', function (): void {
    storeCredentialFor(42);

    $this->artisan('webterm:credentials:forget --device=42')
        ->expectsConfirmation(
            'Delete the stored password credential for 42 (login "netops")? Sessions will fail until one is set again.',
            'no'
        )
        ->assertFailed();

    expect(Credential::query()->count())->toBe(1);
});

it('treats forgetting a device with no credential as a no-op, not an error', function (): void {
    $this->artisan('webterm:credentials:forget --device=42 --force')
        ->expectsOutputToContain('No credential stored')
        ->assertSuccessful();
});

it('can still remove a credential whose device has been deleted from LibreNMS', function (): void {
    // Nothing links webterm_credentials to core's devices table, so removing a
    // device leaves the secret behind. Before this, the row could not be
    // deleted with any shipped command.
    storeCredentialFor(999);

    $this->artisan('webterm:credentials:forget --device=999 --force')
        ->expectsOutputToContain('no longer exists in LibreNMS')
        ->assertSuccessful();

    expect(Credential::query()->count())->toBe(0);
});

/**
 * webterm:why told operators hitting an explicit deny to run `webterm:deny`,
 * which has never existed. Remediation that cannot be followed is worse than
 * none: it costs the operator a round trip before they stop believing the tool.
 */
it('only ever recommends commands that are actually registered', function (): void {
    $registered = array_keys(app(Kernel::class)->all());

    foreach (ReasonCode::cases() as $reason) {
        $remediation = $reason->remediation();

        if ($remediation === null || ! str_contains($remediation, 'webterm:')) {
            continue;
        }

        preg_match('/webterm:[a-z0-9:._-]+/', $remediation, $m);

        expect(in_array($m[0], $registered, true))->toBeTrue(
            sprintf('ReasonCode::%s recommends "%s", which is not a registered command', $reason->name, $m[0])
        );
    }
});
