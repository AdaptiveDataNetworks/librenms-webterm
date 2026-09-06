<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials\Vault;

use Adn\WebTerm\Credentials\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * HTTP transport for the Vault API.
 *
 * Built on Laravel's HTTP client rather than a Vault SDK. Guzzle is already in
 * LibreNMS's lockfile, so this adds nothing to our runtime dependencies --
 * which matters because every one of those is a chance for `lnms plugin:add`
 * to fail against LibreNMS's own lock for every user.
 *
 * EVERY exception is re-wrapped. A raw Guzzle exception renders the request,
 * headers included, and the header in question is X-Vault-Token. One
 * unhandled throw would put a Vault token into a LibreNMS stack trace, an
 * error page, and probably a GitHub issue.
 */
final class VaultTransport
{
    public function __construct(
        private readonly string $address,
        private readonly ?string $namespace = null,
        private readonly bool $verifyTls = true,
        private readonly ?string $caCert = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = [], #[SensitiveParameter] ?string $token = null): array
    {
        return $this->send('post', $path, $payload, $token);
    }

    /** @return array<string, mixed> */
    public function get(string $path, #[SensitiveParameter] ?string $token = null): array
    {
        return $this->send('get', $path, null, $token);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $payload, #[SensitiveParameter] ?string $token): array
    {
        $request = $this->request($token, $path);

        try {
            $response = $payload === null
                ? $request->get($this->url($path))
                : $request->post($this->url($path), $payload);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException('Vault did not respond: '.$e->getMessage());
        } catch (Throwable) {
            // Message deliberately discarded: it may render the request.
            throw new ProviderUnavailableException('Vault request failed.');
        }

        $status = $response->status();

        if ($status === 403) {
            throw new VaultPermissionDeniedException(
                'Vault refused the request (403). The token may have expired, or its policy does not '
                .'allow this path. Check the policy attached to the WebTerm role.'
            );
        }

        if ($status === 404) {
            throw new VaultNotFoundException(sprintf('Vault has nothing at %s.', $path));
        }

        if ($status === 503) {
            throw new ProviderUnavailableException(
                'Vault is sealed or standby (503). WebTerm fails closed: no terminal can be opened '
                .'until Vault is available. This is why out-of-band device access is a prerequisite.'
            );
        }

        if ($status >= 400) {
            // Vault's own error strings are safe -- they describe policy and
            // paths, never the token -- but nothing else from the exception is.
            $errors = (array) ($response->json('errors') ?? []);
            throw new VaultRequestException(sprintf(
                'Vault returned %d%s',
                $status,
                $errors === [] ? '.' : ': '.implode('; ', array_map('strval', $errors))
            ));
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function request(#[SensitiveParameter] ?string $token, string $path): PendingRequest
    {
        $request = Http::asJson()
            ->acceptJson()
            ->timeout(5)
            ->connectTimeout(2)
            // Retries only help for transport-level faults. Retrying a 4xx
            // would replay a rejected request; retrying a write could
            // double-issue a credential.
            ->retry(2, 100, throw: false);

        if ($token !== null) {
            $request = $request->withHeaders(['X-Vault-Token' => $token]);
        }

        // Namespaces apply to everything except the sys/ endpoints, which live
        // at the root. Sending the header there produces a confusing 404.
        if ($this->namespace !== null && $this->namespace !== '' && ! str_starts_with(ltrim($path, '/'), 'sys/')) {
            $request = $request->withHeaders(['X-Vault-Namespace' => $this->namespace]);
        }

        if (! $this->verifyTls) {
            $request = $request->withoutVerifying();
        } elseif ($this->caCert !== null && $this->caCert !== '') {
            $request = $request->withOptions(['verify' => $this->caCert]);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim($this->address, '/').'/v1/'.ltrim($path, '/');
    }
}
