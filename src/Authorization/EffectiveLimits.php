<?php

declare(strict_types=1);

namespace Adn\WebTerm\Authorization;

/**
 * Session limits, after intersecting the global configuration with every grant
 * that applied.
 *
 * Intersection, never union: where two grants both match, the tighter bound
 * wins. Otherwise adding a second, broader grant to a user could silently relax
 * a restriction that an administrator set deliberately on the first.
 */
final class EffectiveLimits
{
    public function __construct(
        public readonly int $idleTimeout,
        public readonly int $maxDuration,
        public readonly int $maxConcurrent,
    ) {}

    public function intersect(?int $maxDuration, ?int $maxConcurrent): self
    {
        return new self(
            $this->idleTimeout,
            $maxDuration === null ? $this->maxDuration : min($this->maxDuration, $maxDuration),
            $maxConcurrent === null ? $this->maxConcurrent : min($this->maxConcurrent, $maxConcurrent),
        );
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'idle_timeout' => $this->idleTimeout,
            'max_duration' => $this->maxDuration,
            'max_concurrent' => $this->maxConcurrent,
        ];
    }
}
