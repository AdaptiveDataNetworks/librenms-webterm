<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Authorization\GrantRepository;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
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

/*
| THE INVARIANT
|
|   admit(u, d) allowed  =>  canView(u, d) allowed
|
| Shell access must always be a strict subset of device visibility. If this ever
| inverts, a user can open a terminal on a device LibreNMS will not even show
| them -- the worst failure this plugin could have.
|
| Checked by brute force over an estate rather than by inspection, because the
| dangerous case is a future reordering of checks inside admit(), which reads
| perfectly reasonably at the diff level.
*/

it('never admits a user to a device they cannot see', function () {
    config()->set('webterm.enabled', true);

    $deviceIds = range(1, 20);
    $userIds = range(1, 10);
    $roles = [[], ['user'], ['global-read'], ['admin']];

    // Half the estate is visible; the rest must be unreachable no matter what
    // grants, abilities or targets say.
    $visibleIds = array_values(array_filter($deviceIds, fn (int $id): bool => $id % 2 === 0));
    $visibility = new FakeVisibility($visibleIds);

    // Deliberately over-permissive everywhere else: every device enabled and
    // pinned, every user holding 'use', and a broad set of grants. Visibility
    // is then the ONLY thing standing between a user and a shell.
    foreach ($deviceIds as $deviceId) {
        Target::create([
            'device_id' => $deviceId,
            'protocol' => 'ssh',
            'enabled' => true,
            'flow' => 'database',
            'host_key_policy' => Target::POLICY_PIN,
            'principal' => 'netops',
        ]);
        HostKey::create([
            'device_id' => $deviceId,
            'algorithm' => 'ssh-ed25519',
            'public_key' => 'AAAA',
            'fingerprint' => 'SHA256:'.$deviceId,
            'status' => HostKey::PINNED,
        ]);
    }

    foreach ($userIds as $userId) {
        Ability::create(['user_id' => $userId, 'ability' => Ability::USE]);

        foreach ($deviceIds as $deviceId) {
            Grant::create([
                'subject_type' => Grant::SUBJECT_USER,
                'subject_ref' => (string) $userId,
                'object_type' => Grant::OBJECT_DEVICE,
                'object_id' => $deviceId,
                'effect' => Grant::ALLOW,
            ]);
        }
    }

    $authorizer = new ShellAuthorizer(
        $visibility,
        new DeviceTarget,
        new GrantRepository(new FakeRoles, new FakeGroups),
        FakeStepUp::notRequired(),
    );

    $checked = 0;
    $allowed = 0;

    foreach ($userIds as $userId) {
        foreach ($roles as $roleSet) {
            $user = new FakeUser($userId, $roleSet);

            foreach ($deviceIds as $deviceId) {
                $device = new FakeDevice($deviceId, ip: '10.0.0.'.$deviceId);
                $decision = $authorizer->admit($user, $device);
                $checked++;

                if ($decision->allowed) {
                    $allowed++;
                    expect($visibility->canView($user, $device))->toBeTrue(
                        "INVARIANT VIOLATED: admit() allowed user {$userId} onto device {$deviceId}, "
                        .'which DeviceVisibility refuses.'
                    );
                }
            }
        }
    }

    // Guard against a vacuous pass: if nothing were ever allowed, the loop
    // above would assert nothing at all.
    expect($checked)->toBe(800)
        ->and($allowed)->toBeGreaterThan(0)
        ->and($allowed)->toBeLessThan($checked);
});

it('never sustains a session for a device the user can no longer see', function () {
    // The same invariant on the re-check path. A user losing device access in
    // LibreNMS must lose the live terminal too, not merely fail to open a new
    // one.
    config()->set('webterm.enabled', true);

    Target::create([
        'device_id' => 1, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => 'database', 'host_key_policy' => Target::POLICY_PIN, 'principal' => 'netops',
    ]);
    Ability::create(['user_id' => 1, 'ability' => Ability::USE]);
    Grant::create([
        'subject_type' => Grant::SUBJECT_USER, 'subject_ref' => '1',
        'object_type' => Grant::OBJECT_DEVICE, 'object_id' => 1, 'effect' => Grant::ALLOW,
    ]);

    $user = new FakeUser(1);
    $device = new FakeDevice(1);

    $visible = new ShellAuthorizer(
        FakeVisibility::all(), new DeviceTarget,
        new GrantRepository(new FakeRoles, new FakeGroups), FakeStepUp::notRequired(),
    );
    $invisible = new ShellAuthorizer(
        FakeVisibility::none(), new DeviceTarget,
        new GrantRepository(new FakeRoles, new FakeGroups), FakeStepUp::notRequired(),
    );

    expect($visible->sustain($user, $device)->allowed)->toBeTrue()
        ->and($invisible->sustain($user, $device)->allowed)->toBeFalse();
});
