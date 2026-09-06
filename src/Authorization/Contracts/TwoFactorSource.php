<?php

declare(strict_types=1);

namespace Adn\WebTerm\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Reads a user's existing TOTP enrolment.
 *
 * A seam so step-up can be tested without a LibreNMS installation. Implementers
 * must never consult session('twofactor'): LibreNMS sets it once at login and
 * leaves it set, so honouring it would make a stolen cookie sufficient.
 */
interface TwoFactorSource
{
    public function isAvailable(): bool;

    public function isEnrolled(Authenticatable $user): bool;

    public function secretFor(Authenticatable $user): ?string;
}
