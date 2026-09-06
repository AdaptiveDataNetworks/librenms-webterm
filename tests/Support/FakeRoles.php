<?php

declare(strict_types=1);

namespace Adn\WebTerm\Tests\Support;

use Adn\WebTerm\Authorization\Contracts\RoleSource;
use Illuminate\Contracts\Auth\Authenticatable;

final class FakeRoles implements RoleSource
{
    public function rolesOf(Authenticatable $user): array
    {
        return $user instanceof FakeUser ? $user->roles : [];
    }
}
