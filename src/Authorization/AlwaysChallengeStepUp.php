<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A step-up gate that nothing can satisfy.
 *
 * NOT the default -- the service provider binds {@see TotpStepUp}. This was
 * once the fallback, which meant that on any install where step-up was
 * required (the default) every session was denied with StepUpRequired and no
 * operator could open a terminal at all.
 *
 * Kept because it is the correct thing to inject where a caller wants step-up
 * to be provably unsatisfiable, and because a placeholder that returned
 * "satisfied" would silently disable a security control while appearing to
 * enforce one.
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
