<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Tests\Support;

use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;

/**
 * A GatewayClient that records what it was asked to do.
 *
 * The real client is exercised against the actual Go binary in
 * GatewayClientTest; this one is for asserting the ORDER of the handoff and
 * what crosses the boundary, without a process.
 */
final class RecordingGateway extends GatewayClient
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $lastCreate = [];

    /** @var array<string, string> */
    public array $credentials = [];

    public function __construct()
    {
        parent::__construct('http://127.0.0.1:1', null);
    }

    public function createSession(array $payload): array
    {
        $this->calls[] = 'createSession';
        $this->lastCreate = $payload;

        return [
            'session_id' => $payload['session_id'],
            'instance_id' => 'gw-test',
            'ticket' => str_repeat('t', 43),
            'expires_at' => '2026-09-06T12:00:30Z',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 ephemeral',
        ];
    }

    public function supplyCredential(string $sessionId, array $auth): void
    {
        $this->calls[] = 'supplyCredential';
        $this->credentials = $auth;
    }

    public function killSession(string $sessionId, string $reason): void
    {
        $this->calls[] = 'killSession';
    }

    public function listSessions(): array
    {
        $this->calls[] = 'listSessions';

        return ['instance_id' => 'gw-test', 'sessions' => [], 'pending' => 0, 'live' => 0];
    }
}
