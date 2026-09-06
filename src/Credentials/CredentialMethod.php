<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Credentials;

/**
 * How the gateway will authenticate to the device.
 *
 * The values are the wire strings in protocol/protocol.json; changing one is a
 * protocol change, not a refactor.
 */
enum CredentialMethod: string
{
    case SignedCertificate = 'signed_certificate';
    case Password = 'password';
    case PrivateKey = 'private_key';

    /**
     * True when the secret would still work if it leaked -- i.e. it is not
     * bounded by a short-lived certificate.
     *
     * This drives a real policy decision: trust-on-first-use host key
     * acceptance is refused for reusable secrets, because TOFU means handing
     * the credential to whatever answers, and a reusable secret handed to an
     * impostor is a lasting compromise rather than a 30-minute one.
     */
    public function isReusable(): bool
    {
        return match ($this) {
            self::SignedCertificate => false,
            self::Password, self::PrivateKey => true,
        };
    }

    /**
     * The secret field names this method carries on the wire.
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return match ($this) {
            self::SignedCertificate => ['certificate'],
            self::Password => ['password'],
            self::PrivateKey => ['private_key', 'passphrase'],
        };
    }
}
