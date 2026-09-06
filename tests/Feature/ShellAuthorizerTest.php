<?php

declare(strict_types=1);

use Adn\WebTerm\Authorization\GrantRepository;
use Adn\WebTerm\Authorization\ReasonCode;
use Adn\WebTerm\Authorization\ShellAuthorizer;
use Adn\WebTerm\Librenms\DeviceTarget;
use Adn\WebTerm\Models\Ability;
use Adn\WebTerm\Models\Grant;
use Adn\WebTerm\Models\HostKey;
use Adn\WebTerm\Models\Session;
use Adn\WebTerm\Models\Target;
use Adn\WebTerm\Tests\Support\FakeDevice;
use Adn\WebTerm\Tests\Support\FakeGroups;
use Adn\WebTerm\Tests\Support\FakeRoles;
use Adn\WebTerm\Tests\Support\FakeStepUp;
use Adn\WebTerm\Tests\Support\FakeUser;
use Adn\WebTerm\Tests\Support\FakeVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| One test per ReasonCode.
|
| Each starts from a fully-permitted baseline and removes exactly one thing, so
| a test failing tells you which control stopped working -- and so a control
| that silently stops being reachable shows up as a test that can no longer be
| made to fail.
*/

const DEVICE_ID = 42;
const USER_ID = 7;

function device(): FakeDevice
{
    return new FakeDevice(DEVICE_ID, ip: '10.0.0.1');
}

function user(array $roles = []): FakeUser
{
    return new FakeUser(USER_ID, $roles);
}

/** Everything in place for a successful admit(). */
function seedHappyPath(): void
{
    config()->set('webterm.enabled', true);

    Target::create([
        'device_id' => DEVICE_ID,
        'protocol' => 'ssh',
        'enabled' => true,
        'flow' => 'database',
        'host_key_policy' => Target::POLICY_PIN,
        'principal' => 'netops',
    ]);

    HostKey::create([
        'device_id' => DEVICE_ID,
        'algorithm' => 'ssh-ed25519',
        'public_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAI',
        'fingerprint' => 'SHA256:test',
        'status' => HostKey::PINNED,
    ]);

    Ability::create(['user_id' => USER_ID, 'ability' => Ability::USE]);

    Grant::create([
        'subject_type' => Grant::SUBJECT_USER,
        'subject_ref' => (string) USER_ID,
        'object_type' => Grant::OBJECT_DEVICE,
        'object_id' => DEVICE_ID,
        'effect' => Grant::ALLOW,
    ]);
}

function authorizer(?FakeVisibility $visibility = null, ?FakeStepUp $stepUp = null): ShellAuthorizer
{
    return new ShellAuthorizer(
        $visibility ?? FakeVisibility::all(),
        new DeviceTarget,
        new GrantRepository(new FakeRoles, new FakeGroups),
        $stepUp ?? FakeStepUp::notRequired(),
    );
}

it('allows a fully configured user, device and grant', function () {
    seedHappyPath();

    $decision = authorizer()->admit(user(), device());

    expect($decision->allowed)->toBeTrue()
        ->and($decision->reason)->toBe(ReasonCode::Allowed)
        ->and($decision->limits)->not->toBeNull();
});

it('refuses when the global kill switch is off', function () {
    seedHappyPath();
    config()->set('webterm.enabled', false);

    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::KillSwitch);
});

it('refuses when the user cannot see the device', function () {
    seedHappyPath();

    expect(authorizer(FakeVisibility::none())->admit(user(), device())->reason)
        ->toBe(ReasonCode::DeviceNotVisible);
});

it('refuses when the user lacks the use ability', function () {
    seedHappyPath();
    Ability::query()->delete();

    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::MissingAbility);
});

it('refuses when the device is not an enabled target', function () {
    seedHappyPath();
    Target::query()->update(['enabled' => false]);

    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::TargetNotEnabled);
});

it('refuses when there is no grant', function () {
    seedHappyPath();
    Grant::query()->delete();

    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::NoGrant);
});

