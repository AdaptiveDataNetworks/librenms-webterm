<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials\Vault;

use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\Credentials\CredentialRequest;
use Adn\WebTerm\Credentials\Exceptions\CredentialResolutionFailedException;
use Adn\WebTerm\Credentials\Exceptions\NoCredentialConfiguredException;
use Adn\WebTerm\Credentials\ResolvedCredential;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Vault KV v2, for devices that cannot use certificates.
 *
 * Better than storing the secret in LibreNMS -- central rotation, real audit,
 * one place to revoke -- but the secret is still reusable while it exists, and
 * the documentation says so rather than presenting KV as equivalent to the
 * signer.
 */
final class KvV2Engine
{
    /**
     * Placeholders permitted in a path template. Deliberately small: a template
     * language here would be a path-traversal surface into a secret store.
     */
    private const PLACEHOLDERS = ['device_id', 'hostname', 'principal'];

    public function __construct(
        private readonly VaultTransport $transport,
        private readonly string $mount = 'secret',
        private readonly string $pathTemplate = 'librenms/devices/{device_id}',
        /** @var array<string, string> */
        private readonly array $fieldMap = ['password' => 'password', 'username' => 'username'],
    ) {}

    /**
     * @param  array<string, string>  $context
     */
    public function read(CredentialRequest $request, #[SensitiveParameter] string $token, array $context = []): ResolvedCredential
    {
        $path = $this->resolvePath(array_merge([
            'device_id' => (string) $request->deviceId,
            'principal' => $request->principal,
        ], $context));

        // KV v2 reads go through /data/, which is the single most common
        // configuration mistake with this engine -- so the driver inserts it
        // rather than asking the operator to remember.
        $full = sprintf('%s/data/%s', trim($this->mount, '/'), $path);

        try {
            $response = $this->transport->get($full, $token);
        } catch (VaultNotFoundException) {
            throw new NoCredentialConfiguredException(sprintf(
                'No secret at %s. Create it with: vault kv put %s/%s password=...',
                $full,
                trim($this->mount, '/'),
                $path
            ));
        }

        // KV v2 nests the payload one level deeper than KV v1.
        $data = (array) (($response['data'] ?? [])['data'] ?? []);

        $passwordField = $this->fieldMap['password'] ?? 'password';
        $keyField = $this->fieldMap['private_key'] ?? 'private_key';

        if (isset($data[$keyField]) && is_string($data[$keyField]) && $data[$keyField] !== '') {
            return new ResolvedCredential(
                CredentialMethod::PrivateKey,
                $request->principal,
                array_filter([
                    'private_key' => (string) $data[$keyField],
                    'passphrase' => isset($data['passphrase']) ? (string) $data['passphrase'] : null,
                ], static fn ($v): bool => $v !== null),
            );
        }

        if (! isset($data[$passwordField]) || ! is_string($data[$passwordField]) || $data[$passwordField] === '') {
            throw new CredentialResolutionFailedException(sprintf(
                'The secret at %s has no "%s" field.',
                $full,
                $passwordField
            ));
        }

        return new ResolvedCredential(
            CredentialMethod::Password,
            $request->principal,
            ['password' => (string) $data[$passwordField]],
        );
    }

    /**
     * Expand the path template.
     *
     * Every substituted value is validated and every segment is URL-encoded. A
     * hostname is attacker-influenceable in some environments, and an
     * unvalidated one could otherwise walk out of the intended prefix and read
     * another application's secrets.
     *
     * @param  array<string, string>  $values
     */
    public function resolvePath(array $values): string
    {
        $path = $this->pathTemplate;

        foreach (self::PLACEHOLDERS as $placeholder) {
            $value = $values[$placeholder] ?? '';

            if (! str_contains($path, '{'.$placeholder.'}')) {
                continue;
            }

            if ($value === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot build a Vault path: "%s" is not a usable value for {%s}.',
                    $value,
                    $placeholder
                ));
            }

            $path = str_replace('{'.$placeholder.'}', rawurlencode($value), $path);
        }

        $path = trim($path, '/');

        // Belt and braces: even with validated placeholders, a hand-written
        // template could contain these.
        foreach (['..', '//', '?', '#'] as $forbidden) {
            if (str_contains($path, $forbidden)) {
                throw new InvalidArgumentException(sprintf(
                    'The Vault path template produces an unsafe path (%s): %s',
                    $forbidden,
                    $path
                ));
            }
        }

        if (preg_match('/\{[a-z_]+\}/', $path) === 1) {
            throw new InvalidArgumentException(
                'The Vault path template contains a placeholder that WebTerm does not provide. '
                .'Supported: '.implode(', ', array_map(static fn ($p): string => '{'.$p.'}', self::PLACEHOLDERS))
            );
        }

        return $path;
    }
}
