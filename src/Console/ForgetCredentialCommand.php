<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Console\Concerns\ResolvesDevices;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use Illuminate\Console\Command;

/**
 * Delete a stored credential.
 *
 * There was no way to remove one. `webterm:credentials:set` could only
 * overwrite, so an operator who stored a password against the wrong device --
 * or who wanted it gone after moving to Vault -- had to go into the database by
 * hand. A credential store you cannot empty is not one anybody should trust.
 */
final class ForgetCredentialCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:credentials:forget
        {--device= : Hostname or id}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete the stored SSH credential for a device';

    public function handle(AuditLogger $audit): int
    {
        $reference = (string) $this->option('device');
        $device = $this->findDevice($reference);

        // A credential outlives its device: nothing links webterm_credentials
        // to LibreNMS's devices table (deliberately -- no foreign keys into
        // core), so deleting a device in LibreNMS leaves the stored secret
        // behind. Refusing to act without a device record made those rows
        // impossible to remove with this tool, which is the wrong answer for a
        // command whose entire job is getting rid of a credential.
        $deviceId = $device !== null ? (int) $device->device_id : null;

        if ($deviceId === null && ctype_digit($reference)) {
            $deviceId = (int) $reference;
        }

        if ($deviceId === null) {
            $this->error(sprintf('No such device: %s', $reference));
            $this->line('  If the device has been removed from LibreNMS, pass its numeric id.');

            return self::FAILURE;
        }

        $orphaned = $device === null;
        $credential = Credential::query()->where('device_id', $deviceId)->where('protocol', 'ssh')->first();

        if ($credential === null) {
            $this->info(sprintf('No credential stored for %s.', $device->hostname ?? $deviceId));

            return self::SUCCESS;
        }

        if ($orphaned) {
            $this->warn(sprintf('Device %d no longer exists in LibreNMS; removing its orphaned credential.', $deviceId));
        }

        if (! $this->option('force') && ! $this->confirm(
            sprintf(
                'Delete the stored %s credential for %s (login "%s")? Sessions will fail until one is set again.',
                $credential->method,
                $device->hostname ?? $deviceId,
                $credential->username
            ),
            false
        )) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $method = (string) $credential->method;
        $username = (string) $credential->username;

        $credential->delete();

        // Recorded before we report success, and flagged security-relevant so
        // it leaves the host before it can be edited out of the local table.
        $audit->log(Event::CredentialRemoved, deviceId: $deviceId, detail: [
            'method' => $method,
            'username' => $username,
        ]);

        $this->info(sprintf('Removed the stored credential for %s.', $device->hostname ?? $deviceId));

        return self::SUCCESS;
    }
}
