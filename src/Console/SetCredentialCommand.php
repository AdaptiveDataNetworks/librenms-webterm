<?php

declare(strict_types=1);

namespace Adn\WebTerm\Console;

use Adn\WebTerm\Console\Concerns\ResolvesDevices;
use Adn\WebTerm\Credentials\CredentialEncrypter;
use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\Models\Credential;
use Adn\WebTerm\Support\SshKey;
use Illuminate\Console\Command;

/**
 * Store a device credential for the database driver.
 *
 * The secret is always prompted for, never taken as an argument: arguments land
 * in shell history and in `ps` output for every user on the machine.
 */
final class SetCredentialCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:credentials:set
        {--device= : Hostname or id}
        {--username= : SSH username}
        {--key-file= : Path to a private key file, instead of a password}';

    protected $description = 'Store an encrypted SSH credential for a device';

    public function handle(): int
    {
        $device = $this->findDevice((string) $this->option('device'));
        if ($device === null) {
            $this->error(sprintf('No such device: %s', $this->option('device')));

            return self::FAILURE;
        }

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

        Credential::query()->updateOrCreate(
            ['device_id' => (int) $device->device_id, 'protocol' => 'ssh'],
            [
                'method' => $method->value,
                'username' => $username,
                'payload' => $encrypter->encrypt($secrets),
                'cipher' => $encrypter->cipher(),
                'key_id' => $encrypter->keyId(),
                'fingerprint' => $fingerprint,
            ]
        );

        $this->info(sprintf('Stored an encrypted %s credential for %s.', $method->value, $device->hostname ?? $device->device_id));
        $this->line('');
        $this->line('  This secret is reusable and now lives in your monitoring database.');
        $this->line('  For anything beyond a small estate, consider Vault:');
        $this->line('    https://adn.github.io/librenms-webterm/vault/');

        return self::SUCCESS;
    }
}
