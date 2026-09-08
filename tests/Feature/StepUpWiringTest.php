<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Authorization\AlwaysChallengeStepUp;
use AdaptiveDataNetworks\WebTerm\Authorization\GrantRepository;
use AdaptiveDataNetworks\WebTerm\Authorization\ReasonCode;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
use AdaptiveDataNetworks\WebTerm\Authorization\StepUpGate;
use AdaptiveDataNetworks\WebTerm\Authorization\TotpStepUp;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTarget;
use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\StepUp;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeDevice;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeGroups;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeRoles;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Every other step-up test injects a gate. Nothing covered the gate you get
 * when you inject nothing -- which is what every real install uses, and which
 * was AlwaysChallengeStepUp: satisfied by nothing. With step_up defaulting to
 * true, that denied every session mint on every install, forever.
 */
function wiringUser(): FakeUser
{
    return new FakeUser(7);
}

function wiringDevice(): FakeDevice
{
    return new FakeDevice(42, ip: '10.0.0.1');
}

function seedForStepUp(): void
{
    config()->set('webterm.enabled', true);

    Target::create([
        'device_id' => 42,
        'protocol' => 'ssh',
        'enabled' => true,
        'flow' => 'database',
        'host_key_policy' => Target::POLICY_PIN,
        'principal' => 'netops',
    ]);

    HostKey::create([
        'device_id' => 42,
        'algorithm' => 'ssh-ed25519',
        'public_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAI',
        'fingerprint' => 'SHA256:test',
        'status' => HostKey::PINNED,
    ]);

    Ability::create(['user_id' => 7, 'ability' => Ability::USE]);

    Grant::create([
        'subject_type' => Grant::SUBJECT_USER,
        'subject_ref' => '7',
        'object_type' => Grant::OBJECT_DEVICE,
        'object_id' => 42,
        'effect' => Grant::ALLOW,
    ]);
}

/** An authorizer with NO step-up gate injected -- exactly what production builds. */
function defaultAuthorizer(): ShellAuthorizer
{
    return new ShellAuthorizer(
        FakeVisibility::all(),
        new DeviceTarget,
        new GrantRepository(new FakeRoles, new FakeGroups),
    );
}

it('binds the real TOTP gate, not the deny-everything placeholder', function (): void {
    expect(app(StepUpGate::class))->toBeInstanceOf(TotpStepUp::class)
        ->and(app(StepUpGate::class))->not->toBeInstanceOf(AlwaysChallengeStepUp::class);
});

it('lets a user who has satisfied step-up open a session', function (): void {
    config()->set('webterm.security.step_up', true);
    seedForStepUp();

    StepUp::create([
        'user_id' => 7,
        'satisfied_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addMinutes(15),
        'absolute_expires_at' => Carbon::now()->addHours(8),
        'failures' => 0,
    ]);

    // Before the gate was bound this denied with StepUpRequired regardless of
    // anything the operator did -- the placeholder's isSatisfied() returns
    // false unconditionally.
    expect(defaultAuthorizer()->admit(wiringUser(), wiringDevice())->allowed)->toBeTrue();
});

it('still denies a user who has not satisfied step-up', function (): void {
    config()->set('webterm.security.step_up', true);
    seedForStepUp();

    $decision = defaultAuthorizer()->admit(wiringUser(), wiringDevice());

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe(ReasonCode::StepUpRequired);
});

it('does not challenge at all when step-up is turned off', function (): void {
    config()->set('webterm.security.step_up', false);
    seedForStepUp();

    expect(defaultAuthorizer()->admit(wiringUser(), wiringDevice())->allowed)->toBeTrue();
});
