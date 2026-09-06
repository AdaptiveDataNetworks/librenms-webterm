<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Step-up authentication at connect time.
 *
 * Separated from ShellAuthorizer so that the authorization rules can be tested
 * exhaustively without a TOTP implementation, and so the "does NOT accept the
 * login session flag" rule lives in one auditable place.
 */
interface StepUpGate
{
    public function isRequired(): bool;

    /**
     * Whether this user has satisfied step-up recently enough.
     *
     * Must never consult session('twofactor'): LibreNMS sets that once at login
     * and leaves it set, so honouring it would make a stolen session cookie
     * sufficient to open a shell.
     */
    public function isSatisfied(Authenticatable $user): bool;
}
