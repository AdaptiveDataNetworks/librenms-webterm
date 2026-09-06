<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials;

use Adn\WebTerm\Credentials\Contracts\CredentialProvider;
use Adn\WebTerm\Credentials\Drivers\DatabaseCredentialProvider;
use Illuminate\Support\Manager;

/**
 * Selects the configured credential provider.
 *
 * Laravel's Manager gives the standard driver-resolution behaviour, so the
 * shape is familiar to anyone who has extended a Laravel package -- and a
 * third-party driver can be registered with extend() without patching us.
 *
 * @method CredentialProvider driver(?string $driver = null)
 */
final class CredentialManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('webterm.credentials.driver', 'database');
    }

    public function createDatabaseDriver(): CredentialProvider
    {
        return new DatabaseCredentialProvider;
    }
}
