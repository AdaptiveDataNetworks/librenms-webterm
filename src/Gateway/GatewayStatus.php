<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Gateway;

use AdaptiveDataNetworks\WebTerm\Protocol;
use Illuminate\Support\Facades\Cache;

/**
 * What the gateway last told us about itself.
 *
 * A protocol mismatch used to surface only when a session was created -- an
 * HTTP 409 raised as GatewayVersionException, which is to say AFTER the
 * operator clicked. Meanwhile the docs promised a warning banner and a
 * self-disabling button, neither of which existed.
 *
 * This records the gateway's advertised range during the reconciler's pass, so
 * the device panel and the terminal tab can refuse before the click instead of
 * erroring after it. Written out of band on purpose: rendering a device page
 * must never make a synchronous call to the gateway, because a device page that
 * hangs when the gateway is down is worse than no terminal button.
 */
final class GatewayStatus
{
    private const CACHE_KEY = 'webterm:gateway-status';

    /**
     * Long enough to outlive several missed reconciler passes, short enough
     * that a fixed gateway stops being reported as broken within minutes.
     */
    private const TTL = 600;

    /**
     * @param  array<string, mixed>  $hello
     */
    public static function record(array $hello): void
    {
        $min = (int) ($hello['min_protocol'] ?? 0);
        $max = (int) ($hello['max_protocol'] ?? 0);

        if ($min <= 0 || $max <= 0) {
            return;
        }

        Cache::put(self::CACHE_KEY, ['min' => $min, 'max' => $max], self::TTL);
    }

    /**
     * A human explanation when the gateway cannot speak our protocol.
     *
     * Null means "fine, or not known" -- and the two are deliberately the same
     * answer. A cold cache on a fresh install must not hide the terminal; the
     * mint still refuses on mismatch, so the worst case of not knowing is the
     * behaviour we had before.
     */
    public static function protocolMismatch(): ?string
    {
        $status = Cache::get(self::CACHE_KEY);

        if (! is_array($status) || ! isset($status['min'], $status['max'])) {
            return null;
        }

        $min = (int) $status['min'];
        $max = (int) $status['max'];

        if ($min <= Protocol::VERSION && $max >= Protocol::VERSION) {
            return null;
        }

        return sprintf(
            'The gateway speaks protocol v%d-v%d and this plugin speaks v%d. Upgrade whichever is behind; '
            .'they must match exactly, as there is no version skew tolerance.',
            $min,
            $max,
            Protocol::VERSION
        );
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
