<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Support;

/**
 * Converts a stored setting string back into the type the config expects.
 *
 * Settings are stored as text because the table has to hold anything. The
 * config file, though, declares real types -- `enabled` is a bool, timeouts are
 * ints, allowed_origins is a list -- and code reads them expecting those types.
 * Without conversion, `enabled` would come back as the string "true", which is
 * truthy, and "false" would ALSO be truthy. That failure mode is silent and
 * exactly backwards from what an operator intended.
 */
final class SettingValue
{
    /**
     * @param  mixed  $current  The configured value, used to infer the target type.
     */
    public static function coerce(string $stored, mixed $current = null): mixed
    {
        $trimmed = trim($stored);

        return match (true) {
            // A list in the config means a comma-separated list in the table.
            is_array($current) => array_values(array_filter(
                array_map('trim', explode(',', $trimmed)),
                static fn (string $v): bool => $v !== ''
            )),
            strtolower($trimmed) === 'true' => true,
            strtolower($trimmed) === 'false' => false,
            strtolower($trimmed) === 'null' => null,
            is_int($current) && is_numeric($trimmed) => (int) $trimmed,
            is_numeric($trimmed) && ! is_string($current) => $trimmed + 0,
            default => $stored,
        };
    }
}
