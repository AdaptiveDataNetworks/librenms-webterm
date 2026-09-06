<?php

declare(strict_types=1);

namespace Adn\WebTerm\Auth;

use Adn\WebTerm\Support\Base32;
use SensitiveParameter;

/**
 * RFC 6238 time-based one-time passwords.
 *
 * Matches what LibreNMS already issues -- RFC 4648 Base32 secrets, HMAC-SHA1,
 * 30-second steps -- so an operator uses the authenticator entry they already
 * have rather than enrolling a second one.
 *
 * Verified against the RFC 6238 Appendix B test vectors, which is the only
 * responsible way to ship a hand-written OTP implementation.
 */
final class Totp
{
    public const STEP_SECONDS = 30;

    /**
     * The step counter for a timestamp. Exposed because the replay guard
     * records the last step a user consumed, and a step may be used only once.
     */
    public static function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::STEP_SECONDS);
    }

    /**
     * @param  string  $secret  Base32, as stored by LibreNMS.
     */
    public static function generate(
        #[SensitiveParameter] string $secret,
        int $step,
        int $digits = 6,
    ): ?string {
        $key = Base32::decode($secret);
        if ($key === null) {
            return null;
        }

        $hash = hash_hmac('sha1', pack('J', $step), $key, true);

        // RFC 4226 dynamic truncation.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($binary % (10 ** $digits)),
            $digits,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Verify a code, returning the step it matched, or null.
     *
     * The step is returned rather than a boolean so the caller can enforce
     * single use: without that, a code remains valid for its whole window and
     * an observer who sees it typed can replay it.
     *
     * $window of 1 accepts the adjacent steps either side, tolerating ~30s of
     * clock skew. Wider windows trade security for convenience.
     */
    public static function verify(
        #[SensitiveParameter] string $secret,
        string $code,
        int $unixTime,
        int $window = 1,
        int $digits = 6,
    ): ?int {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== $digits) {
            return null;
        }

        $current = self::stepAt($unixTime);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;
            $expected = self::generate($secret, $step, $digits);

            if ($expected !== null && hash_equals($expected, $code)) {
                return $step;
            }
        }

        return null;
    }
}
