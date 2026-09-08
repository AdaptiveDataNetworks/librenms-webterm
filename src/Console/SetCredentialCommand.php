<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Console\Concerns\ResolvesCredentialScope;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialEncrypter;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialMethod;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Support\SshKey;
use Illuminate\Console\Command;

/**
 * Store a device credential for the database driver.
 *
 * The secret is always prompted for, never taken as an argument: arguments land
 * in shell history and in `ps` output for every user on the machine.
 */
final class SetCredentialCommand extends Command
{
    use ResolvesCredentialScope;

    protected $signature = 'webterm:credentials:set
        {--device= : Hostname or id}
        {--group= : LibreNMS device group id, for a credential shared by that group}
        {--global : Store the fleet-wide default credential}
        {--username= : SSH username}
        {--key-file= : Path to a private key file, instead of a password}';

    protected $description = 'Store an encrypted SSH credential for a device';

    public function handle(AuditLogger $audit): int
    {
        $scope = $this->credentialScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        [$scopeType, $scopeRef, $scopeLabel] = $scope;

        $username = (string) ($this->option('username') ?: $this->ask('SSH username'));
        if ($username === '') {
            $this->error('A username is required.');

            return self::FAILURE;
        }

        $keyFile = (string) $this->option('key-file');

        if ($keyFile !== '') {
            if (! is_readable($keyFile)) {
                $this->error(sprintf('Cannot read %s.', $keyFile));

                return self::FAILURE;
            }

            $privateKey = (string) file_get_contents($keyFile);
            $passphrase = (string) $this->secret('Key passphrase (blank if none)');

            $secrets = array_filter([
                'private_key' => $privateKey,
                'passphrase' => $passphrase !== '' ? $passphrase : null,
            ], static fn ($v): bool => $v !== null);

            $method = CredentialMethod::PrivateKey;
            $fingerprint = SshKey::fingerprint($privateKey);
        } else {
            // secret() does not echo, and the value never becomes an argv entry.
            $password = (string) $this->secret('SSH password');
            if ($password === '') {
                $this->error('A password is required.');

                return self::FAILURE;
            }

            $secrets = ['password' => $password];
            $method = CredentialMethod::Password;
            $fingerprint = null;
        }

        $encrypter = new CredentialEncrypter;

        // The login the device actually sees is the target's principal. Nothing
        // has ever checked that the two agree, so a typo here surfaced only as
        // an authentication failure against real equipment. Only meaningful for
        // a device-scoped credential -- a shared one deliberately spans devices
        // whose principals may differ, which webterm:credentials:explain shows.
        $principal = $scopeType === CredentialScope::Device
            ? Target::query()->where('device_id', $scopeRef)->where('protocol', 'ssh')->value('principal')
            : null;

        Credential::query()->updateOrCreate(
            ['scope_type' => $scopeType->value, 'scope_ref' => $scopeRef, 'protocol' => 'ssh'],
            [
                'method' => $method->value,
                'username' => $username,
                'payload' => $encrypter->encrypt($secrets),
                'cipher' => $encrypter->cipher(),
                'key_id' => $encrypter->keyId(),
                'fingerprint' => $fingerprint,
            ]
        );

        $audit->log(
            Event::CredentialStored,
            deviceId: $scopeType === CredentialScope::Device ? $scopeRef : null,
            detail: ['scope' => $scopeType->value, 'scope_ref' => $scopeRef, 'method' => $method->value, 'username' => $username]
        );

        $this->info(sprintf('Stored an encrypted %s credential for %s.', $method->value, $scopeLabel));

        if ($principal !== null && $principal !== $username) {
            $this->line('');
            $this->warn(sprintf(
                'This device connects as "%s", not "%s" -- the target principal wins.',
                $principal,
                $username
            ));
            $this->line('  Fix whichever is wrong:');
            $this->line(sprintf('    ./lnms webterm:target:enable --device=%s --principal=%s', $scopeRef, $username));
            $this->line(sprintf('    ./lnms webterm:credentials:set --device=%s --username=%s', $scopeRef, $principal));
        }

        if ($scopeType !== CredentialScope::Device) {
            $this->line('');
            $this->line(sprintf('  This applies to %s, and is overridden by any device-scoped credential.', $scopeLabel));
            $this->line('  Check what a given device will use:');
            $this->line('    ./lnms webterm:credentials:explain --device=<device>');
        }

        $this->line('');
        $this->line('  This secret is reusable and now lives in your monitoring database.');
        $this->line('  For anything beyond a small estate, consider Vault:');
        $this->line('    https://adaptivedatanetworks.github.io/librenms-webterm/vault/');

        return self::SUCCESS;
    }
}
