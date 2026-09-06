<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Session;

/**
 * What the browser needs to open a terminal, and nothing else.
 *
 * Notably absent: any credential. The browser holds a ticket -- 32 random
 * bytes, valid for 30 seconds, usable once -- and the gateway already has the
 * secret.
 */
final class MintResult
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $ticket,
        public readonly string $wsUrl,
        public readonly string $uiUrl,
        public readonly string $expiresAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'ticket' => $this->ticket,
            'ws_url' => $this->wsUrl,
            'ui_url' => $this->uiUrl,
            'expires_at' => $this->expiresAt,
        ];
    }
}
