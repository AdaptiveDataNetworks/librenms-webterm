<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Session;

use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\GroupMembers;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceGroups;
use AdaptiveDataNetworks\WebTerm\Models\Target;

/**
 * Enable a whole device group for terminal access.
 *
 * This MATERIALISES: it writes one target row per member device, tagged with
 * the group it came from. It does not make authorization consult group
 * membership, and that distinction is the entire point.
 *
 * Resolving membership at authorization time would mean a device becomes
 * shell-reachable the moment it joins a group. LibreNMS recomputes dynamic
 * group membership on EVERY poll -- DevicePolled fires UpdateDeviceGroups,
 * which syncs the pivot -- so a device could gain access because discovery
 * re-detected its OS or somebody edited a sysLocation, with nobody deciding
 * anything. Even for static groups, editing the member list needs only core's
 * device-group update permission, which has nothing to do with WebTerm's admin
 * ability: a resolved default would let a device-group editor hand out shell
 * access.
 *
 * Materialising is what makes allowing dynamic groups a defensible OPTION
 * rather than a trap. With security.refuse_dynamic_groups turned off, enabling
 * a dynamic group writes rows for the devices in it AT THAT MOMENT -- an
 * operator's snapshot, not a standing rule. A device joining the group later
 * still gains nothing until somebody re-applies it.
 *
 * Materialising keeps the decision where an operator made it, keeps
 * "N devices enabled" a true device count, and leaves admit() reading exactly
 * one row keyed by device_id.
 */
final class GroupEnablement
{
    public function __construct(
        private readonly GroupMembers $groups = new DeviceGroups,
    ) {}

    /**
     * @param  array{principal: string, flow: string, host_key_policy: string, algorithm_profile: string}  $settings
     * @return array{ok: bool, created: int, updated: int, error: string}
     */
    public function apply(int $groupId, array $settings): array
    {
        $members = $this->groups->staticMembersOf($groupId);

        if ($members === null) {
            // The message depends on whether dynamic groups are refused, or
            // the operator has deliberately allowed them -- otherwise "no such
            // static group" is simply wrong for someone who turned that off.
            $error = (bool) config('webterm.security.refuse_dynamic_groups', true)
                ? 'No such static device group. Dynamic groups are refused by default: LibreNMS '
                    .'recomputes their membership on every poll, so a device could gain terminal '
                    .'access without anyone deciding it should. You can allow them in Settings '
                    .'(security.refuse_dynamic_groups) if that trade suits your fleet.'
                : 'No such device group.';

            return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => $error];
        }

        $created = 0;
        $updated = 0;

        foreach ($members as $deviceId) {
            $existing = Target::query()
                ->where('device_id', $deviceId)
                ->where('protocol', 'ssh')
                ->first();

            // A device somebody enabled by hand keeps its own settings. The
            // group is a bulk action, not an authority over choices already
            // made about a specific device.
            if ($existing !== null && $existing->source === Target::SOURCE_MANUAL) {
                continue;
            }

            Target::query()->updateOrCreate(
                ['device_id' => $deviceId, 'protocol' => 'ssh'],
                $settings + [
                    'enabled' => true,
                    'source' => Target::SOURCE_GROUP,
                    'source_ref' => $groupId,
                ]
            );

            $existing === null ? $created++ : $updated++;
        }

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'error' => ''];
    }
}
