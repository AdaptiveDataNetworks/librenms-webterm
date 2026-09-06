<?php

declare(strict_types=1);

use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\Credentials\CredentialRequest;
use Adn\WebTerm\Credentials\Drivers\VaultCredentialProvider;
use Adn\WebTerm\Credentials\Exceptions\CredentialResolutionFailedException;
use Adn\WebTerm\Credentials\Exceptions\NoCredentialConfiguredException;
use Adn\WebTerm\Credentials\Exceptions\ProviderUnavailableException;
use Adn\WebTerm\Credentials\Vault\KvV2Engine;
use Adn\WebTerm\Credentials\Vault\SshSignerEngine;
use Adn\WebTerm\Credentials\Vault\TokenManager;
use Adn\WebTerm\Credentials\Vault\VaultTransport;
use Adn\WebTerm\Models\Target;
use Adn\WebTerm\Tests\Support\FakeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function vaultRequest(?string $publicKey = null): CredentialRequest
{
    return new CredentialRequest(42, 'netops', new FakeUser(7), $publicKey);
}

function transport(): VaultTransport
{
    return new VaultTransport('https://vault.example.com:8200');
}

it('signs a public key and returns a certificate', function () {
    Http::fake([
        '*/v1/ssh-client-signer/sign/*' => Http::response([
            'data' => ['signed_key' => 'ssh-ed25519-cert-v01@openssh.com AAAAsigned'],
        ]),
    ]);

    $credential = (new SshSignerEngine(transport()))
        ->sign(vaultRequest('ssh-ed25519 AAAApub'), 'tok');

    expect($credential->method)->toBe(CredentialMethod::SignedCertificate)
        ->and($credential->reveal()['certificate'])->toContain('cert-v01@openssh.com');
});

it('derives valid_principals and key_id on the server, never from the browser', function () {
    Http::fake(['*' => Http::response(['data' => ['signed_key' => 'x']])]);

    (new SshSignerEngine(transport()))->sign(vaultRequest('ssh-ed25519 AAAA'), 'tok');

    Http::assertSent(function ($request) {
        // The principal is what the device's sshd authorises against, so it
        // must come from the pinned target, not from user input.
        return $request['valid_principals'] === 'netops'
            && str_starts_with((string) $request['key_id'], 'librenms-webterm:')
            && $request['ttl'] === '30m';
    });
});

it('constrains key_id to characters that are safe in a remote auth log', function () {
    Http::fake(['*' => Http::response(['data' => ['signed_key' => 'x']])]);

    $user = new FakeUser(7);
    $request = new CredentialRequest(42, 'netops', $user, 'ssh-ed25519 AAAA');

    (new SshSignerEngine(transport()))->sign($request, 'tok');

    Http::assertSent(function ($request) {
        return preg_match('/^[A-Za-z0-9._:-]+$/', (string) $request['key_id']) === 1;
    });
});

it('refuses to sign when the gateway supplied no public key', function () {
    expect(fn () => (new SshSignerEngine(transport()))->sign(vaultRequest(null), 'tok'))
        ->toThrow(CredentialResolutionFailedException::class);
});

it('inserts the KV v2 /data/ segment that everyone forgets', function () {
    Http::fake(['*' => Http::response(['data' => ['data' => ['password' => 'hunter2']]])]);

    (new KvV2Engine(transport()))->read(vaultRequest(), 'tok');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/secret/data/librenms/devices/42'));
});

it('reads a password from KV v2', function () {
    Http::fake(['*' => Http::response(['data' => ['data' => ['password' => 'hunter2']]])]);

    $credential = (new KvV2Engine(transport()))->read(vaultRequest(), 'tok');

    expect($credential->method)->toBe(CredentialMethod::Password)
        ->and($credential->reveal())->toBe(['password' => 'hunter2']);
});

it('prefers a private key when the secret has one', function () {
    Http::fake(['*' => Http::response(['data' => ['data' => [
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
        'passphrase' => 'p',
    ]]])]);

    $credential = (new KvV2Engine(transport()))->read(vaultRequest(), 'tok');

    expect($credential->method)->toBe(CredentialMethod::PrivateKey)
        ->and($credential->reveal())->toHaveKeys(['private_key', 'passphrase']);
});

