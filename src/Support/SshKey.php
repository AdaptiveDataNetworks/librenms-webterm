<?php

declare(strict_types=1);

namespace Adn\WebTerm\Support;

/**
 * OpenSSH public-key fingerprinting.
 *
 * Forty lines of PHP rather than a round trip to the gateway. An earlier design
 * had the plugin POST a key to the gateway to be fingerprinted, which created a
 * second channel carrying key material purely to render a cosmetic string in
 * the admin UI. Not worth the exposure.
 */
final class SshKey
{
    /**
     * SHA256 fingerprint in OpenSSH's display form, e.g.
     * "SHA256:jZ2f...". Accepts a full authorized_keys line or a bare blob.
     */
    public static function fingerprint(string $publicKey): ?string
    {
        $blob = self::decodeBlob($publicKey);
        if ($blob === null) {
            return null;
        }

        // OpenSSH prints the base64 of the raw digest, without padding.
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * The key type as declared inside the blob itself, e.g. 'ssh-ed25519'.
     *
     * Read from the blob rather than trusting the text prefix: the two can
     * disagree, and only the blob is what an SSH server actually parses.
     */
    public static function type(string $publicKey): ?string
    {
        $blob = self::decodeBlob($publicKey);
        if ($blob === null || strlen($blob) < 4) {
            return null;
        }

        $length = unpack('N', substr($blob, 0, 4));
        if ($length === false) {
            return null;
        }

        $length = $length[1];
        if ($length <= 0 || $length > 64 || strlen($blob) < 4 + $length) {
            return null;
        }

        $type = substr($blob, 4, $length);

        return preg_match('/^[a-z0-9@.\-]+$/i', $type) === 1 ? $type : null;
    }

    private static function decodeBlob(string $publicKey): ?string
    {
        $parts = preg_split('/\s+/', trim($publicKey)) ?: [];

        foreach ($parts as $part) {
            if ($part === '' || ! preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $part) || strlen($part) < 16) {
                continue;
            }

            $decoded = base64_decode($part, true);
            if ($decoded !== false && $decoded !== '' && strlen($decoded) > 4) {
                return $decoded;
            }
        }

        return null;
    }
}
