<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Tests\Support;

use AdaptiveDataNetworks\WebTerm\Authorization\StepUpGate;
use Illuminate\Contracts\Auth\Authenticatable;

final class FakeStepUp implements StepUpGate
{
    public function __construct(
        private readonly bool $required = false,
        private readonly bool $satisfied = true,
    ) {}

    public static function satisfied(): self
    {
        return new self(true, true);
    }

    public static function pending(): self
    {
        return new self(true, false);
    }

    public static function notRequired(): self
    {
        return new self(false, true);
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isSatisfied(Authenticatable $user): bool
    {
        return $this->satisfied;
    }
}
