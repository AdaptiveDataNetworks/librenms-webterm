<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Console\Concerns\ResolvesDevices;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialEncrypter;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use Illuminate\Console\Command;

/**
 * Show what is stored, without showing any of it.
 *
 * There was no way to see this at all: an operator could write a credential and
 * then had no means of asking what was recorded, under which login, or whether
 * it still decrypts with the current key.
 *
 * No secret, and no fragment of one, is printed. The fingerprint of a key pair
 * is public information; the payload is never read.
 */
final class ListCredentialsCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:credentials:list';

    protected $description = 'List stored SSH credentials and what each one applies to';

    public function handle(): int
    {
        $rows = Credential::query()
            ->orderByRaw("case scope_type when 'device' then 0 when 'group' then 1 else 2 end")
            ->orderBy('scope_ref')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No credentials stored.');
            $this->line('  Add one with:  ./lnms webterm:credentials:set --device=<device> --username=<login>');
            $this->line('  Or fleet-wide: ./lnms webterm:credentials:set --global --username=<login>');

            return self::SUCCESS;
        }

        $currentKey = (new CredentialEncrypter)->keyId();

        // The SSH login actually used is the target's principal, not the
        // credential's username -- nothing validates that the two agree, so a
        // mismatch is silent until a device rejects the login. Only checkable
        // for device-scoped rows; for shared rows use credentials:explain.
        $principals = Target::query()
            ->whereIn('device_id', $rows->where('scope_type', CredentialScope::Device)->pluck('scope_ref')->all())
            ->pluck('principal', 'device_id');

        $mismatched = 0;
        $stale = 0;
        $table = [];

        foreach ($rows as $row) {
            $isDevice = $row->scope_type === CredentialScope::Device;
            $principal = $isDevice ? ($principals[$row->scope_ref] ?? null) : null;
            $agrees = $principal === null || $principal === $row->username;
            $current = $row->key_id === $currentKey;

            $mismatched += $agrees ? 0 : 1;
            $stale += $current ? 0 : 1;

            $table[] = [
                $this->scopeLabel($row->scope_type, $row->scope_ref),
                $row->method,
                $row->username,
                $isDevice ? ($principal ?? '<no target>') : '-',
                $isDevice ? ($agrees ? 'yes' : 'NO') : '-',
                $current ? 'current' : 'OLD KEY',
                $row->updated_at?->toDateTimeString() ?? '-',
            ];
        }

        $this->table(
            ['Applies to', 'Method', 'Stored as', 'Target principal', 'Agree?', 'Key', 'Updated'],
            $table
        );

        $this->line('  Most specific wins: device, then group, then global.');
        $this->line('  What a given device will actually use:');
        $this->line('    ./lnms webterm:credentials:explain --device=<device>');

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

    private function scopeLabel(CredentialScope $scope, ?int $ref): string
    {
        if ($scope !== CredentialScope::Device) {
            return $scope->label($ref);
        }

        $device = $ref === null ? null : $this->findDevice((string) $ref);
        $hostname = $device === null ? null : ($device->hostname ?? null);

        // Falls back to the id rather than blank: a credential can outlive the
        // device it belongs to, and that row is exactly the one an operator is
        // looking for when they run this.
        return is_string($hostname) && $hostname !== ''
            ? $hostname
            : sprintf('device %s', $ref ?? '?');
    }
}
