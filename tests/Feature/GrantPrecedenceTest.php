<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Authorization\EffectiveLimits;
use AdaptiveDataNetworks\WebTerm\Authorization\GrantRepository;
use AdaptiveDataNetworks\WebTerm\Authorization\ReasonCode;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeDevice;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeGroups;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeRoles;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
| Deny must beat allow in every combination.
|
| The rule matters operationally: an administrator revoking someone's access
| during an incident must be able to add one deny row and be done, without
| first finding every allow -- user, role, device and group -- that might match.
*/

function grant(string $subjectType, string $subjectRef, string $objectType, int $objectId, string $effect, array $extra = []): Grant
{
    return Grant::create(array_merge([
        'subject_type' => $subjectType,
        'subject_ref' => $subjectRef,
        'object_type' => $objectType,
        'object_id' => $objectId,
        'effect' => $effect,
    ], $extra));
}

function repo(array $groupMap = []): GrantRepository
{
    return new GrantRepository(new FakeRoles, new FakeGroups($groupMap));
}

function baseLimits(): EffectiveLimits
{
    return new EffectiveLimits(900, 14400, 3);
}

function decide(GrantRepository $repo, FakeUser $user, FakeDevice $device): ReasonCode
{
    return $repo->evaluate($repo->matching($user, $device), baseLimits(), Carbon::now())->reason;
}

it('lets deny beat allow across every subject and object combination', function (
    string $allowSubject, string $allowObject, string $denySubject, string $denyObject
) {
    $user = new FakeUser(1, ['netops']);
    $device = new FakeDevice(5);
    $groupMap = [5 => [9]];

    $ref = fn (string $type): string => $type === Grant::SUBJECT_USER ? '1' : 'netops';
    $obj = fn (string $type): int => $type === Grant::OBJECT_DEVICE ? 5 : 9;

    grant($allowSubject, $ref($allowSubject), $allowObject, $obj($allowObject), Grant::ALLOW);

    expect(decide(repo($groupMap), $user, $device))->toBe(ReasonCode::Allowed);

    grant($denySubject, $ref($denySubject), $denyObject, $obj($denyObject), Grant::DENY);

    expect(decide(repo($groupMap), $user, $device))->toBe(ReasonCode::ExplicitDeny);
})->with(function () {
    $subjects = [Grant::SUBJECT_USER, Grant::SUBJECT_ROLE];
    $objects = [Grant::OBJECT_DEVICE, Grant::OBJECT_GROUP];
    $cases = [];

    foreach ($subjects as $as) {
        foreach ($objects as $ao) {
            foreach ($subjects as $ds) {
                foreach ($objects as $do) {
                    $cases["allow {$as}/{$ao} vs deny {$ds}/{$do}"] = [$as, $ao, $ds, $do];
                }
            }
        }
    }

    return $cases;
});

it('ignores a deny that is outside its time window', function () {
    // A scheduled or lapsed deny is not a deny. Otherwise an expired
    // maintenance freeze would lock people out permanently.
    $user = new FakeUser(1);
    $device = new FakeDevice(5);

    grant(Grant::SUBJECT_USER, '1', Grant::OBJECT_DEVICE, 5, Grant::ALLOW);
    grant(Grant::SUBJECT_USER, '1', Grant::OBJECT_DEVICE, 5, Grant::DENY, [
        'ends_at' => Carbon::now()->subHour(),
    ]);

    expect(decide(repo(), $user, $device))->toBe(ReasonCode::Allowed);
});

it('honours a deny that is inside its window', function () {
    $user = new FakeUser(1);
    $device = new FakeDevice(5);

    grant(Grant::SUBJECT_USER, '1', Grant::OBJECT_DEVICE, 5, Grant::ALLOW);
    grant(Grant::SUBJECT_USER, '1', Grant::OBJECT_DEVICE, 5, Grant::DENY, [
        'starts_at' => Carbon::now()->subHour(),
        'ends_at' => Carbon::now()->addHour(),
    ]);

    expect(decide(repo(), $user, $device))->toBe(ReasonCode::ExplicitDeny);
});

it('does not match grants for other users, roles, devices or groups', function () {
    $user = new FakeUser(1, ['netops']);
    $device = new FakeDevice(5);

    grant(Grant::SUBJECT_USER, '2', Grant::OBJECT_DEVICE, 5, Grant::ALLOW);
    grant(Grant::SUBJECT_ROLE, 'helpdesk', Grant::OBJECT_DEVICE, 5, Grant::ALLOW);
    grant(Grant::SUBJECT_USER, '1', Grant::OBJECT_DEVICE, 6, Grant::ALLOW);
    grant(Grant::SUBJECT_USER, '1', Grant::OBJECT_GROUP, 99, Grant::ALLOW);

    expect(decide(repo([5 => [9]]), $user, $device))->toBe(ReasonCode::NoGrant);
});

it('intersects limits across matching allows rather than taking the loosest', function () {
    // Adding a second, broader grant must never relax a restriction an
    // administrator set deliberately on the first.
    $user = new FakeUser(1, ['netops']);
    $device = new FakeDevice(5);

    grant(Grant::SUBJECT_USER, '1', Grant::OBJECT_DEVICE, 5, Grant::ALLOW, [
        'max_duration' => 600, 'max_concurrent' => 1,
    ]);
    grant(Grant::SUBJECT_ROLE, 'netops', Grant::OBJECT_GROUP, 9, Grant::ALLOW, [
        'max_duration' => 7200, 'max_concurrent' => 5,
    ]);

    $r = repo([5 => [9]]);
    $decision = $r->evaluate($r->matching($user, $device), baseLimits(), Carbon::now());

    expect($decision->allowed)->toBeTrue()
        ->and($decision->limits?->maxDuration)->toBe(600)
        ->and($decision->limits?->maxConcurrent)->toBe(1);
});

it('treats the end of a window as exclusive and the start as inclusive', function () {
    $user = new FakeUser(1);
    $device = new FakeDevice(5);
    $now = Carbon::now();

    $g = grant(Grant::SUBJECT_USER, '1', Grant::OBJECT_DEVICE, 5, Grant::ALLOW, [
        'starts_at' => $now, 'ends_at' => $now->copy()->addHour(),
    ]);

    expect($g->isActiveAt($now))->toBeTrue()
        ->and($g->isActiveAt($now->copy()->addMinute()))->toBeTrue()
        ->and($g->isActiveAt($now->copy()->addHour()))->toBeFalse()
        ->and($g->isActiveAt($now->copy()->subSecond()))->toBeFalse();
});
