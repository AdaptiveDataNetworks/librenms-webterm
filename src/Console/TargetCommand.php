<?php

declare(strict_types=1);

namespace Adn\WebTerm\Console;

use Adn\WebTerm\Console\Concerns\ResolvesDevices;
use Adn\WebTerm\Librenms\DeviceTarget;
use Adn\WebTerm\Models\Target;
use Illuminate\Console\Command;

/**
 * Enable or disable a device for terminal access.
 *
 * Validates the dial address at enable time rather than leaving the operator to
 * find out at 3am that the gateway cannot resolve a hostname.
 */
final class TargetCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:target:enable
        {--device= : Hostname or id}
        {--principal= : SSH username to connect as}
        {--flow=database : database|ssh_signer|kv2|private_key}
        {--policy=pin : pin|tofu_first_connect}
        {--profile=modern : modern|legacy}
        {--disable : Disable instead of enabling}';

    protected $description = 'Enable a device for WebTerm terminal access';

    public function handle(DeviceTarget $targets): int
    {
        $device = $this->findDevice((string) $this->option('device'));
        if ($device === null) {
            $this->error(sprintf('No such device: %s', $this->option('device')));

            return self::FAILURE;
        }

        $deviceId = (int) $device->device_id;

        if ($this->option('disable')) {
            Target::query()->where('device_id', $deviceId)->update(['enabled' => false]);
            $this->info(sprintf('Disabled %s.', $device->hostname ?? $deviceId));

            return self::SUCCESS;
        }

        // Fail here rather than at connect time. The gateway dials IP literals
        // only, and a device with nothing but a DNS name will never work.
        $dial = $targets->resolve($device);
        if ($dial === null) {
            $this->error(sprintf('%s cannot be targeted: %s', $device->hostname ?? $deviceId, (string) $targets->rejectionReason($device)));

            return self::FAILURE;
        }

        $principal = (string) $this->option('principal');
        if ($principal === '') {
            $principal = (string) $this->ask('SSH username to connect as');
        }

        if ($principal === '') {
            $this->error('A principal (SSH username) is required.');

            return self::FAILURE;
        }

        Target::query()->updateOrCreate(
            ['device_id' => $deviceId, 'protocol' => 'ssh'],
            [
                'enabled' => true,
                'principal' => $principal,
                'flow' => (string) $this->option('flow'),
                'host_key_policy' => (string) $this->option('policy'),
                'algorithm_profile' => (string) $this->option('profile'),
            ]
        );

        $this->info(sprintf('Enabled %s (%s) as %s.', $device->hostname ?? $deviceId, $dial, $principal));

        if ((string) $this->option('policy') === Target::POLICY_PIN) {
            $this->line('');
            $this->line('  No connection will succeed until a host key is pinned:');
            $this->line('    ./lnms webterm:hostkey-scan --device='.($device->hostname ?? $deviceId));
        }

        return self::SUCCESS;
    }
}
