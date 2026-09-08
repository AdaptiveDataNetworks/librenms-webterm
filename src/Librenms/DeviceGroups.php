<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Librenms;

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
final class DeviceGroups implements CoreDependency, GroupSource
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
                    // Discriminate on `type`, which is what LibreNMS itself
                    // uses (UpdateDeviceGroupsAction and DeviceGroup::…
                    // both test `type == 'dynamic'`).
                    //
                    // This previously guessed from the `rules` payload being
                    // empty, which is wrong and silently disabled the feature:
                    // DeviceGroup::saving() rewrites `rules` for any group whose
                    // rules attribute is dirty, and DeviceGroupController sets
                    // `rules` unconditionally before branching on type -- so a
                    // STATIC group created in the web UI is stored with
                    // rules = {"joins":[]}. Every such group was classified
                    // dynamic and skipped, so group grants and group-scoped
                    // credentials never matched a UI-created group at all.
                    $type = $group->type ?? null;

                    if (is_string($type) && $type !== '') {
                        if ($type !== 'static') {
                            continue;
                        }
                    } else {
                        // Only if a core predating the column ever turns up.
                        $rules = $group->rules ?? null;
                        if (is_array($rules) ? $rules !== [] : ! empty($rules)) {
                            continue;
                        }
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
