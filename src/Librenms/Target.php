<?php

declare(strict_types=1);

namespace Adn\WebTerm\Librenms;

/**
 * A validated dial target: always an IP literal, always a valid port.
 *
 * Constructing one is the assertion that IpGuard has approved the address.
 */
final class Target
{
    public function __construct(
        public readonly string $ip,
        public readonly int $port,
    ) {}

    public function __toString(): string
    {
        return str_contains($this->ip, ':')
            ? '['.$this->ip.']:'.$this->port
            : $this->ip.':'.$this->port;
    }
}
