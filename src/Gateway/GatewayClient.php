<?php

declare(strict_types=1);

namespace Adn\WebTerm\Gateway;

use Adn\WebTerm\Protocol;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;

/**
 * The plugin's client for the gateway control plane.
 *
 * Uses ext-curl directly rather than Guzzle or Laravel's HTTP client. Both are
 * present, but both would put the request -- headers included -- into an
 * exception message on failure, and one of these requests carries a credential.
 * Curl gives exact control over what is reported.
 *
 * PHP is the only initiator: the gateway never calls back into LibreNMS. That
 * is what keeps a credential-vending endpoint off the public LibreNMS vhost.
 */
class GatewayClient
{
    private const USER_AGENT = 'librenms-webterm-plugin';

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $secretFile = null,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    public function hello(): array
    {
        return $this->send('GET', Protocol::EP_HELLO_PATH);
    }

    /**
     * Phase 1: create a pending session and receive the ticket.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    public function createSession(array $payload): array
    {
        return $this->send('POST', Protocol::EP_SESSION_CREATE_PATH, $payload);
    }

    /**
     * Ask the gateway what host key a device presents.
     *
     * The gateway never authenticates during a scan, so this is safe to run
     * against a device nobody has decided to trust yet.
     *
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    public function scanHostKey(string $ip, int $port = 22): array
    {
        return $this->send('POST', Protocol::EP_HOSTKEY_SCAN_PATH, ['ip' => $ip, 'port' => $port]);
    }

    /**
     * Phase 3: hand over the resolved secret.
     *
     * The only request in the system carrying credential material. It travels
     * over loopback, and the payload is never logged or retried -- a retry
     * would mean the secret sits in memory for longer with no benefit, since a
     * failure here aborts the session anyway.
     *
     * @param  array<string, string>  $auth
     *
     * @throws GatewayException
     */
    public function supplyCredential(string $sessionId, #[SensitiveParameter] array $auth): void
    {
        $this->send(
            'POST',
            str_replace('{id}', rawurlencode($sessionId), Protocol::EP_SESSION_CREDENTIAL_PATH),
            ['auth' => $auth],
            retryable: false
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    public function listSessions(): array
    {
        return $this->send('GET', Protocol::EP_SESSION_LIST_PATH);
    }

    public function killSession(string $sessionId, string $reason): void
    {
        $this->send(
            'DELETE',
            str_replace('{id}', rawurlencode($sessionId), Protocol::EP_SESSION_DELETE_PATH),
            ['reason' => $reason]
        );
    }

    public function notice(string $sessionId, string $level, string $message): void
    {
        $this->send(
            'POST',
            str_replace('{id}', rawurlencode($sessionId), Protocol::EP_SESSION_NOTICE_PATH),
            ['level' => $level, 'message' => $message]
        );
    }

    /**
     * Build the canonical string that is signed.
     *
     * Byte-identical to the Go implementation; protocol/PROTOCOL.md section 3.2
     * is normative and a cross-implementation test asserts the two agree.
     */
    public static function canonical(string $method, string $path, int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            Protocol::NAME,
            'v1',
            $method,
            $path,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function deriveControlKey(#[SensitiveParameter] string $secret): string
    {
        return hash_hkdf(
            Protocol::HKDF_HASH,
            $secret,
            32,
            Protocol::HKDF_INFO_CONTROL,
            Protocol::HKDF_SALT
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $payload = null, bool $retryable = true): array
    {
        $this->assertCircuitClosed();

        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $nonce = bin2hex(random_bytes(8));
        $signature = Protocol::SIGNATURE_PREFIX.hash_hmac(
            'sha256',
            self::canonical($method, $path, $timestamp, $nonce, $body),
            $this->controlKey()
        );

        $ch = curl_init($this->baseUrl().$path);
        if ($ch === false) {
            throw new GatewayException('Could not initialise an HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: '.self::USER_AGENT,
                Protocol::HEADER_TIMESTAMP.': '.$timestamp,
                Protocol::HEADER_NONCE.': '.$nonce,
                Protocol::HEADER_SIGNATURE.': '.$signature,
            ],
            // Hard caps. A blackholed gateway must never consume every php-fpm
            // worker: five terminals waiting on a dead socket can starve the
            // whole LibreNMS UI.
            CURLOPT_CONNECTTIMEOUT_MS => (int) config('webterm.gateway.connect_timeout_ms', 2000),
            CURLOPT_TIMEOUT_MS => (int) config('webterm.gateway.timeout_ms', 5000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->recordFailure();

            throw new GatewayUnreachableException(
                'The terminal gateway did not respond: '.($error !== '' ? $error : 'unknown transport error')
            );
        }

        if ($status >= 500 || $status === 0) {
            $this->recordFailure();
        } else {
            $this->recordSuccess();
        }

        if ($status === 401) {
            throw new GatewayException(
                'The gateway rejected our credentials. LibreNMS and the gateway must read the same '
                .'shared secret file; check gateway.secret_file on both sides.'
            );
        }

        if ($status === 409) {
            throw new GatewayVersionException(
                'The gateway speaks a different protocol version. '
                .'Upgrade the gateway, or pin the plugin to a matching release.'
            );
        }

        if ($status >= 400) {
            throw new GatewayException(sprintf('The gateway returned HTTP %d.', $status));
        }

        if ($response === '' || $status === 204) {
            return [];
        }

        $decoded = json_decode((string) $response, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function baseUrl(): string
    {
        return rtrim($this->baseUrl ?? (string) config('webterm.gateway.url', 'http://127.0.0.1:8377'), '/');
    }

    private function controlKey(): string
    {
        $path = $this->secretFile ?? (string) config('webterm.gateway.secret_file');

        if ($path === '' || ! is_readable($path)) {
            throw new GatewayException(sprintf(
                'The gateway shared secret is not readable at %s. '
                .'Generate one with `librenms-webterm-gw init` and make it readable by the web user.',
                $path === '' ? '(unset)' : $path
            ));
        }

        $secret = trim((string) file_get_contents($path));

        if (strlen($secret) < Protocol::SECRET_BYTES) {
            throw new GatewayException(sprintf(
                'The gateway shared secret in %s is shorter than %d bytes.',
                $path,
                Protocol::SECRET_BYTES
            ));
        }

        return self::deriveControlKey($secret);
    }

    /**
     * A simple circuit breaker.
     *
     * Without it, an unreachable gateway turns every device page load into a
     * multi-second stall, and the failure spreads from "the terminal is down"
     * to "LibreNMS is down".
     */
    private function assertCircuitClosed(): void
    {
        $failures = (int) Cache::get($this->circuitKey(), 0);
        $threshold = (int) config('webterm.gateway.circuit_breaker.threshold', 3);

        if ($failures >= $threshold) {
            throw new GatewayUnreachableException(
                'The terminal gateway is not responding and has been temporarily marked down. '
                .'Check: systemctl status librenms-webterm-gw'
            );
        }
    }

    private function recordFailure(): void
    {
        $cooldown = (int) config('webterm.gateway.circuit_breaker.cooldown_seconds', 30);
        $current = (int) Cache::get($this->circuitKey(), 0);

        Cache::put($this->circuitKey(), $current + 1, $cooldown);
    }

    private function recordSuccess(): void
    {
        Cache::forget($this->circuitKey());
    }

    private function circuitKey(): string
    {
        return 'webterm:gateway:failures';
    }
}
