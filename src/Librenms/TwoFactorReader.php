<?php

declare(strict_types=1);

namespace Adn\WebTerm\Librenms;

use Adn\WebTerm\Support\Guard;
use App\Facades\LibrenmsConfig;
use App\Models\UserPref;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Reads LibreNMS's existing TOTP enrolment so that step-up authentication can
 * reuse the secret a user has already registered, rather than making them
 * enrol a second authenticator.
 *
 * ⚠ WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * ------------------------------------------
 * It does not, and must never, consult session('twofactor'). LibreNMS sets that
 * flag once at login and leaves it set for the life of the session. Accepting
 * it would mean a stolen session cookie is sufficient to open a shell, which is
 * precisely the attack step-up exists to interrupt. Step-up must challenge at
 * connect time, every time (subject to its own short grace window).
 *
 * There is a regression test asserting the session flag is never consulted.
 */
final class TwoFactorReader implements CoreDependency
{
    public static function coreSymbols(): array
    {
        return [
            'App\Models\UserPref::getPref',
            'App\Facades\LibrenmsConfig',
        ];
    }

    /**
     * Whether LibreNMS has two-factor enabled globally.
     */
    public function isAvailable(): bool
    {
        return Guard::safely(
            static function (): bool {
                if (! class_exists(LibrenmsConfig::class)) {
                    return false;
                }

                return LibrenmsConfig::get('twofactor') === true;
            },
            false,
            'TwoFactorReader::isAvailable'
        );
    }

    /**
     * Whether this user has a usable TOTP secret registered with LibreNMS.
     *
     * Fails closed. If we cannot tell, we report not-enrolled, which makes
     * step-up unsatisfiable and therefore denies the session -- the safe
     * direction. The settings UI refuses to require step-up while no user is
     * enrolled, so this cannot silently lock everyone out.
     */
    public function isEnrolled(Authenticatable $user): bool
    {
        return $this->secretFor($user) !== null;
    }

    /**
     * The user's TOTP secret, or null.
     *
     * LibreNMS stores this as a preference whose value is an array containing
     * a 'key' entry, alongside counters it maintains itself.
     */
    public function secretFor(Authenticatable $user): ?string
    {
        return Guard::safely(
            static function () use ($user): ?string {
                if (! class_exists(UserPref::class)) {
                    return null;
                }

                /** @var mixed $pref */
                $pref = UserPref::getPref($user, 'twofactor');

                if (! is_array($pref)) {
                    return null;
                }

                $key = $pref['key'] ?? null;

                return is_string($key) && $key !== '' ? $key : null;
            },
            null,
            'TwoFactorReader::secretFor'
        );
    }
}
