<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Console\Concerns\ResolvesDevices;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialEncrypter;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use Illuminate\Console\Command;

/**
 * Show what is stored, without showing any of it.
 *
 * There was no way to see this at all: an operator could write a credential and
 * then had no means of asking what was recorded, for which device, under which
 * login, or whether it still decrypts with the current key. "Set it again and
 * hope" was the only recourse, which is precisely the opacity that makes a
 * CLI-only surface feel unusable.
 *
 * No secret, and no fragment of one, is printed here. The fingerprint column is
 * a public key fingerprint, which is not sensitive; the payload is never read.
 */
final class ListCredentialsCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:credentials:list
        {--device= : Limit to one device, by hostname or id}';

    protected $description = 'List stored SSH credentials, and what they will be used for';

    public function handle(): int
    {
        $query = Credential::query()->orderBy('device_id');

        if ((string) $this->option('device') !== '') {
            $device = $this->findDevice((string) $this->option('device'));
            if ($device === null) {
                $this->error(sprintf('No such device: %s', $this->option('device')));

                return self::FAILURE;
            }

            $query->where('device_id', (int) $device->device_id);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No credentials stored.');
            $this->line('  Add one with: ./lnms webterm:credentials:set --device=<device> --username=<login>');

            return self::SUCCESS;
        }

        $currentKey = (new CredentialEncrypter)->keyId();

        // The SSH login actually used is the target's principal, not the
        // credential's username -- nothing has ever validated that the two
        // agree, so a mismatch is silent until a device rejects the login.
        $principals = Target::query()
            ->whereIn('device_id', $rows->pluck('device_id')->all())
            ->pluck('principal', 'device_id');

        $mismatched = 0;
        $stale = 0;
        $table = [];

        foreach ($rows as $row) {
            $principal = $principals[$row->device_id] ?? null;
            $agrees = $principal === null || $principal === $row->username;
            $current = $row->key_id === $currentKey;

            $mismatched += $agrees ? 0 : 1;
            $stale += $current ? 0 : 1;

            $table[] = [
                $this->deviceLabel((int) $row->device_id),
                $row->method,
                $row->username,
                $principal ?? '<no target>',
                $agrees ? 'yes' : 'NO',
                $current ? 'current' : 'OLD KEY',
                $row->updated_at?->toDateTimeString() ?? '-',
            ];
        }

        $this->table(
            ['Device', 'Method', 'Stored as', 'Target principal', 'Agree?', 'Key', 'Updated'],
            $table
        );

        if ($mismatched > 0) {
            $this->warn(sprintf(
                '%d credential(s) are stored under a different login than the target connects as.',
                $mismatched
            ));
            $this->line('  The target principal wins. Fix either side:');
            $this->line('    ./lnms webterm:target:enable --device=<device> --principal=<login>');
            $this->line('    ./lnms webterm:credentials:set --device=<device> --username=<login>');
        }

        if ($stale > 0) {
            $this->warn(sprintf('%d credential(s) still use a superseded encryption key.', $stale));
            $this->line('  Re-encrypt them with: ./lnms webterm:credentials:rekey');
        }

        return self::SUCCESS;
    }

    private function deviceLabel(int $deviceId): string
    {
        $device = $this->findDevice((string) $deviceId);
        $hostname = $device === null ? null : ($device->hostname ?? null);

        // Falls back to the id rather than blank: a credential can outlive the
        // device it belongs to, and that row is exactly the one an operator is
        // looking for when they run this.
        return is_string($hostname) && $hostname !== ''
            ? $hostname
            : sprintf('#%d', $deviceId);
    }
}
