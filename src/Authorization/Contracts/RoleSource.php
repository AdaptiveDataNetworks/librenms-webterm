<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface RoleSource
{
    /** @return list<string> */
    public function rolesOf(Authenticatable $user): array;
}
