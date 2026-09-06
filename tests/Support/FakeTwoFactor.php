<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Tests\Support;

use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\TwoFactorSource;
use Illuminate\Contracts\Auth\Authenticatable;

final class FakeTwoFactor implements TwoFactorSource
{
    public function __construct(private readonly ?string $secret = null) {}

    public static function enrolled(string $secret): self
    {
        return new self($secret);
    }

    public static function notEnrolled(): self
    {
        return new self(null);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isEnrolled(Authenticatable $user): bool
    {
        return $this->secret !== null;
    }

    public function secretFor(Authenticatable $user): ?string
    {
        return $this->secret;
    }
}
