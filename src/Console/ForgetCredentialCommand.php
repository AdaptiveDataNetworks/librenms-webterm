<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Console\Concerns\ResolvesCredentialScope;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
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
    use ResolvesCredentialScope;

    protected $signature = 'webterm:credentials:forget
        {--device= : Hostname or id}
        {--group= : LibreNMS device group id}
        {--global : The fleet-wide default credential}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete the stored SSH credential for a device';

    public function handle(AuditLogger $audit): int
    {
        $scope = $this->credentialScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        [$scopeType, $scopeRef, $scopeLabel] = $scope;

        $credential = Credential::query()
            ->where('scope_type', $scopeType->value)
            ->where('scope_ref', $scopeRef)
            ->where('protocol', 'ssh')
            ->first();

        if ($credential === null) {
            $this->info(sprintf('No credential stored for %s.', $scopeLabel));

            return self::SUCCESS;
        }

        // A credential outlives its device: nothing links webterm_credentials
        // to LibreNMS's devices table (deliberately -- no foreign keys into
        // core), so deleting a device leaves the stored secret behind.
        if ($scopeType === CredentialScope::Device && $this->findDevice((string) $scopeRef) === null) {
            $this->warn(sprintf('Device %d no longer exists in LibreNMS; removing its orphaned credential.', $scopeRef));
        }

        if (! $this->option('force') && ! $this->confirm(
            sprintf(
                'Delete the stored %s credential for %s (login "%s")? Sessions will fail until one is set again.',
                $credential->method,
                $scopeLabel,
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
        $audit->log(
            Event::CredentialRemoved,
            deviceId: $scopeType === CredentialScope::Device ? $scopeRef : null,
            detail: ['scope' => $scopeType->value, 'scope_ref' => $scopeRef, 'method' => $method, 'username' => $username]
        );

        $this->info(sprintf('Removed the stored credential for %s.', $scopeLabel));

        return self::SUCCESS;
    }
}
