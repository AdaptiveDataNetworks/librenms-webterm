<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console\Concerns;

use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;

/**
 * Turns --device / --group / --global into a scope, or explains why it cannot.
 *
 * Exactly one must be given. Defaulting to anything would be wrong in both
 * directions: silently defaulting to --global writes a fleet-wide secret when
 * the operator meant one device, and silently defaulting to a device makes
 * --global look ignored.
 */
trait ResolvesCredentialScope
{
    use ResolvesDevices;

    /**
     * @return array{CredentialScope, int|null, string}|null scope, ref, label
     */
    protected function credentialScope(): ?array
    {
        $device = (string) ($this->option('device') ?? '');
        $group = (string) ($this->option('group') ?? '');
        $global = (bool) $this->option('global');

        $given = array_filter([$device !== '', $group !== '', $global]);

        if (count($given) === 0) {
            $this->error('Give exactly one of --device, --group or --global.');

            return null;
        }

        if (count($given) > 1) {
            $this->error('--device, --group and --global are mutually exclusive.');

            return null;
        }

        if ($global) {
            return [CredentialScope::Global, CredentialScope::UNTARGETED, 'the global default'];
        }

        if ($group !== '') {
            if (! ctype_digit($group)) {
                $this->error('--group takes a numeric LibreNMS device group id.');

                return null;
            }

            return [CredentialScope::Group, (int) $group, sprintf('device group %d', (int) $group)];
        }

        $resolved = $this->findDevice($device);

        // A credential can outlive its device, so a bare numeric id is accepted
        // even when the device record is gone -- otherwise an orphaned row
        // could never be addressed.
        if ($resolved === null && ! ctype_digit($device)) {
            $this->error(sprintf('No such device: %s', $device));

            return null;
        }

        $deviceId = $resolved !== null ? (int) $resolved->device_id : (int) $device;
        $label = $resolved !== null ? (string) ($resolved->hostname ?? $deviceId) : sprintf('device %d', $deviceId);

        return [CredentialScope::Device, $deviceId, $label];
    }
}
