<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Tests\Support;

/**
 * Stands in for App\Models\Device, modelling only the surface our adapters
 * touch. tests/Contract is what asserts the real model still offers it.
 */
final class FakeDevice
{
    public function __construct(
        public readonly int $device_id,
        public readonly ?string $ip = '10.0.0.1',
        public readonly ?string $overwrite_ip = null,
        public readonly ?string $hostname = null,
        private readonly mixed $sshPort = null,
    ) {}

    public function getAttrib(string $name, mixed $default = null): mixed
    {
        return $name === 'override_device_ssh_port' ? $this->sshPort : $default;
    }
}
