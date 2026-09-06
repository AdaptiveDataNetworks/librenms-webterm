<?php

declare(strict_types=1);

namespace Adn\WebTerm\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default binding until the TOTP implementation is wired in.
 *
 * Fails closed: when step-up is configured as required, nothing satisfies it,
 * so no session opens. A placeholder that returned "satisfied" would silently
 * disable a security control while appearing to enforce one.
 */
final class AlwaysChallengeStepUp implements StepUpGate
{
    public function __construct(private readonly bool $required = true) {}

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isSatisfied(Authenticatable $user): bool
    {
        return false;
    }
}
