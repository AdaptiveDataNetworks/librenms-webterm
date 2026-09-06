<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials\Drivers;

use Adn\WebTerm\Credentials\Contracts\CredentialProvider;
use Adn\WebTerm\Credentials\CredentialEncrypter;
use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\Credentials\CredentialRequest;
use Adn\WebTerm\Credentials\Exceptions\CredentialResolutionFailedException;
use Adn\WebTerm\Credentials\Exceptions\NoCredentialConfiguredException;
use Adn\WebTerm\Credentials\ProviderHealth;
use Adn\WebTerm\Credentials\ResolvedCredential;
use Adn\WebTerm\Models\Credential;
use Throwable;

/**
 * Credentials stored encrypted in the LibreNMS database.
 *
 * The simple option, and honestly the weaker one: the secret is reusable and it
 * lives in the monitoring database, so a backup taken alongside a leaked key is
 * a working credential for the network. The documentation says so plainly and
 * points at Vault. It ships because requiring a Vault deployment to try the
 * plugin would exclude most LibreNMS installations.
 */
final class DatabaseCredentialProvider implements CredentialProvider
{
    public function __construct(
        private readonly CredentialEncrypter $encrypter = new CredentialEncrypter,
    ) {}

    public function name(): string
    {
        return 'database';
    }

    public function capabilities(): array
    {
        // No signed certificates: nothing here can mint one. Saying so is what
        // stops a Vault-shaped deployment silently falling back to a password.
        return [CredentialMethod::Password, CredentialMethod::PrivateKey];
    }

    public function resolve(CredentialRequest $request): ResolvedCredential
    {
        $row = Credential::query()
            ->where('device_id', $request->deviceId)
            ->where('protocol', $request->protocol)
            ->first();

        if ($row === null) {
            throw new NoCredentialConfiguredException(
                'No stored credential for this device. '
                .'Add one with: ./lnms webterm:credentials:set --device=<device> --username=<login>'
            );
        }

        $method = CredentialMethod::tryFrom($row->method);
        if ($method === null) {
            throw new CredentialResolutionFailedException(
                'Stored credential has an unrecognised method.'
            );
        }

        try {
            $secrets = $this->encrypterFor($row)->decrypt($row->payload);
        } catch (Throwable) {
            // Deliberately not re-thrown with the original message: decryption
            // failures from the framework can include payload fragments.
            throw new CredentialResolutionFailedException(
                'Stored credential could not be decrypted. This usually means APP_KEY '
                .'or WEBTERM_CREDENTIAL_KEY changed since it was saved. '
                .'Re-encrypt with: ./lnms webterm:credentials:rekey'
            );
        }

        return new ResolvedCredential($method, $row->username, $secrets);
    }

    public function health(): ProviderHealth
    {
        try {
            $total = Credential::query()->count();
            $current = Credential::query()->where('key_id', $this->encrypter->keyId())->count();
        } catch (Throwable $e) {
            return ProviderHealth::failing('Credential table is unreadable: '.$e->getMessage());
        }

        if ($total === 0) {
            return ProviderHealth::ok('No credentials stored yet.');
        }

        if ($current < $total) {
            return ProviderHealth::failing(
                sprintf('%d of %d credentials are encrypted with an older key.', $total - $current, $total),
                './lnms webterm:credentials:rekey'
            );
        }

        return ProviderHealth::ok(sprintf('%d credentials, all on the current key.', $total));
    }

    /**
     * Rows encrypted with a superseded key stay readable while a rekey runs, so
     * a half-finished migration degrades gracefully instead of taking the
     * terminal offline for every device at once.
     */
    private function encrypterFor(Credential $row): CredentialEncrypter
    {
        if ($row->key_id === $this->encrypter->keyId()) {
            return $this->encrypter;
        }

        $legacy = config('webterm.credentials.database.previous_key');

        if (is_string($legacy) && $legacy !== '') {
            $candidate = new CredentialEncrypter($legacy);
            if ($candidate->keyId() === $row->key_id) {
                return $candidate;
            }
        }

        return $this->encrypter;
    }
}
