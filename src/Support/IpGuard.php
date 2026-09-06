<?php

declare(strict_types=1);

namespace Adn\WebTerm\Support;

/**
 * Validates the address the gateway will be told to dial.
 *
 * The gateway performs no DNS resolution -- it dials IP literals only -- which
 * means this class is the boundary that decides where the gateway may connect.
 * A mistake here turns the gateway into an SSRF pivot with access to whatever
 * the monitoring host can reach.
 *
 * Note what is deliberately NOT blocked: RFC 1918 and other private ranges.
 * Managed network equipment lives on private addresses; blocking them would
 * block the entire purpose of the plugin. What is blocked is the set of
 * addresses that can only be a mistake or an attack -- loopback (the gateway's
 * own host, including LibreNMS itself), link-local (including the cloud
 * metadata endpoint at 169.254.169.254), multicast, and unspecified.
 */
final class IpGuard
{
    /**
     * True when $candidate is a syntactically valid IPv4 or IPv6 literal.
     */
    public static function isIpLiteral(string $candidate): bool
    {
        return filter_var($candidate, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * True when the address is one the gateway may dial.
     */
    public static function isDialable(string $candidate): bool
    {
        return self::rejectionReason($candidate) === null;
    }

    /**
     * Returns a human-readable reason the address is refused, or null when it
     * is acceptable. Reasons are surfaced to administrators, so they name the
     * category rather than saying "invalid".
     */
    public static function rejectionReason(string $candidate): ?string
    {
        if ($candidate === '') {
            return 'address is empty';
        }

        if (! self::isIpLiteral($candidate)) {
            return 'not an IP literal (the gateway does not resolve DNS)';
        }

        $packed = @inet_pton($candidate);
        if ($packed === false) {
            return 'not an IP literal (the gateway does not resolve DNS)';
        }

        if (strlen($packed) === 16) {
            // The two IPv6 addresses that are themselves special must be judged
            // before any unmapping. ::1 begins with twelve zero bytes, so a
            // naive IPv4-compatible check rewrites loopback into 0.0.0.1 and
            // reports the wrong reason.
            if ($packed === str_repeat("\x00", 16)) {
                return 'unspecified address';
            }
            if ($packed === str_repeat("\x00", 15)."\x01") {
                return 'loopback address (the gateway would dial its own host)';
            }

            // Normalise IPv4-mapped and IPv4-compatible IPv6 (::ffff:127.0.0.1)
            // down to their IPv4 form. Without this, every IPv4 rule below could
            // be bypassed by expressing the same address in IPv6 notation.
            $mapped = self::unmapIpv4($packed);
            if ($mapped !== null) {
                return self::rejectionReason($mapped);
            }
        }

        return strlen($packed) === 4
            ? self::rejectIpv4($packed)
            : self::rejectIpv6($packed);
    }

    private static function unmapIpv4(string $packed): ?string
    {
        // ::ffff:a.b.c.d
        if (str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12)) ?: null;
        }

        // ::a.b.c.d (IPv4-compatible, deprecated but still parsed).
        // Only meaningful when the embedded address is outside 0.0.0.0/8;
        // ::1 and friends are IPv6 addresses in their own right, handled above.
        if (str_starts_with($packed, str_repeat("\x00", 12)) && ord($packed[12]) !== 0) {
            return inet_ntop(substr($packed, 12)) ?: null;
        }

        return null;
    }

    private static function rejectIpv4(string $packed): ?string
    {
        $b = array_values(unpack('C4', $packed));

        return match (true) {
            $b[0] === 0 => 'unspecified or "this network" address',
            $b[0] === 127 => 'loopback address (the gateway would dial its own host)',
            $b[0] === 169 && $b[1] === 254 => 'link-local address (includes cloud metadata endpoints)',
            $b[0] >= 224 && $b[0] <= 239 => 'multicast address',
            $b[0] >= 240 => 'reserved or broadcast address',
            default => null,
        };
    }

    private static function rejectIpv6(string $packed): ?string
    {
        if ($packed === str_repeat("\x00", 16)) {
            return 'unspecified address';
        }
        if ($packed === str_repeat("\x00", 15)."\x01") {
            return 'loopback address (the gateway would dial its own host)';
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        if ($first === 0xFF) {
            return 'multicast address';
        }
        // fe80::/10
        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return 'link-local address';
        }

        return null;
    }

    /**
     * Validate a TCP port for the SSH connection.
     */
    public static function isValidPort(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }
}
