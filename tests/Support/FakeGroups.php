<?php

declare(strict_types=1);

namespace Adn\WebTerm\Tests\Support;

use Adn\WebTerm\Authorization\Contracts\GroupSource;

final class FakeGroups implements GroupSource
{
    /** @param array<int, list<int>> $map deviceId => groupIds */
    public function __construct(private readonly array $map = []) {}

    public function staticGroupIdsFor(object $device): array
    {
        return $this->map[(int) $device->device_id] ?? [];
    }
}
