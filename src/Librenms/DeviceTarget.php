<?php

declare(strict_types=1);

namespace Adn\WebTerm\Librenms;

use Adn\WebTerm\Support\Guard;
use Adn\WebTerm\Support\IpGuard;

/**
 * Resolves a LibreNMS device to the IP literal and port the gateway will dial.
 *
 * The gateway links no DNS resolver, so the address must be an IP literal by
 * the time it reaches the wire. That is a deliberate constraint: it means the
 * set of hosts the gateway can reach is decided here, in PHP, against
 * LibreNMS's own device record -- not by whatever a resolver happens to return
 * at connect time.
 *
 * Note that LibreNMS's own Device::pollerTarget() returns
 * `overwrite_ip ?: hostname`, and `hostname` is frequently a DNS name rather
 * than an address. We therefore cannot use pollerTarget() directly; we prefer
 * the resolved `ip` column, which the model stores packed (inet_pton) and
 * exposes through an accessor as a printable address.
 */
final class DeviceTarget implements CoreDependency
{
    public const DEFAULT_SSH_PORT = 22;

    public const PORT_ATTRIB = 'override_device_ssh_port';

    public static function coreSymbols(): array
    {
        return [
            'App\Models\Device',
            'App\Models\Device::pollerTarget',
            'App\Models\Device::getAttrib',
        ];
    }

    /**
     * @param  object  $device  A LibreNMS App\Models\Device.
     */
    public function resolve(object $device): ?Target
    {
        return Guard::safely(
            function () use ($device): ?Target {
                $address = $this->address($device);
                if ($address === null) {
                    return null;
                }

                $port = $this->port($device);
                if ($port === null) {
                    return null;
                }

                $reason = IpGuard::rejectionReason($address);
                if ($reason !== null) {
                    return null;
                }

                return new Target($address, $port);
            },
            null,
            'DeviceTarget::resolve'
        );
    }

    /**
     * Explain why a device cannot be targeted, for the admin UI and for
     * `webterm:why`. Returns null when the device is targetable.
     *
     * @param  object  $device  A LibreNMS App\Models\Device.
     */
    public function rejectionReason(object $device): ?string
    {
        return Guard::safely(
            function () use ($device): ?string {
                $address = $this->address($device);
                if ($address === null) {
                    return 'device has no usable IP address; the gateway does not resolve DNS, '
                        .'so set the device IP or an IP override in LibreNMS';
                }

                if ($this->port($device) === null) {
                    return 'device has an invalid '.self::PORT_ATTRIB.' attribute';
                }

                return IpGuard::rejectionReason($address);
            },
            'device target could not be evaluated',
            'DeviceTarget::rejectionReason'
        );
    }

    /**
     * Prefer, in order: the resolved `ip` column; an `overwrite_ip` that is
     * itself a literal; a `hostname` that is itself a literal. A hostname that
     * is a DNS name yields null -- we do not resolve it here, because a name
     * that resolves differently later is exactly the ambiguity this design
     * removes.
     */
    private function address(object $device): ?string
    {
        foreach (['ip', 'overwrite_ip', 'hostname'] as $property) {
            $value = $device->{$property} ?? null;
            if (is_string($value) && $value !== '' && IpGuard::isIpLiteral($value)) {
                return $value;
            }
        }

        return null;
    }

    private function port(object $device): ?int
    {
        $raw = method_exists($device, 'getAttrib')
            ? $device->getAttrib(self::PORT_ATTRIB)
            : null;

        if ($raw === null || $raw === '') {
            return self::DEFAULT_SSH_PORT;
        }

        if (! is_numeric($raw)) {
            return null;
        }

        $port = (int) $raw;

        return IpGuard::isValidPort($port) ? $port : null;
    }
}
