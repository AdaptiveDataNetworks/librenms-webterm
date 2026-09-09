<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Gateway\GatewayStatus;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTabPresenter;
use AdaptiveDataNetworks\WebTerm\Protocol;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeDevice;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => GatewayStatus::forget());

it('says nothing when the gateway has never been heard from', function (): void {
    // A cold cache must read as "fine", not as "broken". On a fresh install
    // nothing has run the reconciler yet, and hiding the terminal because we
    // have not checked would be worse than the behaviour we had before.
    expect(GatewayStatus::protocolMismatch())->toBeNull();
});

it('says nothing when the gateway speaks our protocol', function (): void {
    GatewayStatus::record(['min_protocol' => Protocol::VERSION, 'max_protocol' => Protocol::VERSION]);

    expect(GatewayStatus::protocolMismatch())->toBeNull();
});

it('explains a mismatch in both directions', function (): void {
    GatewayStatus::record(['min_protocol' => Protocol::VERSION + 1, 'max_protocol' => Protocol::VERSION + 2]);
    expect(GatewayStatus::protocolMismatch())->toContain('no version skew tolerance');

    GatewayStatus::record(['min_protocol' => 1, 'max_protocol' => 1]);
    expect(GatewayStatus::protocolMismatch())->toBeNull();
});

it('ignores a hello that carries no usable range', function (): void {
    GatewayStatus::record(['instance_id' => 'gw-1']);

    expect(GatewayStatus::protocolMismatch())->toBeNull();
});

it('refuses the terminal tab before the click, not with a 409 after it', function (): void {
    // The mismatch used to surface only at session-create -- an HTTP 409 raised
    // as GatewayVersionException, which is to say after the operator clicked --
    // while the docs promised a warning banner that did not exist.
    config()->set('webterm.enabled', true);
    GatewayStatus::record(['min_protocol' => Protocol::VERSION + 5, 'max_protocol' => Protocol::VERSION + 5]);

    $this->actingAs(new FakeUser(7));

    $data = (new DeviceTabPresenter)->dataFor(new FakeDevice(42));

    expect($data['webtermState'])->toBe('denied')
        ->and($data['webtermReason'])->toContain('protocol')
        ->and($data)->not->toHaveKey('webtermDeviceId');
});
