<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\GroupMembers;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Session\GroupEnablement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const GROUP_SETTINGS = [
    'principal' => 'netops',
    'flow' => 'database',
    'host_key_policy' => 'pin',
    'algorithm_profile' => 'modern',
];

/** Answers membership without needing LibreNMS. */
function fakeGroups(?array $members): GroupMembers
{
    return new class($members) implements GroupMembers
    {
        public function __construct(private readonly ?array $members) {}

        public function staticMembersOf(int $groupId): ?array
        {
            return $this->members;
        }
    };
}

it('writes one target row per member device, tagged with the group', function (): void {
    $result = (new GroupEnablement(fakeGroups([11, 12, 13])))->apply(7, GROUP_SETTINGS);

    expect($result['ok'])->toBeTrue()
        ->and($result['created'])->toBe(3)
        ->and(Target::query()->count())->toBe(3);

    $target = Target::query()->where('device_id', 12)->firstOrFail();

    expect($target->source)->toBe(Target::SOURCE_GROUP)
        ->and($target->source_ref)->toBe(7)
        ->and((bool) $target->enabled)->toBeTrue()
        ->and($target->principal)->toBe('netops');
});

it('refuses a dynamic group outright rather than enabling nothing quietly', function (): void {
    // LibreNMS recomputes dynamic membership on every poll, so honouring one
    // would let a device gain shell access because discovery re-detected its
    // OS. An empty result would read as "that group is empty" instead.
    $result = (new GroupEnablement(fakeGroups(null)))->apply(9, GROUP_SETTINGS);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('every poll')
        ->and(Target::query()->count())->toBe(0);
});

it('does not overwrite a device somebody configured by hand', function (): void {
    Target::create([
        'device_id' => 11, 'protocol' => 'ssh', 'enabled' => true, 'flow' => 'database',
        'host_key_policy' => 'pin', 'algorithm_profile' => 'legacy', 'principal' => 'special-account',
        'source' => Target::SOURCE_MANUAL, 'source_ref' => 0,
    ]);

    $result = (new GroupEnablement(fakeGroups([11, 12])))->apply(7, GROUP_SETTINGS);

    expect($result['created'])->toBe(1);

    $manual = Target::query()->where('device_id', 11)->firstOrFail();

    // A bulk action does not overrule a decision made about a specific device.
    expect($manual->principal)->toBe('special-account')
        ->and($manual->algorithm_profile)->toBe('legacy')
        ->and($manual->source)->toBe(Target::SOURCE_MANUAL);
});

it('is idempotent -- re-applying a group changes nothing', function (): void {
    $enabler = new GroupEnablement(fakeGroups([11, 12]));

    $enabler->apply(7, GROUP_SETTINGS);
    $second = $enabler->apply(7, GROUP_SETTINGS);

    expect(Target::query()->count())->toBe(2)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(2);
});

it('leaves authorization reading exactly one row per device', function (): void {
    // The whole point of materialising: admit() never consults group
    // membership, so a device joining a group later cannot grant access.
    (new GroupEnablement(fakeGroups([11])))->apply(7, GROUP_SETTINGS);

    expect(Target::query()->where('device_id', 11)->where('protocol', 'ssh')->count())->toBe(1);
});