it('refuses when a deny grant matches', function () {
    seedHappyPath();
    Grant::create([
        'subject_type' => Grant::SUBJECT_USER,
        'subject_ref' => (string) USER_ID,
        'object_type' => Grant::OBJECT_DEVICE,
        'object_id' => DEVICE_ID,
        'effect' => Grant::DENY,
    ]);

    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::ExplicitDeny);
});

it('distinguishes a grant that has expired from one that has not started', function () {
    seedHappyPath();

    Grant::query()->update(['ends_at' => now()->subHour()]);
    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::GrantExpired);

    Grant::query()->update(['ends_at' => null, 'starts_at' => now()->addHour()]);
    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::GrantNotYetActive);
});

it('refuses when the device has no usable IP for the gateway to dial', function () {
    seedHappyPath();

    $noIp = new FakeDevice(DEVICE_ID, ip: null, hostname: 'core-sw-01.example.com');
    $decision = authorizer()->admit(user(), $noIp);

    expect($decision->reason)->toBe(ReasonCode::TargetUnresolvable)
        ->and($decision->message())->toContain('does not resolve DNS');
});

it('refuses when no SSH principal is configured', function () {
    seedHappyPath();
    Target::query()->update(['principal' => '']);

    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::NoPrincipal);
});

it('refuses when the host key is unpinned and policy forbids trust-on-first-use', function () {
    seedHappyPath();
    HostKey::query()->delete();

    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::HostKeyNotPinned);
});

it('allows an unpinned host key when the target opts into first-connect TOFU', function () {
    seedHappyPath();
    HostKey::query()->delete();
    Target::query()->update(['host_key_policy' => Target::POLICY_TOFU]);

    expect(authorizer()->admit(user(), device())->allowed)->toBeTrue();
});

it('refuses when the user is at their concurrent session limit', function () {
    seedHappyPath();
    config()->set('webterm.session.max_concurrent_per_user', 2);

    foreach (['01AAAAAAAAAAAAAAAAAAAAAAAA', '01BBBBBBBBBBBBBBBBBBBBBBBB'] as $id) {
        Session::create([
            'session_id' => $id,
            'user_id' => USER_ID,
            'device_id' => DEVICE_ID,
            'state' => Session::ACTIVE,
            'method' => 'password',
        ]);
    }

    expect(authorizer()->admit(user(), device())->reason)->toBe(ReasonCode::ConcurrencyLimit);
});

it('refuses when step-up is required and not yet satisfied', function () {
    seedHappyPath();

    expect(authorizer(null, FakeStepUp::pending())->admit(user(), device())->reason)
        ->toBe(ReasonCode::StepUpRequired)
        ->and(authorizer(null, FakeStepUp::satisfied())->admit(user(), device())->allowed)
        ->toBeTrue();
});

it('does not re-apply concurrency or step-up when sustaining a live session', function () {
    // sustain() runs every 15s for a session that is already counted. Applying
    // the concurrency cap there would kill the newest session on every poll,
    // and re-challenging step-up would make a live terminal unusable.
    seedHappyPath();
    config()->set('webterm.session.max_concurrent_per_user', 1);

    Session::create([
        'session_id' => '01CCCCCCCCCCCCCCCCCCCCCCCC',
        'user_id' => USER_ID,
        'device_id' => DEVICE_ID,
        'state' => Session::ACTIVE,
        'method' => 'password',
    ]);

    expect(authorizer(null, FakeStepUp::pending())->sustain(user(), device())->allowed)->toBeTrue();
});

it('revokes a live session when its grant is removed', function () {
    seedHappyPath();
    expect(authorizer()->sustain(user(), device())->allowed)->toBeTrue();

    Grant::query()->delete();

    expect(authorizer()->sustain(user(), device())->allowed)->toBeFalse();
});

it('offers a remediation command for every administrator-resolvable refusal', function () {
    foreach (ReasonCode::cases() as $reason) {
        if ($reason->isAllowed() || $reason->isSelfResolvable()) {
            continue;
        }

        expect($reason->remediation())
            ->not->toBeNull("ReasonCode::{$reason->name} has no remediation guidance");
    }
});
