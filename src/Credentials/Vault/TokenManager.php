<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials\Vault;

use Adn\WebTerm\Credentials\Exceptions\ProviderUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;
use Throwable;

/**
 * Obtains and caches a Vault token.
 *
 * Two auth methods ship. Vault Agent is the recommendation: the agent owns
 * renewal and WebTerm never handles a SecretID at all, which removes an entire
 * class of credential-handling code from a web application. AppRole exists for
 * installations that cannot run the agent.
 *
 * The cached token is always encrypted. It is a bearer credential for the
 * secret store, and Laravel's cache is frequently Redis or a database table
 * that is backed up, replicated and inspected far more casually than a vault.
 */
class TokenManager
{
    private const CACHE_KEY = 'webterm:vault:token';

    private const LOCK_KEY = 'webterm:vault:token:lock';

    /** Renew before expiry rather than at it: a token that expires mid-request fails a user's connection. */
    private const RENEW_AT = 0.75;

    public function __construct(
        private readonly VaultTransport $transport,
        private readonly string $method = 'agent',
        /** @var array<string, mixed> */
        private readonly array $options = [],
    ) {}

    /**
     * A usable token, from cache when possible.
     */
    public function token(): string
    {
        $cached = $this->fromCache();
        if ($cached !== null) {
            return $cached;
        }

        // Without the lock, a burst of connections on a cold cache each performs
        // its own login. AppRole SecretIDs can be use-limited, so that is not
        // merely wasteful -- it can exhaust them.
        $lock = Cache::lock(self::LOCK_KEY, 10);

        try {
            $lock->block(5);
        } catch (Throwable) {
            // Losing the race is fine; another process is logging in.
            $cached = $this->fromCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            // Double-check: the holder of the lock may have just populated it.
            $cached = $this->fromCache();
            if ($cached !== null) {
                return $cached;
            }

            return $this->login();
        } finally {
            $lock->release();
        }
    }

    /**
     * Discard the cached token, forcing a fresh login.
     *
     * Called exactly once after a 403: a token can be revoked or expire early,
     * and one retry distinguishes that from a genuine policy failure. Retrying
     * indefinitely would turn a misconfigured policy into a login storm.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function fromCache(): ?string
    {
        $encrypted = Cache::get(self::CACHE_KEY);

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($encrypted);
        } catch (Throwable) {
            // A key change makes the cached token unreadable; log in again.
            return null;
        }

        return $token !== '' ? $token : null;
    }

    private function login(): string
    {
        [$token, $ttl] = match ($this->method) {
            'approle' => $this->loginApprole(),
            'agent' => $this->loginAgent(),
            default => throw new ProviderUnavailableException(
                sprintf('Unknown Vault auth method "%s". Use "agent" or "approle".', $this->method)
            ),
        };

        $this->remember($token, $ttl);

        return $token;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function loginApprole(): array
    {
        $roleId = (string) ($this->options['role_id'] ?? '');
        $secretIdFile = (string) ($this->options['secret_id_file'] ?? '');

        if ($roleId === '') {
            throw new ProviderUnavailableException(
                'WEBTERM_VAULT_ROLE_ID is not set. See docs: vault/policies.'
            );
        }

        if ($secretIdFile === '' || ! is_readable($secretIdFile)) {
            throw new ProviderUnavailableException(sprintf(
                'The AppRole SecretID file %s is not readable by the web user.',
                $secretIdFile === '' ? '(unset)' : $secretIdFile
            ));
        }

        $secretId = trim((string) file_get_contents($secretIdFile));
        $mount = (string) ($this->options['mount'] ?? 'approle');

        $response = $this->transport->post("auth/{$mount}/login", [
            'role_id' => $roleId,
            'secret_id' => $secretId,
        ]);

        return $this->extractAuth($response);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function loginAgent(): array
    {
        // The agent injects the token, so "logging in" is a lookup-self that
        // confirms we have one and tells us how long it lasts.
        $response = $this->transport->get('auth/token/lookup-self');

        $data = (array) ($response['data'] ?? []);
        $token = (string) ($data['id'] ?? '');

        if ($token === '') {
            throw new ProviderUnavailableException(
                'Vault Agent did not provide a usable token. Check that the agent is running and that '
                .'its sink is readable, or switch WEBTERM_VAULT_AUTH to approle.'
            );
        }

        return [$token, (int) ($data['ttl'] ?? 3600)];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{0: string, 1: int}
     */
    private function extractAuth(array $response): array
    {
        $auth = (array) ($response['auth'] ?? []);
        $token = (string) ($auth['client_token'] ?? '');

        if ($token === '') {
            throw new ProviderUnavailableException('Vault did not return a client token.');
        }

        return [$token, (int) ($auth['lease_duration'] ?? 3600)];
    }

    private function remember(#[SensitiveParameter] string $token, int $ttl): void
    {
        $lifetime = max(30, (int) floor($ttl * self::RENEW_AT));

        Cache::put(self::CACHE_KEY, Crypt::encryptString($token), $lifetime);
    }
}
