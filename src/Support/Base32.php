<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Support;

/**
 * RFC 4648 Base32, decode only.
 *
 * Implemented here rather than pulled from Packagist because our runtime
 * `require` is capped at php and librenms/plugin-interfaces: every additional
 * dependency is a chance for `lnms plugin:add` to fail against LibreNMS's own
 * lockfile. This is thirty lines and covered by the RFC test vectors.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @return string|null Raw bytes, or null when the input is not valid Base32.
     */
    public static function decode(string $encoded): ?string
    {
        // Authenticator apps and LibreNMS both emit unpadded, and users paste
        // secrets with spaces and mixed case.
        $encoded = strtoupper(str_replace([' ', '-', '='], '', $encoded));

        if ($encoded === '') {
            return null;
        }

        $bits = '';
        for ($i = 0, $len = strlen($encoded); $i < $len; $i++) {
            $index = strpos(self::ALPHABET, $encoded[$i]);
            if ($index === false) {
                return null;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
            // A trailing partial group is Base32 padding, not data. Discard it.
        }

        return $bytes === '' ? null : $bytes;
    }
}
