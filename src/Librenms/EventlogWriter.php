<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Librenms;

use AdaptiveDataNetworks\WebTerm\Audit\Severity;
use AdaptiveDataNetworks\WebTerm\Support\Guard;
use App\Models\Eventlog;

/**
 * Mirrors WebTerm events into LibreNMS's own eventlog.
 *
 * This is not the audit trail -- that is our own append-only table plus the
 * off-box syslog stream. This exists because an operator investigating a device
 * looks at that device's event list, and "someone opened a shell here at 03:12"
 * belongs where they are already looking. Duplication is the point.
 *
 * Writing here must never be able to fail a connection: the eventlog is a
 * convenience, and losing a mirror entry is preferable to refusing a session.
 * The authoritative record is written elsewhere, first.
 */
final class EventlogWriter implements CoreDependency
{
    public const EVENT_TYPE = 'webterm';

    public static function coreSymbols(): array
    {
        return [
            'App\Models\Eventlog::log',
            'LibreNMS\Enum\Severity',
        ];
    }

    /**
     * @param  object|int|null  $device  A LibreNMS App\Models\Device, its id, or null.
     */
    public function write(string $message, object|int|null $device = null, Severity $severity = Severity::Info): void
    {
        Guard::safely(
            static function () use ($message, $device, $severity): bool {
                if (! class_exists(Eventlog::class)
                    || ! enum_exists(\LibreNMS\Enum\Severity::class)) {
                    return false;
                }

                // Our enum values are deliberately identical to LibreNMS's, so
                // this is a total mapping; from() would only throw if core
                // renumbered its cases, which the contract test would catch.
                $coreSeverity = \LibreNMS\Enum\Severity::from($severity->value);

                Eventlog::log($message, $device, self::EVENT_TYPE, $coreSeverity);

                return true;
            },
            false,
            'EventlogWriter::write'
        );
    }
}
