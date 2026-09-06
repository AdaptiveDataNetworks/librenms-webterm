<?php

declare(strict_types=1);

namespace Adn\WebTerm\Console;

use Adn\WebTerm\Credentials\CredentialEncrypter;
use Adn\WebTerm\Models\Credential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-encrypt stored credentials onto the current key.
 *
 * Needed whenever APP_KEY is rotated -- routine advice that would otherwise
 * silently render every stored credential undecryptable.
 *
 * Written to be interruptible: rows are processed in chunks under a row lock,
 * each committed independently, so a crash halfway leaves a mixture of old and
 * new key_ids that the driver still reads. The alternative -- one big
 * transaction -- turns a timeout into a total outage.
 */
final class RekeyCredentialsCommand extends Command
{
    protected $signature = 'webterm:credentials:rekey
        {--from= : The previous key, if it is no longer in the environment}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Re-encrypt stored WebTerm credentials onto the current key';

    public function handle(): int
    {
        $current = new CredentialEncrypter;
        $previous = $this->option('from') !== null
            ? new CredentialEncrypter((string) $this->option('from'))
            : null;

        $dryRun = (bool) $this->option('dry-run');

        $total = Credential::query()->count();
        $stale = Credential::query()->where('key_id', '!=', $current->keyId())->count();

        $this->info(sprintf('Current key id: %s', $current->keyId()));
        $this->info(sprintf('%d credential(s), %d on an older key.', $total, $stale));

        if ($stale === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run: no changes written.');

            return self::SUCCESS;
        }

        $migrated = 0;
        $failed = [];

        Credential::query()
            ->where('key_id', '!=', $current->keyId())
            ->chunkById(100, function ($rows) use ($current, $previous, &$migrated, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        DB::transaction(function () use ($row, $current, $previous, &$migrated): void {
                            $locked = Credential::query()->lockForUpdate()->find($row->getKey());
                            if ($locked === null || $locked->key_id === $current->keyId()) {
                                return;
                            }

                            $source = $previous ?? new CredentialEncrypter;
                            $secrets = $source->decrypt($locked->payload);

                            $locked->payload = $current->encrypt($secrets);
                            $locked->cipher = $current->cipher();
                            $locked->key_id = $current->keyId();
                            $locked->save();

                            $migrated++;
                        });
                    } catch (Throwable $e) {
                        // One unreadable row must not abort the run: the other
                        // credentials still need migrating before the old key
                        // is discarded.
                        $failed[] = $row->device_id;
                    }
                }
            });

        $this->info(sprintf('Re-encrypted %d credential(s).', $migrated));

        if ($failed !== []) {
            $this->error(sprintf(
                'Could not re-encrypt %d credential(s) for device id(s): %s. '
                .'They were encrypted with a key that is no longer available; '
                .'re-enter them with webterm:credentials:set.',
                count($failed),
                implode(', ', $failed)
            ));

            return self::FAILURE;
        }

        // The run is only complete when nothing is left on an old key.
        $remaining = Credential::query()->where('key_id', '!=', $current->keyId())->count();
        if ($remaining > 0) {
            $this->error(sprintf('%d credential(s) still on an older key.', $remaining));

            return self::FAILURE;
        }

        $this->info('All credentials are on the current key.');

        return self::SUCCESS;
    }
}
