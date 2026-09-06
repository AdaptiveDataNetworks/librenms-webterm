<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Credentials\CredentialEncrypter;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialManager;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialMethod;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialRequest;
use AdaptiveDataNetworks\WebTerm\Credentials\Drivers\DatabaseCredentialProvider;
use AdaptiveDataNetworks\WebTerm\Credentials\Exceptions\CredentialResolutionFailedException;
use AdaptiveDataNetworks\WebTerm\Credentials\Exceptions\NoCredentialConfiguredException;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Support\SshKey;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function storeCredential(string $password = 'hunter2', ?CredentialEncrypter $with = null): Credential
{
    $enc = $with ?? new CredentialEncrypter;

    return Credential::create([
        'device_id' => 42,
        'protocol' => 'ssh',
        'method' => CredentialMethod::Password->value,
        'username' => 'netops',
        'payload' => $enc->encrypt(['password' => $password]),
        'cipher' => $enc->cipher(),
        'key_id' => $enc->keyId(),
    ]);
}

function credentialRequest(): CredentialRequest
{
    return new CredentialRequest(42, 'netops', new FakeUser(1));
}

it('stores and resolves an encrypted credential', function () {
    storeCredential();

    $credential = (new DatabaseCredentialProvider)->resolve(credentialRequest());

    expect($credential->username)->toBe('netops')
        ->and($credential->method)->toBe(CredentialMethod::Password)
        ->and($credential->reveal())->toBe(['password' => 'hunter2']);
});

it('never stores the secret in plaintext', function () {
    storeCredential('CANARY-secret-value');

    $row = Credential::query()->firstOrFail();

    expect($row->payload)->not->toContain('CANARY-secret-value')
        ->and($row->getRawOriginal('payload'))->not->toContain('CANARY-secret-value');
});

it('derives a key distinct from APP_KEY', function () {
    // A leaked session key must not also decrypt device credentials.
    $appKey = (string) config('app.key');
    $enc = new CredentialEncrypter;

    expect($enc->keyId())->not->toBe($appKey)
        ->and(strlen($enc->keyId()))->toBe(16);
});

it('reports a helpful error when the key has changed', function () {
    // Rotating APP_KEY is routine advice; the failure must name the cause and
    // the fix rather than surfacing a framework decryption exception.
    storeCredential('hunter2', new CredentialEncrypter('a-completely-different-key-value'));

    expect(fn () => (new DatabaseCredentialProvider)->resolve(credentialRequest()))
        ->toThrow(CredentialResolutionFailedException::class, 'webterm:credentials:rekey');
});

it('explains how to add a missing credential', function () {
    expect(fn () => (new DatabaseCredentialProvider)->resolve(credentialRequest()))
        ->toThrow(NoCredentialConfiguredException::class, 'webterm:credentials:set');
});

it('declares that it cannot issue signed certificates', function () {
    // Saying so is what stops a Vault-shaped deployment silently falling back
    // to a reusable password.
    $capabilities = (new DatabaseCredentialProvider)->capabilities();

    expect($capabilities)->not->toContain(CredentialMethod::SignedCertificate)
        ->and($capabilities)->toContain(CredentialMethod::Password);
});

it('reports unhealthy while credentials remain on an older key', function () {
    storeCredential('hunter2', new CredentialEncrypter('old-key'));

    $health = (new DatabaseCredentialProvider)->health();

    expect($health->healthy)->toBeFalse()
        ->and($health->remediation)->toContain('rekey');
});

it('re-encrypts credentials onto the current key', function () {
    $old = new CredentialEncrypter('old-key-material');
    storeCredential('hunter2', $old);

    $this->artisan('webterm:credentials:rekey', ['--from' => 'old-key-material'])
        ->assertExitCode(0);

    $current = new CredentialEncrypter;
    $row = Credential::query()->firstOrFail();

    expect($row->key_id)->toBe($current->keyId())
        ->and((new DatabaseCredentialProvider)->resolve(credentialRequest())->reveal())
        ->toBe(['password' => 'hunter2']);
});

it('does not abort the whole rekey because one row is unreadable', function () {
    // A row whose key is genuinely lost must not block migrating the rest
    // before the old key is discarded.
    storeCredential('good', new CredentialEncrypter('old-key-material'));
    Credential::create([
        'device_id' => 43, 'protocol' => 'ssh',
        'method' => CredentialMethod::Password->value, 'username' => 'netops',
        'payload' => 'not-decryptable-by-anything',
        'cipher' => CredentialEncrypter::CIPHER, 'key_id' => 'lostkey123456789',
    ]);

    $this->artisan('webterm:credentials:rekey', ['--from' => 'old-key-material'])
        ->assertExitCode(1);

    $current = new CredentialEncrypter;
    expect(Credential::query()->where('device_id', 42)->firstOrFail()->key_id)
        ->toBe($current->keyId());
});

it('resolves the configured driver through the manager', function () {
    $manager = app(CredentialManager::class);

    expect($manager->driver()->name())->toBe('database');
});

it('fingerprints openssh public keys without a gateway round trip', function () {
    // ssh-keygen -lf on this key reports this fingerprint.
    $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGb9ECWmEzf8OcdU7Rw2sXFhJKKKKcNDpEqLZgWzT4Xz test@example';

    expect(SshKey::type($key))->toBe('ssh-ed25519')
        ->and(SshKey::fingerprint($key))->toStartWith('SHA256:')
        ->and(strlen((string) SshKey::fingerprint($key)))->toBe(50);
});

it('returns null for input that is not a public key', function () {
    expect(SshKey::fingerprint('not a key'))->toBeNull()
        ->and(SshKey::type('not a key'))->toBeNull();
});
