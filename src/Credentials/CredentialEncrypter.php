<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials;

use Illuminate\Encryption\Encrypter;
use RuntimeException;
use SensitiveParameter;

/**
 * Encryption for stored device credentials.
 *
 * WHY NOT JUST USE APP_KEY DIRECTLY
 * ---------------------------------
 * APP_KEY protects session cookies and signed URLs, and it appears in .env,
 * deployment tooling and support bundles. Device credentials are a materially
 * more valuable secret, so the key is derived from APP_KEY through HKDF with a
 * distinct info string -- a compromise of a session cookie does not hand over
 * device passwords, and an installation that wants full separation can set
 * WEBTERM_CREDENTIAL_KEY and share nothing at all.
 *
 * THE ROTATION HAZARD
 * -------------------
 * Rotating APP_KEY is routine advice, and doing it would silently make every
 * stored credential undecryptable. Each row therefore records the key_id that
 * encrypted it, so `webterm:credentials:rekey` can migrate incrementally and a
 * partially completed rekey is a recoverable state rather than an outage.
 */
final class CredentialEncrypter
{
    public const CIPHER = 'aes-256-gcm';

    private const HKDF_INFO = 'librenms-webterm/credentials/v1';

    private readonly string $key;

    public function __construct(#[SensitiveParameter] ?string $rawKey = null)
    {
        $this->key = $rawKey !== null ? $this->normalise($rawKey) : $this->deriveKey();
    }

    /**
     * Short, stable identifier for the key that encrypted a row.
     *
     * Not a secret -- it is a truncated hash of the key, which is what makes it
     * safe to store next to the ciphertext and to print in `webterm:doctor`.
     */
    public function keyId(): string
    {
        return substr(hash('sha256', 'keyid:'.$this->key), 0, 16);
    }

    public function cipher(): string
    {
        return self::CIPHER;
    }

    /**
     * @param  array<string, string>  $secrets
     */
    public function encrypt(#[SensitiveParameter] array $secrets): string
    {
        return $this->encrypter()->encrypt($secrets);
    }

    /**
     * @return array<string, string>
     */
    public function decrypt(string $payload): array
    {
        $value = $this->encrypter()->decrypt($payload);

        if (! is_array($value)) {
            throw new RuntimeException('Decrypted credential payload was not an array.');
        }

        /** @var array<string, string> $value */
        return $value;
    }

    private function encrypter(): Encrypter
    {
        return new Encrypter($this->key, self::CIPHER);
    }

    private function deriveKey(): string
    {
        $appKey = (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException(
                'Cannot derive a credential key: APP_KEY is not set. '
                .'Run `php artisan key:generate`, or set WEBTERM_CREDENTIAL_KEY.'
            );
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }

        return hash_hkdf('sha256', $appKey, 32, self::HKDF_INFO);
    }

    private function normalise(#[SensitiveParameter] string $rawKey): string
    {
        if (str_starts_with($rawKey, 'base64:')) {
            $decoded = base64_decode(substr($rawKey, 7), true);
            if ($decoded !== false) {
                $rawKey = $decoded;
            }
        }

        if (strlen($rawKey) !== 32) {
            // Accept any passphrase, but stretch it, so a short
            // WEBTERM_CREDENTIAL_KEY is not silently rejected at connect time.
            return hash_hkdf('sha256', $rawKey, 32, self::HKDF_INFO);
        }

        return $rawKey;
    }
}
