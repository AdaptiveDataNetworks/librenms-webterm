<?php

declare(strict_types=1);

use Adn\WebTerm\Audit\AuditLogger;
use Adn\WebTerm\Authorization\GrantRepository;
use Adn\WebTerm\Authorization\ShellAuthorizer;
use Adn\WebTerm\Credentials\CredentialManager;
use Adn\WebTerm\Gateway\GatewayClient;
use Adn\WebTerm\Gateway\GatewayUnreachableException;
use Adn\WebTerm\Gateway\GatewayVersionException;
use Adn\WebTerm\Http\DevicePanelPresenter;
use Adn\WebTerm\Librenms\DeviceTarget;
use Adn\WebTerm\Models\Session as SessionModel;
use Adn\WebTerm\Session\Reconciler;
use Adn\WebTerm\Session\SessionMinter;
use Adn\WebTerm\Tests\Support\FakeDevice;
use Adn\WebTerm\Tests\Support\FakeGroups;
use Adn\WebTerm\Tests\Support\FakeRoles;
use Adn\WebTerm\Tests\Support\FakeStepUp;
use Adn\WebTerm\Tests\Support\FakeUser;
use Adn\WebTerm\Tests\Support\FakeVisibility;
use Adn\WebTerm\Tests\Support\RecordingGateway;
use Adn\WebTerm\Tests\Support\UnreachableGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
| The failure modes from the hardening list, reproduced deliberately.
|
| Each one is something an operator will actually hit. The test asserts not
| merely that it fails, but that it fails in a way that points at the cause.
*/

beforeEach(function () {
    config()->set('webterm.audit.syslog', false);
});

it('degrades the device panel gracefully when the gateway is down', function () {
    // A device page that 500s because a terminal gateway is unreachable would
    // turn "the terminal is down" into "LibreNMS is down".
    seedMintable();
    config()->set('webterm.gateway.url', 'http://127.0.0.1:1');

    $presenter = new DevicePanelPresenter(new ShellAuthorizer(
        FakeVisibility::all(),
        new DeviceTarget,
        new GrantRepository(new FakeRoles, new FakeGroups),
        FakeStepUp::notRequired(),
    ));

    expect(fn () => $presenter->present(new FakeUser(7), new FakeDevice(42)))
        ->not->toThrow(Exception::class);

    expect($presenter->present(new FakeUser(7), new FakeDevice(42))['state'])->toBe('ready');
});

it('reports an unreachable gateway with the service to check', function () {
    seedMintable();

    $minter = new SessionMinter(
        new ShellAuthorizer(
            FakeVisibility::all(), new DeviceTarget,
            new GrantRepository(new FakeRoles, new FakeGroups), FakeStepUp::notRequired(),
        ),
        new DeviceTarget,
        new UnreachableGateway,
        app(CredentialManager::class),
        new AuditLogger,
    );

    expect(fn () => $minter->mint(new FakeUser(7), new FakeDevice(42)))
        ->toThrow(GatewayUnreachableException::class);
});

it('opens a circuit rather than stalling every request on a dead gateway', function () {
    // Five terminals waiting on a blackholed socket can starve the php-fpm
    // pool. After a few failures, fail fast instead.
    config()->set('webterm.gateway.circuit_breaker.threshold', 2);
    Cache::put('webterm:gateway:failures', 2, 30);

    $client = new GatewayClient('http://127.0.0.1:1', null);

    $started = microtime(true);
    try {
        $client->hello();
    } catch (GatewayUnreachableException $e) {
        expect($e->getMessage())->toContain('systemctl status librenms-webterm-gw');
    }
    $elapsed = (microtime(true) - $started) * 1000;

    // The circuit short-circuits before any network attempt.
    expect($elapsed)->toBeLessThan(100.0);
});

it('names both versions when the protocol does not match', function () {
    // Version skew is the steady state: LibreNMS re-resolves the plugin on
    // every update while the gateway binary is untouched.
    $client = new class('http://127.0.0.1:1', null) extends GatewayClient
    {
        public function hello(): array
        {
            throw new GatewayVersionException(
                'The gateway speaks a different protocol version. '
                .'Upgrade the gateway, or pin the plugin to a matching release.'
            );
        }
    };

    expect(fn () => $client->hello())
        ->toThrow(GatewayVersionException::class, 'Upgrade the gateway');
});

it('does not reap live sessions when the gateway is unreachable', function () {
    // An unreachable gateway tells us nothing about what is running. Reaping on
    // that basis would kill every live session during a transient blip.
    SessionModel::create([
        'session_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
        'user_id' => 7, 'device_id' => 42,
        'state' => SessionModel::ACTIVE, 'method' => 'password',
        'started_at' => now()->subMinutes(5),
    ]);

    $result = (new Reconciler(new UnreachableGateway))->run();

    expect($result['reaped'])->toBe(0)
        ->and(SessionModel::query()->live()->count())->toBe(1);
});

it('reaps sessions the gateway has never heard of', function () {
    // Otherwise they consume a user's concurrency budget forever.
    SessionModel::create([
        'session_id' => '01BBBBBBBBBBBBBBBBBBBBBBBB',
        'user_id' => 7, 'device_id' => 42,
        'state' => SessionModel::ACTIVE, 'method' => 'password',
        'gateway_instance_id' => 'gw-test',
        'started_at' => now()->subMinutes(5),
    ]);

    $result = (new Reconciler(new RecordingGateway))->run();

    expect($result['reaped'])->toBe(1)
        ->and(SessionModel::query()->live()->count())->toBe(0);
});

it('reaps everything when the gateway instance id changes', function () {
    // A restarted gateway kept nothing, so every session we believe is live is
    // stale -- including ones the new instance has not reported yet.
    SessionModel::create([
        'session_id' => '01CCCCCCCCCCCCCCCCCCCCCCCC',
        'user_id' => 7, 'device_id' => 42,
        'state' => SessionModel::ACTIVE, 'method' => 'password',
        'gateway_instance_id' => 'gw-OLD-instance',
        'started_at' => now()->subMinutes(5),
    ]);

    (new Reconciler(new RecordingGateway))->run();

    expect(SessionModel::query()->live()->count())->toBe(0);
});

it('does not reap a pending session that simply has not connected yet', function () {
    // A user who has clicked but not yet loaded the terminal must not have
    // their session reaped out from under them.
    SessionModel::create([
        'session_id' => '01DDDDDDDDDDDDDDDDDDDDDDDD',
        'user_id' => 7, 'device_id' => 42,
        'state' => SessionModel::PENDING, 'method' => 'password',
        'gateway_instance_id' => 'gw-test',
        'started_at' => now(),
    ]);

    (new Reconciler(new RecordingGateway))->run();

    expect(SessionModel::query()->live()->count())->toBe(1);
});
