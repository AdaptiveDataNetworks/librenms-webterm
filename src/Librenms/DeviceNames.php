<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Librenms;

use AdaptiveDataNetworks\WebTerm\Support\Guard;

/**
 * Turns device ids into something a human recognises.
 *
 * The admin console showed raw device ids, which nobody knows. Resolving them
 * one row at a time would be an N+1 across every table on every page, so this
 * batches: one query for however many ids a page needs.
 *
 * Core is named by string, never imported, which is the same idiom the rest of
 * src/Librenms uses -- a hard reference would be a phpstan class.notFound when
 * analysing without LibreNMS present.
 */
final class DeviceNames implements CoreDependency
{
    /** @return list<string> */
    public static function coreSymbols(): array
    {
        return [
            'App\Models\Device',
            'App\Models\Device::displayName',
        ];
    }

    /**
     * Hostnames for the given device ids, keyed by id.
     *
     * Ids with no device are simply absent: a credential or grant can outlive
     * the device it names, and the caller decides how to present that.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, string>
     */
    public function namesFor(array $deviceIds): array
    {
        $deviceIds = array_values(array_unique(array_filter($deviceIds, static fn (int $id): bool => $id > 0)));

        if ($deviceIds === []) {
            return [];
        }

        return Guard::safely(
            static function () use ($deviceIds): array {
                $model = 'App\Models\Device';

                if (! class_exists($model)) {
                    return [];
                }

                $names = [];

                foreach ($model::query()->whereIn('device_id', $deviceIds)->get() as $device) {
                    $id = (int) ($device->device_id ?? 0);

                    if ($id === 0) {
                        continue;
                    }

                    // displayName() honours the operator's hostname/sysName
                    // preference; falling back to hostname keeps this working
                    // if that method ever goes away.
                    $label = method_exists($device, 'displayName')
                        ? (string) $device->displayName()
                        : (string) ($device->hostname ?? '');

                    $names[$id] = $label !== '' ? $label : (string) $id;
                }

                return $names;
            },
            [],
            'DeviceNames::namesFor'
        );
    }

    /**
     * The LibreNMS device page for an id.
     *
     * Built from the path rather than route('device', ...) so a renamed route
     * degrades to a wrong link rather than an exception on a page that is
     * mostly about something else.
     */
    public function urlFor(int $deviceId): string
    {
        return url('device/'.$deviceId);
    }
}
