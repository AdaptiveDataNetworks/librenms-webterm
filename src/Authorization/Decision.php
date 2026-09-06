<?php

declare(strict_types=1);

namespace Adn\WebTerm\Authorization;

/**
 * The outcome of an authorization check.
 *
 * Always carries a reason, including when allowed, so that the audit record and
 * `webterm:why` are built from the same object the decision was made with --
 * rather than from a second, possibly divergent, explanation.
 */
final class Decision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ReasonCode $reason,
        public readonly ?EffectiveLimits $limits = null,
        public readonly ?string $detail = null,
    ) {}

    public static function allow(EffectiveLimits $limits): self
    {
        return new self(true, ReasonCode::Allowed, $limits);
    }

    public static function deny(ReasonCode $reason, ?string $detail = null): self
    {
        return new self(false, $reason, null, $detail);
    }

    public function message(): string
    {
        return $this->detail !== null
            ? $this->reason->message().' '.$this->detail
            : $this->reason->message();
    }
}
