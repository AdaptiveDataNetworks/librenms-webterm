<?php

declare(strict_types=1);

namespace Adn\WebTerm\Librenms;

use Adn\WebTerm\Authorization\Contracts\GroupSource;
use Adn\WebTerm\Support\Guard;

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
                    // LibreNMS marks rule-driven groups with a non-empty rules
                    // payload; anything else is a hand-curated static group.
                    $rules = $group->rules ?? null;
                    $isDynamic = is_array($rules) ? $rules !== [] : ! empty($rules);

                    if ($isDynamic) {
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
