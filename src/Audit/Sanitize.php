<?php

declare(strict_types=1);

namespace Adn\WebTerm\Audit;

/**
 * The single chokepoint for text entering the audit trail.
 *
 * Audit records carry attacker-influenced strings: hostnames, usernames,
 * SSH banner text, error messages from the far end. Written raw, a terminal
 * escape sequence in any of those can rewrite what an operator sees when they
 * `cat` the log -- moving the cursor, clearing the line, or colouring a
 * denial to look like a success. Log files are read in terminals, so the
 * terminal is part of the threat model.
 *
 * Deliberately no ext-intl: normalising Unicode would be nice, but adding a
 * required extension to a plugin distributed by `lnms plugin:add` is a support
 * burden out of proportion to the benefit.
 */
final class Sanitize
{
    public const MAX_LENGTH = 1024;

    public static function text(?string $value, int $maxLength = self::MAX_LENGTH): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Strip CSI / OSC escape sequences first, so their payload does not
        // survive as stray text once the introducer is removed.
        $value = (string) preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $value);
        $value = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $value);
        $value = (string) preg_replace('/\x1B[@-_]/', '', $value);

        // Then every remaining C0/C1 control character, including the bare
        // ESC, newlines and carriage returns: one record is one line.
        $value = (string) preg_replace('/[\x00-\x1F\x7F-\x9F]/u', ' ', $value)
            ?: (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);

        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength - 1).'…';
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function detail(array $detail): array
    {
        $clean = [];
        foreach ($detail as $key => $value) {
            $key = self::text((string) $key, 64);

            $clean[$key] = match (true) {
                is_string($value) => self::text($value),
                is_scalar($value), $value === null => $value,
                is_array($value) => self::detail($value),
                default => self::text(get_debug_type($value), 64),
            };
        }

        return $clean;
    }
}
