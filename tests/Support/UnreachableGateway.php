<?php

declare(strict_types=1);

namespace Adn\WebTerm\Tests\Support;

use Adn\WebTerm\Gateway\GatewayClient;
use Adn\WebTerm\Gateway\GatewayUnreachableException;

/**
 * A gateway that is down, for exercising the degradation paths.
 */
final class UnreachableGateway extends GatewayClient
{
    public function __construct()
    {
        parent::__construct('http://127.0.0.1:1', null);
    }

    public function hello(): array
    {
        throw new GatewayUnreachableException('The terminal gateway did not respond.');
    }

    public function createSession(array $payload): array
    {
        throw new GatewayUnreachableException('The terminal gateway did not respond.');
    }

    public function listSessions(): array
    {
        throw new GatewayUnreachableException('The terminal gateway did not respond.');
    }

    public function killSession(string $sessionId, string $reason): void
    {
        throw new GatewayUnreachableException('The terminal gateway did not respond.');
    }
}
