<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Authorization\GrantRepository;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
use AdaptiveDataNetworks\WebTerm\Http\DevicePanelPresenter;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTarget;
use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeDevice;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeGroups;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeRoles;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeStepUp;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function presenter(?FakeVisibility $visibility = null, ?FakeStepUp $stepUp = null): DevicePanelPresenter
{
    return new DevicePanelPresenter(new ShellAuthorizer(
        $visibility ?? FakeVisibility::all(),
        new DeviceTarget,
        new GrantRepository(new FakeRoles, new FakeGroups),
        $stepUp ?? FakeStepUp::notRequired(),
    ));
}

function seedPanel(): void
{
    config()->set('webterm.enabled', true);
    Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => 'database', 'host_key_policy' => Target::POLICY_PIN, 'principal' => 'netops',
    ]);
    HostKey::create([
        'device_id' => 42, 'algorithm' => 'ssh-ed25519', 'public_key' => 'AAAA',
        'fingerprint' => 'SHA256:x', 'status' => HostKey::PINNED,
    ]);
    Ability::create(['user_id' => 7, 'ability' => Ability::USE]);
    Grant::create([
        'subject_type' => Grant::SUBJECT_USER, 'subject_ref' => '7',
        'object_type' => Grant::OBJECT_DEVICE, 'object_id' => 42, 'effect' => Grant::ALLOW,
    ]);
}

it('offers a terminal when everything is in place', function () {
    seedPanel();

    expect(presenter()->present(new FakeUser(7), new FakeDevice(42))['state'])->toBe('ready');
});

it('renders nothing at all for a device with no target row', function () {
    // Most devices in an estate will never be terminal-enabled. A permanent
    // "not configured" box on every device page would be noise.
    config()->set('webterm.enabled', true);

    expect(presenter()->present(new FakeUser(7), new FakeDevice(99))['state'])->toBe('hidden');
});

it('reveals nothing to a user who cannot see the device', function () {
    // An explanation would itself confirm the device exists.
    seedPanel();

    $panel = presenter(FakeVisibility::none())->present(new FakeUser(7), new FakeDevice(42));

    expect($panel['state'])->toBe('hidden')
        ->and($panel['message'])->toBe('')
        ->and($panel['reason'])->toBeNull();
});

it('treats a pending step-up as a prompt, not a refusal', function () {
    // Showing "denied" would send the user to an administrator who has nothing
    // to fix; the user simply has to confirm their identity.
    seedPanel();

    $panel = presenter(null, FakeStepUp::pending())->present(new FakeUser(7), new FakeDevice(42));

    expect($panel['state'])->toBe('ready')
        ->and($panel['reason'])->toBe('step_up_required');
});

it('explains an actionable refusal', function () {
    seedPanel();
    Grant::query()->delete();

    $panel = presenter()->present(new FakeUser(7), new FakeDevice(42));

    expect($panel['state'])->toBe('denied')
        ->and($panel['message'])->toContain('not been granted');
});

it('never makes a network call while rendering', function () {
    // This runs on every device page load for every user. A synchronous call
    // to a down gateway would stall the whole page.
    seedPanel();
    config()->set('webterm.gateway.url', 'http://127.0.0.1:1');

    $started = microtime(true);
    $panel = presenter()->present(new FakeUser(7), new FakeDevice(42));
    $elapsed = (microtime(true) - $started) * 1000;

    expect($panel['state'])->toBe('ready')
        ->and($elapsed)->toBeLessThan(200.0);
});

it('degrades to unavailable rather than throwing', function () {
    // A thrown exception here would make LibreNMS disable the whole plugin.
    expect(presenter()->present(null, new FakeDevice(42))['state'])->toBe('unavailable');
});
