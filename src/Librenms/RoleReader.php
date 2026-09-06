<?php

declare(strict_types=1);

namespace Adn\WebTerm\Librenms;

use Adn\WebTerm\Authorization\Contracts\RoleSource;
use Adn\WebTerm\Support\Guard;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Reads LibreNMS roles (Spatie laravel-permission).
 *
 * Used for reporting and for the "who could connect?" admin view, NOT as an
 * authorization gate. WebTerm grants are explicit rows; being an admin confers
 * no shell access on its own. That is a deliberate departure from how the rest
 * of LibreNMS behaves, and it is the reason ShellAuthorizer avoids Laravel
 * Gate abilities entirely -- LibreNMS registers a Gate::before that returns
 * true for every ability for any admin, which would silently defeat a
 * gate-based design.
 */
final class RoleReader implements CoreDependency, RoleSource
{
    public static function coreSymbols(): array
    {
        return [
            'App\Models\User',
            'Spatie\Permission\Traits\HasRoles::hasRole',
        ];
    }

    public function isAdmin(Authenticatable $user): bool
    {
        return $this->hasRole($user, 'admin');
    }

    public function hasRole(Authenticatable $user, string $role): bool
    {
        return Guard::safely(
            static function () use ($user, $role): bool {
                if (! method_exists($user, 'hasRole')) {
                    return false;
                }

                return (bool) $user->hasRole($role);
            },
            false,
            'RoleReader::hasRole'
        );
    }

    /** @return list<string> */
    public function rolesOf(Authenticatable $user): array
    {
        return Guard::safely(
            static function () use ($user): array {
                if (! method_exists($user, 'getRoleNames')) {
                    return [];
                }

                $names = $user->getRoleNames();

                return array_values(array_filter(
                    is_object($names) && method_exists($names, 'toArray') ? $names->toArray() : (array) $names,
                    'is_string'
                ));
            },
            [],
            'RoleReader::rolesOf'
        );
    }
}