it('tells the operator the exact vault command when a secret is missing', function () {
    Http::fake(['*' => Http::response([], 404)]);

    expect(fn () => (new KvV2Engine(transport()))->read(vaultRequest(), 'tok'))
        ->toThrow(NoCredentialConfiguredException::class, 'vault kv put');
});

it('rejects path template values that could escape the intended prefix', function (string $value) {
    // A hostname is attacker-influenceable in some environments; an unvalidated
    // one could read another application's secrets.
    $engine = new KvV2Engine(transport(), 'secret', 'librenms/{hostname}');

    expect(fn () => $engine->resolvePath(['hostname' => $value]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '../../other-app/secrets',
    'a/b',
    '',
    'has space',
    'sw#1',
    str_repeat('x', 65),
]);

it('rejects a template with an unsupported placeholder', function () {
    $engine = new KvV2Engine(transport(), 'secret', 'librenms/{os}/{device_id}');

    expect(fn () => $engine->resolvePath(['device_id' => '42']))
        ->toThrow(InvalidArgumentException::class, 'placeholder');
});

it('explains a sealed vault instead of surfacing a raw error', function () {
    Http::fake(['*' => Http::response([], 503)]);

    expect(fn () => transport()->get('sys/health'))
        ->toThrow(ProviderUnavailableException::class, 'fails closed');
});

it('never lets a raw client exception carry the vault token outward', function () {
    // A Guzzle exception renders the request, headers included -- and the
    // header in question is X-Vault-Token.
    Http::fake(fn () => throw new ConnectionException('cURL error 7'));

    try {
        transport()->post('auth/approle/login', ['secret_id' => 'SECRET-CANARY'], 'TOKEN-CANARY');
        expect(false)->toBeTrue('expected a throw');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(ProviderUnavailableException::class)
            ->and($e->getMessage())->not->toContain('TOKEN-CANARY')
            ->and($e->getMessage())->not->toContain('SECRET-CANARY')
            // The trace is what lands in a log or an APP_DEBUG error page.
            // (print_r on a Laravel exception walks the whole container.)
            ->and($e->getTraceAsString())->not->toContain('TOKEN-CANARY')
            ->and($e->getTraceAsString())->not->toContain('SECRET-CANARY');
    }
});

it('omits the namespace header on sys endpoints', function () {
    // Namespaces do not apply to sys/, and sending the header there produces a
    // confusing 404.
    Http::fake(['*' => Http::response(['data' => []])]);

    $t = new VaultTransport('https://vault.example.com:8200', 'team-a');
    $t->get('sys/health');
    $t->get('secret/data/x', 'tok');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'sys/health')
        ? ! $request->hasHeader('X-Vault-Namespace')
        : $request->hasHeader('X-Vault-Namespace', 'team-a'));
});

it('caches the vault token encrypted, never in plaintext', function () {
    // Laravel's cache is often Redis or a database table, backed up and
    // inspected far more casually than a vault.
    Http::fake(['*' => Http::response([
        'auth' => ['client_token' => 'TOKEN-CANARY', 'lease_duration' => 3600],
    ])]);

    $manager = new TokenManager(transport(), 'approle', [
        'role_id' => 'r',
        'secret_id_file' => tempnam(sys_get_temp_dir(), 'sid'),
    ]);

    expect($manager->token())->toBe('TOKEN-CANARY');

    $cached = Cache::get('webterm:vault:token');
    expect($cached)->toBeString()
        ->and($cached)->not->toContain('TOKEN-CANARY');
});

it('declares that it can issue signed certificates', function () {
    expect((new VaultCredentialProvider)->capabilities())
        ->toContain(CredentialMethod::SignedCertificate);
});

it('pins the engine per target rather than inferring it at connect time', function () {
    Http::fake(['*' => Http::response(['data' => ['signed_key' => 'ssh-cert']])]);

    Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => 'ssh_signer', 'host_key_policy' => 'pin', 'principal' => 'netops',
    ]);

    $tokens = new class(transport()) extends TokenManager
    {
        public function token(): string
        {
            return 'tok';
        }
    };

    $provider = new VaultCredentialProvider(transport(), $tokens);
    $credential = $provider->resolve(vaultRequest('ssh-ed25519 AAAA'));

    expect($credential->method)->toBe(CredentialMethod::SignedCertificate);
});
