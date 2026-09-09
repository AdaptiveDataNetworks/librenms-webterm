<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Librenms;

use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\GroupMembers;
use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\GroupSource;
use AdaptiveDataNetworks\WebTerm\Support\Guard;

/**
 * Reads device-group membership, so a grant can be written against a group
 * rather than device by device.
 *
 * Only STATIC groups are honoured. LibreNMS also supports dynamic groups whose
 * membership is a rule evaluated against device attributes -- which means a
 * device can silently join a group, and therefore silently acquire shell
 * access, because someone edited its sysLocation. Access to a shell should
 * change when a human changes an access rule, not as a side effect of
 * discovery. Dynamic groups are ignored, and the admin UI says so.
 */
final class DeviceGroups implements CoreDependency, GroupMembers, GroupSource
{
    public static function coreSymbols(): array
    {
        return [
            'App\Models\DeviceGroup',
            'App\Models\Device::groups',
            // DeviceGroup's `type` column is depended on too, but the contract
            // suite asserts symbols with method_exists() and has no database in
            // the integration job, so a column cannot be declared here without
            // failing that job. The dependency degrades safely instead: an
            // absent `type` falls back to the rules heuristic below.
        ];
    }

    /**
     * Device ids in a STATIC group.
     *
     * Dynamic groups are refused outright rather than returned empty, because
     * the caller materialises access from this and a silent empty result would
     * read as "that group has no devices" instead of "that group is not
     * something you may enable". LibreNMS recomputes dynamic membership on
     * every poll (DevicePolled -> UpdateDeviceGroups -> sync), so honouring one
     * would let a device gain shell reachability because discovery re-detected
     * its OS.
     *
     * @return list<int>|null null when the group is missing or dynamic
     */
    public function staticMembersOf(int $groupId): ?array
    {
        return Guard::safely(
            static function () use ($groupId): ?array {
                $model = 'App\Models\DeviceGroup';

                if (! class_exists($model)) {
                    return null;
                }

                $group = $model::query()->find($groupId);

                if ($group === null) {
                    return null;
                }

                if (! self::eligible($group)) {
                    return null;
                }

                $ids = [];

                foreach ($group->devices()->get() as $device) {
                    $id = (int) ($device->device_id ?? 0);

                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }

                return array_values(array_unique($ids));
            },
            null,
            'DeviceGroups::staticMembersOf'
        );
    }

    /**
     * Whether a group may confer WebTerm access.
     *
     * One implementation for both lookups. It was two, and they had already
     * drifted -- one honoured the dynamic-groups setting and the other did not,
     * so allowing dynamic groups worked for enabling a group and silently did
     * nothing for grants and credentials.
     *
     * Discriminates on `type`, which is what LibreNMS itself uses
     * (UpdateDeviceGroupsAction and DeviceGroup both test type == 'dynamic').
     * It once guessed from `rules` being empty, which is wrong for exactly the
     * groups operators create: DeviceGroup::saving() rewrites `rules` whenever
     * that attribute is dirty and DeviceGroupController sets it unconditionally
     * before branching on type, so a STATIC group made in the web UI is stored
     * with rules = {"joins":[]}. Every such group was classified dynamic and
     * skipped.
     *
     * Dynamic groups are refused by default because LibreNMS recomputes their
     * membership on every poll, so a device could gain terminal access because
     * discovery re-detected its OS. An operator may allow them anyway --
     * materialising means enabling one captures a snapshot, not a standing
     * rule.
     */
    private static function eligible(object $group): bool
    {
        $type = $group->type ?? null;

        if (is_string($type) && $type !== '') {
            return $type === 'static' || ! self::refusesDynamic();
        }

        // Only for a core predating the column.
        $rules = $group->rules ?? null;
        $looksDynamic = is_array($rules) ? $rules !== [] : ! empty($rules);

        return ! $looksDynamic || ! self::refusesDynamic();
    }

    private static function refusesDynamic(): bool
    {
        return (bool) config('webterm.security.refuse_dynamic_groups', true);
    }

    /**
     * Static group ids containing this device.
     *
     * @param  object  $device  A LibreNMS App\Models\Device.
     * @return list<int>
     */
    public function staticGroupIdsFor(object $device): array
    {
        return Guard::safely(
            static function () use ($device): array {
                if (! method_exists($device, 'groups')) {
                    return [];
                }

                $ids = [];
                foreach ($device->groups()->get() as $group) {
                    if (! self::eligible($group)) {
                        continue;
                    }

                    $id = $group->id ?? null;
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $ids[] = (int) $id;
                    }
                }

                return array_values(array_unique($ids));
            },
            [],
            'DeviceGroups::staticGroupIdsFor'
        );
    }
}
