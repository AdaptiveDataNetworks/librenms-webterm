<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\HostKeys\HostKeyManager;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTarget;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record the SSH host keys of enabled devices.
 *
 * Prints the fingerprint and requires confirmation before pinning, because
 * pinning a key nobody checked is trust-on-first-use with extra steps. The
 * documentation says to compare against what the device itself reports, and
 * this is the moment to do it.
 */
final class HostKeyScanCommand extends Command
{
    protected $signature = 'webterm:hostkey-scan
        {--device= : Device id or hostname, or all enabled targets if omitted}
        {--accept : Pin without prompting (for automation; you accept the risk)}';

    protected $description = 'Scan and pin SSH host keys for WebTerm targets';

    public function handle(HostKeyManager $keys, DeviceTarget $targets): int
    {
        $model = 'App\Models\Device';
        if (! class_exists($model)) {
            $this->error('LibreNMS core is not available.');

            return self::FAILURE;
        }

        $rows = Target::query()->enabled()->get();

        if ($device = $this->option('device')) {
            $rows = $rows->filter(function (Target $t) use ($device, $model): bool {
                $d = $model::query()->find($t->device_id);

                return (string) $t->device_id === (string) $device
                    || ($d !== null && (string) ($d->hostname ?? '') === (string) $device);
            });
        }

        if ($rows->isEmpty()) {
            $this->warn('No enabled targets matched.');

            return self::SUCCESS;
        }

        $pinned = 0;
        $failed = 0;

        foreach ($rows as $target) {
            $device = $model::query()->find($target->device_id);
            if ($device === null) {
                continue;
            }

            $dial = $targets->resolve($device);
            if ($dial === null) {
                $this->error(sprintf('%s: %s', $device->hostname ?? $target->device_id, (string) $targets->rejectionReason($device)));
                $failed++;

                continue;
            }

            try {
                $found = $keys->scan($dial->ip, $dial->port);
            } catch (Throwable $e) {
                $this->error(sprintf('%s: %s', $device->hostname ?? $dial->ip, $e->getMessage()));
                $failed++;

                continue;
            }

            foreach ($found as $key) {
                $this->line(sprintf(
                    '<info>%s</info> (%s)  %s  %s',
                    $device->hostname ?? '',
                    $dial->ip,
                    $key['algorithm'],
                    $key['fingerprint']
                ));

                if (! $this->option('accept')
                    && ! $this->confirm('Compare this against the device itself. Pin it?', false)) {
                    continue;
                }

                $keys->pin($target->device_id, $key);
                $pinned++;
            }
        }

        $this->info(sprintf('Pinned %d key(s).%s', $pinned, $failed > 0 ? sprintf(' %d device(s) failed.', $failed) : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
