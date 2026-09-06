<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Stands in for App\Models\User. Hand-written rather than mocked: the
 * authorization matrix runs thousands of combinations and must stay cheap.
 */
final class FakeUser implements Authenticatable
{
    /** @param list<string> $roles */
    public function __construct(
        public readonly int $id,
        public readonly array $roles = [],
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
