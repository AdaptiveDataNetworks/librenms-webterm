<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\HostKeys\HostKeyManager;
use Illuminate\Console\Command;

/**
 * Forget a device's pinned host keys.
 *
 * Requires a reason, which is written to the audit trail. Clearing a pin is
 * exactly what an attacker would want done after substituting a device, so the
 * record of who did it and why is the control.
 */
final class HostKeyResetCommand extends Command
{
    protected $signature = 'webterm:hostkey-reset
        {--device= : Device id}
        {--reason= : Why the pin is being cleared (recorded in the audit trail)}';

    protected $description = 'Clear pinned SSH host keys for a device';

    public function handle(HostKeyManager $keys): int
    {
        $deviceId = (int) $this->option('device');
        $reason = trim((string) $this->option('reason'));

        if ($deviceId === 0) {
            $this->error('--device is required.');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('--reason is required; it is written to the audit trail.');

            return self::FAILURE;
        }

        $count = $keys->reset($deviceId, $reason);

        $this->info(sprintf('Cleared %d pinned key(s) for device %d.', $count, $deviceId));
        $this->warn('The next connection will re-establish trust. Verify the new fingerprint against the device.');

        return self::SUCCESS;
    }
}
