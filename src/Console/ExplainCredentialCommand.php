<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\GroupSource;
use AdaptiveDataNetworks\WebTerm\Console\Concerns\ResolvesDevices;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use Illuminate\Console\Command;

/**
 * Say which stored credential a device will actually use, and why.
 *
 * Scoped credentials buy an operator one command instead of two hundred, and
 * cost them the ability to know what any given device will do. That trade is
 * only acceptable if the answer is one command away, so this ships with the
 * feature rather than after it.
 *
 * It prints every candidate in precedence order, marks the winner, and names
 * the ones that lost -- an operator debugging "why is this device using the
 * wrong login" needs to see the row that beat theirs, not just the result.
 */
final class ExplainCredentialCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:credentials:explain
        {--device= : Hostname or id}';

    protected $description = 'Show which stored credential a device resolves to, and what it beat';

    public function handle(GroupSource $groups): int
    {
        $reference = (string) $this->option('device');
        $device = $this->findDevice($reference);

        if ($device === null) {
            $this->error(sprintf('No such device: %s', $reference));

            return self::FAILURE;
        }

        $deviceId = (int) $device->device_id;
        $groupIds = $groups->staticGroupIdsFor($device);

        $this->line('');
        $this->line(sprintf('  <options=bold>%s</> (device %d)', $device->hostname ?? $deviceId, $deviceId));
        $this->line(sprintf('  static groups: %s', $groupIds === [] ? 'none' : implode(', ', $groupIds)));

        $principal = Target::query()
            ->where('device_id', $deviceId)
            ->where('protocol', 'ssh')
            ->value('principal');

        $this->line(sprintf('  connects as:   %s', $principal ?? '<no target enabled>'));
        $this->line('');

        $candidates = Credential::candidatesFor($deviceId, $groupIds)->get();

        if ($candidates->isEmpty()) {
            $this->warn('  No stored credential applies to this device.');
            $this->line('    ./lnms webterm:credentials:set --device='.($device->hostname ?? $deviceId).' --username=<login>');
            $this->line('    ./lnms webterm:credentials:set --global --username=<login>');
            $this->line('');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($candidates as $i => $row) {
            $rows[] = [
                $i === 0 ? '-> USED' : 'overridden',
                $row->scope_type->label($row->scope_ref),
                $row->method,
                $row->username,
            ];
        }

        $this->table(['', 'Scope', 'Method', 'Stored as'], $rows);

        $winner = $candidates->first();

        if ($principal !== null && $winner->username !== $principal) {
            $this->warn(sprintf(
                '  The winning credential is stored as "%s" but this device connects as "%s".',
                $winner->username,
                $principal
            ));
            $this->line('  The principal is what SSH uses; the stored username is only a label.');
        }

        if ($candidates->count() > 1) {
            $this->line('  Most specific wins: device, then group, then global.');
        }

        $this->line('');

        return self::SUCCESS;
    }
}
