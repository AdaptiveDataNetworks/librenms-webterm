<?php

declare(strict_types=1);

namespace Adn\WebTerm\Credentials\Drivers;

use Adn\WebTerm\Credentials\Contracts\CredentialProvider;
use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\Credentials\CredentialRequest;
use Adn\WebTerm\Credentials\ProviderHealth;
use Adn\WebTerm\Credentials\ResolvedCredential;
use Adn\WebTerm\Credentials\Vault\KvV2Engine;
use Adn\WebTerm\Credentials\Vault\SshSignerEngine;
use Adn\WebTerm\Credentials\Vault\TokenManager;
use Adn\WebTerm\Credentials\Vault\VaultPermissionDeniedException;
use Adn\WebTerm\Credentials\Vault\VaultTransport;
use Adn\WebTerm\Models\Target;
use SensitiveParameter;
use Throwable;

/**
 * HashiCorp Vault credential provider.
 *
 * Which engine is used is pinned PER TARGET, never inferred at connect time
 * from the device's detected OS. Inference would mean a device could silently
 * move from a 30-minute certificate to a reusable password because discovery
 * changed its os field -- breaking the security claim of the whole deployment
 * without anyone changing a setting.
 */
final class VaultCredentialProvider implements CredentialProvider
{
    public function __construct(
        private readonly ?VaultTransport $transport = null,
        private readonly ?TokenManager $tokens = null,
        private readonly ?SshSignerEngine $signer = null,
        private readonly ?KvV2Engine $kv = null,
    ) {}

    public function name(): string
    {
        return 'vault';
    }

    public function capabilities(): array
    {
        return [
            CredentialMethod::SignedCertificate,
            CredentialMethod::Password,
            CredentialMethod::PrivateKey,
        ];
    }

    public function resolve(CredentialRequest $request): ResolvedCredential
    {
        $tokens = $this->tokens();
        $token = $tokens->token();

        try {
            return $this->resolveWith($request, $token);
        } catch (VaultPermissionDeniedException $e) {
            // A 403 can mean an expired or revoked token rather than a bad
            // policy. Re-authenticate ONCE and try again; retrying forever
            // would turn a misconfigured policy into a login storm against
            // Vault.
            $tokens->forget();

            return $this->resolveWith($request, $tokens->token());
        }
    }

    private function resolveWith(CredentialRequest $request, #[SensitiveParameter] string $token): ResolvedCredential
    {
        return match ($this->flowFor($request->deviceId)) {
            'ssh_signer' => $this->signer()->sign($request, $token),
            default => $this->kv()->read($request, $token),
        };
    }

    public function health(): ProviderHealth
    {
        try {
            $this->tokens()->token();
        } catch (Throwable $e) {
            return ProviderHealth::failing(
                'Vault is not usable: '.$e->getMessage(),
                'See the Vault troubleshooting guide: https://adn.github.io/librenms-webterm/vault/troubleshooting/'
            );
        }

        return ProviderHealth::ok('Vault reachable and authenticated.');
    }

    private function flowFor(int $deviceId): string
    {
        $target = Target::query()
            ->where('device_id', $deviceId)
            ->where('protocol', 'ssh')
            ->first();

        if ($target === null) {
            // No pinned target means no flow decision has been made, so take
            // the conservative one: KV, which cannot be mistaken for the
            // stronger guarantee a certificate provides.
            return 'kv2';
        }

        return $target->flow;
    }

    private function transport(): VaultTransport
    {
        return $this->transport ?? new VaultTransport(
            (string) config('webterm.credentials.vault.address'),
            config('webterm.credentials.vault.namespace'),
            (bool) config('webterm.credentials.vault.tls.verify', true),
            config('webterm.credentials.vault.ca_cert'),
        );
    }

    private function tokens(): TokenManager
    {
        return $this->tokens ?? new TokenManager(
            $this->transport(),
            (string) config('webterm.credentials.vault.auth', 'agent'),
            (array) config('webterm.credentials.vault.'.config('webterm.credentials.vault.auth', 'agent'), []),
        );
    }

    private function signer(): SshSignerEngine
    {
        return $this->signer ?? new SshSignerEngine(
            $this->transport(),
            (string) config('webterm.credentials.vault.ssh_signer.mount', 'ssh-client-signer'),
            (string) config('webterm.credentials.vault.ssh_signer.role', 'librenms-webterm'),
            (string) config('webterm.credentials.vault.ssh_signer.ttl', '30m'),
        );
    }

    private function kv(): KvV2Engine
    {
        return $this->kv ?? new KvV2Engine(
            $this->transport(),
            (string) config('webterm.credentials.vault.kv2.mount', 'secret'),
            (string) config('webterm.credentials.vault.kv2.path_template', 'librenms/devices/{device_id}'),
            (array) config('webterm.credentials.vault.kv2.field_map', ['password' => 'password']),
        );
    }
}
