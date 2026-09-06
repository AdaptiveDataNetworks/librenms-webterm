<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials;

/**
 * Health of a credential backend, for `webterm:doctor` and the settings panel.
 *
 * Reported rather than thrown, so a sealed Vault shows as a clear red status
 * instead of a stack trace on the device page.
 */
final class ProviderHealth
{
    private function __construct(
        public readonly bool $healthy,
        public readonly string $summary,
        public readonly ?string $remediation = null,
    ) {}

    public static function ok(string $summary): self
    {
        return new self(true, $summary);
    }

    public static function failing(string $summary, ?string $remediation = null): self
    {
        return new self(false, $summary, $remediation);
    }
}
