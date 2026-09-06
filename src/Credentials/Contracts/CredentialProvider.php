<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials\Contracts;

use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\Credentials\CredentialRequest;
use Adn\WebTerm\Credentials\Exceptions\CredentialException;
use Adn\WebTerm\Credentials\ProviderHealth;
use Adn\WebTerm\Credentials\ResolvedCredential;

/**
 * A source of device credentials.
 *
 * Two drivers ship: `database` (encrypted rows, for small installations) and
 * `vault` (HashiCorp Vault, for everyone else). The abstraction exists from
 * v1.0 rather than being retrofitted, because the two have genuinely different
 * capabilities -- only Vault can issue a short-lived signed certificate -- and
 * that difference has to be visible to the connect path rather than hidden
 * behind a lowest-common-denominator interface.
 */
interface CredentialProvider
{
    public function name(): string;

    /**
     * @throws CredentialException
     */
    public function resolve(CredentialRequest $request): ResolvedCredential;

    /**
     * Which methods this provider can actually produce.
     *
     * The connect path pins the method per target and refuses a downgrade, so
     * a provider that cannot issue a certificate must say so here rather than
     * quietly returning a reusable password on a deployment whose entire
     * security claim is that no long-lived secret exists.
     *
     * @return list<CredentialMethod>
     */
    public function capabilities(): array;

    public function health(): ProviderHealth;
}
