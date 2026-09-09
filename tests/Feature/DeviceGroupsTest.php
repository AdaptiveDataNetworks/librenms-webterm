<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Librenms\DeviceGroups;

/*
 * A Feature test, not a Unit one, because the adapter reads config() to decide
 * whether dynamic groups are refused -- and tests/Unit has no Laravel
 * application (tests/Pest.php binds the Testbench TestCase to Feature only).
 *
 * That mattered: as a Unit test the dynamic-group case passed VACUOUSLY.
 * config() threw a BindingResolutionException, Guard::safely swallowed it and
 * returned the empty fallback, and the assertion "no dynamic groups" was
 * satisfied by the failure rather than by the behaviour.
 */

/**
 * Stands in for App\Models\DeviceGroup. Only the surface the adapter touches.
 */
final class FakeDeviceGroup
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $type = null,
        public readonly mixed $rules = null,
    ) {}
}

/** Stands in for App\Models\Device, exposing groups()->get(). */
final class FakeGroupedDevice
{
    /** @param list<FakeDeviceGroup> $groups */
    public function __construct(private readonly array $groups) {}

    public function groups(): self
    {
        return $this;
    }

    /** @return list<FakeDeviceGroup> */
    public function get(): array
    {
        return $this->groups;
    }
}

/**
 * A static group created in the LibreNMS web UI is stored with a NON-EMPTY
 * rules payload: DeviceGroup::saving() rewrites `rules` whenever that attribute
 * is dirty, and DeviceGroupController sets it unconditionally before branching
 * on type. Classifying on rules-emptiness therefore called every such group
 * dynamic and skipped it -- silently disabling group grants and group-scoped
 * credentials for exactly the groups operators actually create.
 */
it('treats a UI-created static group as static despite its rules payload', function (): void {
    $device = new FakeGroupedDevice([
        new FakeDeviceGroup(id: 7, type: 'static', rules: ['joins' => []]),
    ]);

    expect((new DeviceGroups)->staticGroupIdsFor($device))->toBe([7]);
});

it('still excludes genuinely dynamic groups', function (): void {
    // Membership is recomputed on every poll, so a rule-driven group must never
    // widen access without a human deciding it should.
    $device = new FakeGroupedDevice([
        new FakeDeviceGroup(id: 8, type: 'dynamic', rules: ['joins' => [['devices', 'ports']]]),
    ]);

    expect((new DeviceGroups)->staticGroupIdsFor($device))->toBe([]);
});

it('keeps both kinds apart when a device is in each', function (): void {
    $device = new FakeGroupedDevice([
        new FakeDeviceGroup(id: 3, type: 'static', rules: ['joins' => []]),
        new FakeDeviceGroup(id: 4, type: 'dynamic', rules: ['joins' => []]),
        new FakeDeviceGroup(id: 5, type: 'static', rules: []),
    ]);

    expect((new DeviceGroups)->staticGroupIdsFor($device))->toBe([3, 5]);
});

it('falls back to the rules heuristic only when type is absent', function (): void {
    // Defensive, for a core predating the column. Absent type plus empty rules
    // is the only case where the old guess is still used.
    $device = new FakeGroupedDevice([
        new FakeDeviceGroup(id: 9, type: null, rules: []),
        new FakeDeviceGroup(id: 10, type: null, rules: ['joins' => [['a']]]),
    ]);

    expect((new DeviceGroups)->staticGroupIdsFor($device))->toBe([9]);
});

it('includes dynamic groups when the operator has deliberately allowed them', function (): void {
    // The refusal is a default, not a prohibition -- it is the operator's
    // fleet. Materialising is what makes allowing it defensible: enabling a
    // dynamic group captures its members at that moment rather than creating a
    // standing rule, so a device joining later still gains nothing.
    config()->set('webterm.security.refuse_dynamic_groups', false);

    $device = new FakeGroupedDevice([
        new FakeDeviceGroup(id: 3, type: 'static', rules: ['joins' => []]),
        new FakeDeviceGroup(id: 4, type: 'dynamic', rules: ['joins' => [['a']]]),
    ]);

    expect((new DeviceGroups)->staticGroupIdsFor($device))->toBe([3, 4]);
});

it('excludes dynamic groups again as soon as the refusal is back on', function (): void {
    config()->set('webterm.security.refuse_dynamic_groups', true);

    $device = new FakeGroupedDevice([
        new FakeDeviceGroup(id: 3, type: 'static', rules: ['joins' => []]),
        new FakeDeviceGroup(id: 4, type: 'dynamic', rules: ['joins' => [['a']]]),
    ]);

    expect((new DeviceGroups)->staticGroupIdsFor($device))->toBe([3]);
});
