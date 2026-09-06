<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Authorization\GrantRepository;
use AdaptiveDataNetworks\WebTerm\Authorization\ReasonCode;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialManager;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTarget;
use AdaptiveDataNetworks\WebTerm\Models\AuditEntry;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Models\Session as SessionModel;
use AdaptiveDataNetworks\WebTerm\Models\Ticket;
use AdaptiveDataNetworks\WebTerm\Session\MintException;
use AdaptiveDataNetworks\WebTerm\Session\SessionMinter;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeDevice;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeGroups;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeRoles;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeStepUp;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeVisibility;
use AdaptiveDataNetworks\WebTerm\Tests\Support\RecordingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function minter(RecordingGateway $gateway, ?FakeStepUp $stepUp = null): SessionMinter
{
    return new SessionMinter(
        new ShellAuthorizer(
            FakeVisibility::all(),
            new DeviceTarget,
            new GrantRepository(new FakeRoles, new FakeGroups),
            $stepUp ?? FakeStepUp::notRequired(),
        ),
        new DeviceTarget,
        $gateway,
        app(CredentialManager::class),
        new AuditLogger,
    );
}

it('runs the three phases in the order that matters', function () {
    seedMintable();
    $gateway = new RecordingGateway;

    $result = minter($gateway)->mint(new FakeUser(7), new FakeDevice(42));

    // The credential is resolved and pushed only AFTER the pending session
    // exists -- so browsing device pages mints nothing.
    expect($gateway->calls)->toBe(['createSession', 'supplyCredential'])
        ->and($result->ticket)->not->toBeEmpty()
        ->and($result->sessionId)->toHaveLength(26);
});

it('sends the credential to the gateway but never to the browser', function () {
    seedMintable();
    $gateway = new RecordingGateway;

    $result = minter($gateway)->mint(new FakeUser(7), new FakeDevice(42));

    expect($gateway->credentials['password'] ?? null)->toBe('CANARY-mint-secret')
        ->and(json_encode($result->toArray()))->not->toContain('CANARY-mint-secret');
});

it('stores only the hash of the ticket', function () {
    seedMintable();
    $gateway = new RecordingGateway;

    $result = minter($gateway)->mint(new FakeUser(7), new FakeDevice(42));
    $ticket = Ticket::query()->firstOrFail();

    expect($ticket->ticket_hash)->toBe(hash('sha256', $result->ticket))
        ->and($ticket->ticket_hash)->not->toBe($result->ticket);
});

it('records a pending session and an audit entry', function () {
    seedMintable();

    minter(new RecordingGateway)->mint(new FakeUser(7), new FakeDevice(42));

    expect(SessionModel::query()->firstOrFail()->state)->toBe(SessionModel::PENDING)
        ->and(AuditEntry::query()->where('event', 'session.requested')->count())->toBe(1);
});

it('refuses and audits when authorization denies', function () {
    seedMintable();
    Grant::query()->delete();
    $gateway = new RecordingGateway;

    expect(fn () => minter($gateway)->mint(new FakeUser(7), new FakeDevice(42)))
        ->toThrow(MintException::class);

    // Nothing reached the gateway, so no credential was ever resolved.
    expect($gateway->calls)->toBe([])
        ->and(AuditEntry::query()->where('event', 'session.denied')->count())->toBe(1);
});

it('maps an invisible device to 404, identical to one that does not exist', function () {
    seedMintable();

    $minter = new SessionMinter(
        new ShellAuthorizer(
            FakeVisibility::none(),
            new DeviceTarget,
            new GrantRepository(new FakeRoles, new FakeGroups),
            FakeStepUp::notRequired(),
        ),
        new DeviceTarget,
        new RecordingGateway,
        app(CredentialManager::class),
        new AuditLogger,
    );

    try {
        $minter->mint(new FakeUser(7), new FakeDevice(42));
        expect(false)->toBeTrue('expected a MintException');
    } catch (MintException $e) {
        expect($e->reason)->toBe(ReasonCode::DeviceNotVisible)
            ->and($e->status())->toBe(404);
    }
});

it('signals a step-up challenge with 428', function () {
    seedMintable();

    try {
        minter(new RecordingGateway, FakeStepUp::pending())->mint(new FakeUser(7), new FakeDevice(42));
        expect(false)->toBeTrue('expected a MintException');
    } catch (MintException $e) {
        expect($e->reason)->toBe(ReasonCode::StepUpRequired)
            ->and($e->status())->toBe(428);
    }
});

it('kills the pending session when the credential cannot be resolved', function () {
    // Otherwise a pending session sits on the gateway holding capacity until it
    // expires, for every failed attempt.
    seedMintable();
    Credential::query()->delete();
    $gateway = new RecordingGateway;

    expect(fn () => minter($gateway)->mint(new FakeUser(7), new FakeDevice(42)))
        ->toThrow(MintException::class);

    expect($gateway->calls)->toContain('killSession');
});

it('passes the pinned host key to the gateway', function () {
    seedMintable();
    $gateway = new RecordingGateway;

    minter($gateway)->mint(new FakeUser(7), new FakeDevice(42));

    expect($gateway->lastCreate['target']['known_hosts'])->toBe(['ssh-ed25519 AAAA'])
        ->and($gateway->lastCreate['target']['host_key_policy'])->toBe('pin');
});

it('sends an IP literal, never a hostname', function () {
    // The gateway links no resolver; a hostname here would be a dead session.
    seedMintable();
    $gateway = new RecordingGateway;

    minter($gateway)->mint(new FakeUser(7), new FakeDevice(42, ip: '10.9.8.7', hostname: 'sw.example.com'));

    expect($gateway->lastCreate['target']['ip'])->toBe('10.9.8.7')
        ->and(filter_var($gateway->lastCreate['target']['ip'], FILTER_VALIDATE_IP))->not->toBeFalse();
});

it('never lets the browser choose the SSH username', function () {
    seedMintable();
    $gateway = new RecordingGateway;

    minter($gateway)->mint(new FakeUser(7), new FakeDevice(42));

    expect($gateway->lastCreate['auth']['username'])->toBe('netops');
});
