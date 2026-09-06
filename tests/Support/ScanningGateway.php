<?php

declare(strict_types=1);

namespace Adn\WebTerm\Tests\Support;

use Adn\WebTerm\Gateway\GatewayClient;

/**
 * A GatewayClient that returns fixed host keys from a scan.
 *
 * The real scan path is exercised against the Go binary elsewhere; this covers
 * the pinning policy, which is where the security decisions live.
 */
final class ScanningGateway extends GatewayClient
{
    /** @param list<array{algorithm: string, public_key: string, fingerprint: string}> $keys */
    public function __construct(private readonly array $keys = [])
    {
        parent::__construct('http://127.0.0.1:1', null);
    }

    public function scanHostKey(string $ip, int $port = 22): array
    {
        return ['keys' => $this->keys];
    }
}
