<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials;

use DateTimeImmutable;
use JsonSerializable;
use LogicException;
use RuntimeException;
use SensitiveParameter;

/**
 * A credential resolved for exactly one session.
 *
 * WHY THIS IS NOT JUST AN ARRAY OR A DTO
 * --------------------------------------
 * Credential material reaches this object and must leave it exactly once, on
 * its way to the gateway over loopback. Everywhere else -- a stack trace under
 * APP_DEBUG, a `dd()` left in a controller, a queued job payload, a cached
 * response, a support bundle -- it must be unrecoverable.
 *
 * The obvious approach is to hold the secret in a closure. That does not work:
 * PHP exposes a closure's bound variables under `[static]`, so `print_r` and
 * `var_dump` both print the secret in full. This was verified, not assumed.
 *
 * So the secret is held in a private static registry keyed by an opaque
 * reference, and the instance carries only that reference. Every dump, export,
 * encode and serialize path then sees the reference and nothing else. The
 * accompanying test asserts this against print_r, var_export, var_dump,
 * json_encode, serialize, string casts, exception traces and get_object_vars.
 */
final class ResolvedCredential implements JsonSerializable
{
    /**
     * Secrets live here rather than on the instance, so that no dump of the
     * instance can reach them.
     *
     * @var array<string, array<string, string>>
     */
    private static array $vault = [];

    private readonly string $ref;

    /**
     * @param  array<string, string>  $secrets  Keyed by the wire field name.
     */
    public function __construct(
        public readonly CredentialMethod $method,
        public readonly string $username,
        #[SensitiveParameter] array $secrets,
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {
        if ($username === '') {
            throw new LogicException('ResolvedCredential requires a username.');
        }

        foreach (array_keys($secrets) as $key) {
            if (! in_array($key, $method->secretKeys(), true)) {
                throw new LogicException(sprintf(
                    'Secret key "%s" is not valid for method "%s".',
                    $key,
                    $method->value
                ));
            }
        }

        $this->ref = bin2hex(random_bytes(16));
        self::$vault[$this->ref] = $secrets;
    }

    /**
     * Hand the secrets over. The only legitimate caller is the gateway client,
     * immediately before writing them to the loopback socket.
     *
     * @return array<string, string>
     */
    public function reveal(): array
    {
        return self::$vault[$this->ref]
            ?? throw new RuntimeException('This credential has already been consumed.');
    }

    /**
     * Erase the secrets. Called once the handoff has completed; also called
     * from the destructor so that an abandoned credential does not linger for
     * the lifetime of the request.
     */
    public function consume(): void
    {
        unset(self::$vault[$this->ref]);
    }

    public function isConsumed(): bool
    {
        return ! isset(self::$vault[$this->ref]);
    }

    public function __destruct()
    {
        $this->consume();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    /**
     * Serializing a credential is always a mistake -- into a queue payload, a
     * cache entry or a session. Fail loudly rather than persist it.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('ResolvedCredential must never be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('ResolvedCredential must never be unserialized.');
    }

    public function __toString(): string
    {
        return '[redacted credential]';
    }

    /**
     * Cloning would produce a second object sharing one vault entry, whose
     * destructor would erase the original's secrets.
     */
    public function __clone()
    {
        throw new LogicException('ResolvedCredential must not be cloned.');
    }

    /** @return array<string, mixed> */
    private function redacted(): array
    {
        return [
            'method' => $this->method->value,
            'username' => $this->username,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'secrets' => '[redacted]',
            'consumed' => $this->isConsumed(),
        ];
    }
}
