<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\HostKeys;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialMethod;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Manages the pinned SSH host keys that make a connection trustworthy.
 *
 * Every SSH library in common use verifies nothing by default -- phpseclib,
 * Go's x/crypto/ssh and Guacamole all leave the policy to the caller -- so
 * this is entirely ours to get right, and these rows are the trust store.
 *
 * Scanning is initiated here rather than reported by the gateway, because of
 * the one-way rule: the gateway never calls into LibreNMS, so it cannot tell
 * us about a key it saw. Asking it is also better, because the scan happens
 * before any credential exists to offer.
 */
final class HostKeyManager
{
    public function __construct(
        private readonly ?GatewayClient $gateway = null,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * @return list<HostKey>
     */
    public function pinnedFor(int $deviceId): array
    {
        return HostKey::query()
            ->where('device_id', $deviceId)
            ->where('status', HostKey::PINNED)
            ->get()
            ->all();
    }

    public function hasPin(int $deviceId): bool
    {
        return $this->pinnedFor($deviceId) !== [];
    }

    /**
     * Ask the gateway what key a device is presenting.
     *
     * @return list<array{algorithm: string, public_key: string, fingerprint: string}>
     */
    public function scan(string $ip, int $port = 22): array
    {
        $gateway = $this->gateway ?? new GatewayClient;

        $response = $gateway->scanHostKey($ip, $port);

        /** @var list<array{algorithm: string, public_key: string, fingerprint: string}> $keys */
        $keys = array_values(array_filter(
            (array) ($response['keys'] ?? []),
            static fn ($k): bool => is_array($k) && isset($k['algorithm'], $k['public_key'], $k['fingerprint'])
        ));

        return $keys;
    }

    /**
     * Record a key as trusted.
     *
     * @param  array{algorithm: string, public_key: string, fingerprint: string}  $key
     */
    public function pin(int $deviceId, array $key, ?Authenticatable $by = null, bool $firstConnect = false): HostKey
    {
        $existing = HostKey::query()
            ->where('device_id', $deviceId)
            ->where('algorithm', $key['algorithm'])
            ->where('status', HostKey::PINNED)
            ->first();

        if ($existing !== null && $existing->fingerprint !== $key['fingerprint']) {
            // A changed key is never quietly accepted. Superseding the old row
            // rather than deleting it keeps the history: an operator reviewing
            // the change needs to see what it was before.
            $existing->status = HostKey::SUPERSEDED;
            $existing->save();

            $this->audit->log(
                Event::HostKeyChanged,
                $by,
                $deviceId,
                detail: ['old' => $existing->fingerprint, 'new' => $key['fingerprint']],
            );
        }

        $pinned = HostKey::query()->updateOrCreate(
            [
                'device_id' => $deviceId,
                'algorithm' => $key['algorithm'],
                'fingerprint' => $key['fingerprint'],
            ],
            [
                'public_key' => $this->blobOf($key['public_key']),
                'status' => HostKey::PINNED,
                'first_seen_at' => Carbon::now(),
                'pinned_at' => Carbon::now(),
                'pinned_by' => $by?->getAuthIdentifier(),
            ]
        );

        $this->audit->log(
            $firstConnect ? Event::HostKeyPinned : Event::HostKeyPinned,
            $by,
            $deviceId,
            detail: ['fingerprint' => $key['fingerprint'], 'first_connect' => $firstConnect],
        );

        return $pinned;
    }

    /**
     * Pin on first connect, if the target's policy allows it.
     *
     * Refused outright for any flow carrying a reusable secret. Trust-on-first-
     * use means handing the credential to whatever answers; with a certificate
     * that exposure is bounded to thirty minutes, but a password or private key
     * handed to an impostor is a lasting compromise.
     */
    public function maybePinOnFirstConnect(Target $target, string $ip, int $port, CredentialMethod $method): ?HostKey
    {
        if ($target->host_key_policy !== Target::POLICY_TOFU) {
            return null;
        }

        if ($this->hasPin($target->device_id)) {
            return null;
        }

        if ($method->isReusable()) {
            return null;
        }

        $keys = $this->scan($ip, $port);
        if ($keys === []) {
            return null;
        }

        return $this->pin($target->device_id, $keys[0], null, firstConnect: true);
    }

    /**
     * Forget every pin for a device so the next connection re-establishes trust.
     */
    public function reset(int $deviceId, string $reason, ?Authenticatable $by = null): int
    {
        $count = HostKey::query()
            ->where('device_id', $deviceId)
            ->where('status', HostKey::PINNED)
            ->update(['status' => HostKey::SUPERSEDED]);

        $this->audit->log(Event::HostKeyRejected, $by, $deviceId, detail: ['reason' => $reason]);

        return $count;
    }

    /**
     * Extract the base64 blob from an authorized_keys line.
     *
     * The format is "<type> <base64> [comment]", and the comment is
     * attacker-controlled text supplied by the far end. Keeping only the blob
     * means it can never be rendered in the admin UI or written to a log.
     *
     * Returns an empty string when the input is not a key, rather than falling
     * back to storing the raw line -- a fallback here would silently reinstate
     * exactly the exposure this exists to prevent.
     */
    private function blobOf(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        $isBase64 = static fn (string $p): bool => preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $p) === 1;

        // "<type> <base64> [comment]"
        if (count($parts) >= 2 && $isBase64($parts[1])) {
            return $parts[1];
        }

        // A bare blob with no type prefix.
        if (count($parts) === 1 && $isBase64($parts[0])) {
            return $parts[0];
        }

        return '';
    }
}
