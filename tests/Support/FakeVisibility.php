<?php

declare(strict_types=1);

namespace Adn\WebTerm\Tests\Support;

use Adn\WebTerm\Authorization\Contracts\VisibilityCheck;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Lets a test drive device visibility independently of the grant rules being
 * exercised -- which is what makes the "admit implies view" invariant test
 * meaningful rather than circular.
 */
final class FakeVisibility implements VisibilityCheck
{
    /** @param list<int> $visibleDeviceIds */
    public function __construct(
        private readonly array $visibleDeviceIds,
        private readonly bool $all = false,
    ) {}

    public static function all(): self
    {
        return new self([], true);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function canView(Authenticatable $user, object $device): bool
    {
        return $this->all || in_array((int) $device->device_id, $this->visibleDeviceIds, true);
    }
}
