<?php

declare(strict_types=1);

namespace Adn\WebTerm\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * "May this user see this device at all?"
 *
 * A seam, so the authorization matrix can be tested exhaustively without a
 * LibreNMS installation -- and so the invariant test can drive visibility
 * independently of the grant rules it is checking against.
 */
interface VisibilityCheck
{
    /** @param object $device A LibreNMS App\Models\Device. */
    public function canView(Authenticatable $user, object $device): bool;
}
