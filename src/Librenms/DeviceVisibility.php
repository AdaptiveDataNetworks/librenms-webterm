<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Librenms;

use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\VisibilityCheck;
use AdaptiveDataNetworks\WebTerm\Support\Guard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Answers "may this user see this device at all?" using LibreNMS's own rules.
 *
 * This is a NECESSARY condition for opening a shell, never a sufficient one.
 * LibreNMS's DevicePolicy::view() returns true for any user holding the
 * 'global-read' permission and, via ChecksGlobalPermissions, for any user with
 * the plain 'user' role on their assigned devices. Treating that as permission
 * to open a shell would hand a terminal to every read-only account.
 *
 * ShellAuthorizer therefore calls this as one gate among several, and the
 * property test asserts the invariant in the safe direction: anything admit()
 * allows, this must also allow.
 */
final class DeviceVisibility implements CoreDependency, VisibilityCheck
{
    public static function coreSymbols(): array
    {
        return [
            'App\Models\Device',
            'App\Policies\DevicePolicy::view',
            'App\Facades\Permissions',
            'LibreNMS\Cache\PermissionsCache::canAccessDevice',
        ];
    }

    /**
     * Fails closed: any unexpected shape, missing policy or thrown exception
     * denies. A visibility check that errors open is a vulnerability.
     *
     * @param  object  $device  A LibreNMS App\Models\Device.
     */
    public function canView(Authenticatable $user, object $device): bool
    {
        return Guard::safely(
            static function () use ($user, $device): bool {
                $decision = Gate::forUser($user)->inspect('view', $device);

                return $decision->allowed();
            },
            false,
            'DeviceVisibility::canView'
        );
    }
}
