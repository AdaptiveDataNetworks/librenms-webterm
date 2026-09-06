<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Credentials\Vault;

use AdaptiveDataNetworks\WebTerm\Credentials\CredentialMethod;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialRequest;
use AdaptiveDataNetworks\WebTerm\Credentials\Exceptions\CredentialResolutionFailedException;
use AdaptiveDataNetworks\WebTerm\Credentials\ResolvedCredential;
use SensitiveParameter;

/**
 * Vault's SSH secrets engine, certificate-signing mode.
 *
 * The reason to run Vault at all. The gateway generates a keypair per session
 * and Vault signs the public half; nothing reusable is stored anywhere, devices
 * hold a CA public key instead of per-user secrets, and onboarding or removing
 * an operator is a policy change rather than a password rotation across the
 * estate.
 */
final class SshSignerEngine
{
    public function __construct(
        private readonly VaultTransport $transport,
        private readonly string $mount = 'ssh-client-signer',
        private readonly string $role = 'librenms-webterm',
        private readonly string $ttl = '30m',
    ) {}

    public function sign(CredentialRequest $request, #[SensitiveParameter] string $token): ResolvedCredential
    {
        if ($request->clientPublicKey === null || $request->clientPublicKey === '') {
            throw new CredentialResolutionFailedException(
                'The gateway did not supply a public key to sign. This flow requires a gateway '
                .'that supports the signed-certificate method.'
            );
        }

        $response = $this->transport->post(
            sprintf('%s/sign/%s', trim($this->mount, '/'), rawurlencode($this->role)),
            [
                'public_key' => $request->clientPublicKey,
                // Server-derived. Never taken from the browser, and never from
                // the requesting user's own input: valid_principals is what the
                // device's sshd authorises against.
                'valid_principals' => $request->principal,
                'ttl' => $this->ttl,
                // key_id lands in the device's auth log. Composed only from
                // values the server controls.
                //
                // Be clear about what this is: an attestation by the LibreNMS
                // host about who asked, not cryptographic proof of identity.
                // An attacker who controls LibreNMS can write any name here.
                // The authoritative record is Vault's own audit device, on a
                // system the LibreNMS administrator does not necessarily run.
                'key_id' => $this->keyId($request),
            ],
            $token
        );

        $data = (array) ($response['data'] ?? []);
        $certificate = (string) ($data['signed_key'] ?? '');

        if ($certificate === '') {
            throw new CredentialResolutionFailedException('Vault returned no signed certificate.');
        }

        return new ResolvedCredential(
            CredentialMethod::SignedCertificate,
            $request->principal,
            ['certificate' => trim($certificate)],
        );
    }

    private function keyId(CredentialRequest $request): string
    {
        $username = $request->user->username ?? $request->user->name ?? null;
        $username = is_string($username) ? $username : (string) $request->user->getAuthIdentifier();

        // Constrained charset: this string is written to a remote auth log, and
        // log files are read in terminals.
        $safe = static fn (string $v): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $v);

        return sprintf(
            'librenms-webterm:%s:%d',
            $safe($username),
            $request->deviceId
        );
    }
}
