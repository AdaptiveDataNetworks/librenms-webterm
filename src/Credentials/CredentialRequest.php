<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Credentials;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Everything a provider needs to resolve a credential for one session.
 *
 * $clientPublicKey is present only for the signed-certificate flow: the gateway
 * generates an ephemeral keypair and sends us the public half, so the private
 * key never crosses a process boundary and we have nothing reusable to leak.
 */
final class CredentialRequest
{
    public function __construct(
        public readonly int $deviceId,
        public readonly string $principal,
        public readonly Authenticatable $user,
        public readonly ?string $clientPublicKey = null,
        public readonly string $protocol = 'ssh',
        /**
         * Static device-group ids this device belongs to, resolved by the
         * caller. Passed in rather than looked up here because resolving them
         * needs a LibreNMS Device model, and the credential layer is kept free
         * of core classes so it stays unit-testable.
         *
         * @var list<int>
         */
        public readonly array $groupIds = [],
    ) {}
}
