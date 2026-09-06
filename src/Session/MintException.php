<?php

declare(strict_types=1);

namespace Adn\WebTerm\Session;

use Adn\WebTerm\Authorization\ReasonCode;
use RuntimeException;

final class MintException extends RuntimeException
{
    public function __construct(string $message, public readonly ReasonCode $reason)
    {
        parent::__construct($message);
    }

    /**
     * The HTTP status for this refusal.
     *
     * DeviceNotVisible maps to 404, identical to a device that does not exist:
     * a user who cannot see a device must not be able to discover that it is
     * terminal-enabled by comparing 403 against 404.
     */
    public function status(): int
    {
        return match ($this->reason) {
            ReasonCode::DeviceNotVisible => 404,
            ReasonCode::StepUpRequired => 428,
            ReasonCode::ConcurrencyLimit => 429,
            default => 403,
        };
    }
}
