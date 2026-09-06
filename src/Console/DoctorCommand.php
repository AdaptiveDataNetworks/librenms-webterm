<?php

declare(strict_types=1);

namespace Adn\WebTerm\Console;

use Adn\WebTerm\Credentials\CredentialManager;
use Adn\WebTerm\Gateway\GatewayClient;
use Adn\WebTerm\Models\Ability;
use Adn\WebTerm\Models\Grant;
use Adn\WebTerm\Models\HostKey;
use Adn\WebTerm\Models\Target;
use Adn\WebTerm\Protocol;
use Illuminate\Console\Command;
use Throwable;

/**
 * Check the installation and say what is wrong in the operator's terms.
 *
 * The design rule here: every failed check prints the command that fixes it.
 * A diagnostic that reports "gateway unreachable" and stops has moved the
 * problem, not helped.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'webterm:doctor {--deep : Also probe SSH reachability for every enabled target}';

    protected $description = 'Check that WebTerm is correctly configured';

    private int $problems = 0;

    public function handle(): int
    {
        $this->line('');
        $this->line('  <options=bold>LibreNMS WebTerm</>');
        $this->line('');

        $this->checkKillSwitch();
        $this->checkSecret();
        $this->checkGateway();
        $this->checkOrigins();
        $this->checkCredentials();
        $this->checkTargets();

        if ($this->option('deep')) {
            $this->checkHostKeys();
        }

        $this->line('');

        if ($this->problems === 0) {
            $this->info('  No problems found.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->error(sprintf('  %d problem(s) found.', $this->problems));
        $this->line('');

        return self::FAILURE;
    }

    private function reportOk(string $label, string $detail = ''): void
    {
        $this->line(sprintf('  <fg=green>PASS</>  %s%s', $label, $detail === '' ? '' : '  <fg=gray>'.$detail.'</>'));
    }

    private function reportWarn(string $label, string $detail, string $fix): void
    {
        $this->line(sprintf('  <fg=yellow>WARN</>  %s  <fg=gray>%s</>', $label, $detail));
        $this->line(sprintf('        <fg=gray>fix:</> %s', $fix));
    }

    private function reportFail(string $label, string $detail, string $fix): void
    {
        $this->problems++;
        $this->line(sprintf('  <fg=red>FAIL</>  %s  <fg=gray>%s</>', $label, $detail));
        $this->line(sprintf('        <fg=gray>fix:</> %s', $fix));
    }

    private function checkKillSwitch(): void
    {
        if ((bool) config('webterm.enabled', false)) {
            $this->reportOk('Plugin enabled');

            return;
        }

        $this->reportFail('Plugin enabled', 'the global kill switch is off', './lnms webterm:config set enabled true');
    }

    private function checkSecret(): void
    {
        $path = (string) config('webterm.gateway.secret_file');

        if ($path === '' || ! is_readable($path)) {
            $this->reportFail(
                'Shared secret',
                sprintf('%s is not readable by this user', $path === '' ? '(unset)' : $path),
                'librenms-webterm-gw init  (then make it readable by the web user)'
            );

            return;
        }

        $secret = trim((string) file_get_contents($path));

        if (strlen($secret) < Protocol::SECRET_BYTES) {
            $this->reportFail('Shared secret', 'shorter than 32 bytes', 'librenms-webterm-gw init --force');

            return;
        }

        $perms = substr(sprintf('%o', fileperms($path)), -3);
        if ($perms !== '600' && $perms !== '640') {
            $this->reportWarn('Shared secret', sprintf('mode %s is broader than necessary', $perms), 'chmod 640 '.$path);

            return;
        }

        $this->reportOk('Shared secret', $path);
    }

    private function checkGateway(): void
    {
        try {
            $hello = (new GatewayClient)->hello();
        } catch (Throwable $e) {
            $this->reportFail('Gateway', $e->getMessage(), 'systemctl status librenms-webterm-gw');

            return;
        }

        $min = (int) ($hello['min_protocol'] ?? 0);
        $max = (int) ($hello['max_protocol'] ?? 0);

        if ($min > Protocol::VERSION || $max < Protocol::VERSION) {
            $this->reportFail(
                'Gateway protocol',
                sprintf('plugin speaks v%d, gateway speaks v%d-v%d', Protocol::VERSION, $min, $max),
                'Upgrade the gateway to match the plugin.'
            );
        } else {
            $this->reportOk('Gateway', sprintf('protocol v%d, instance %s', Protocol::VERSION, $hello['instance_id'] ?? '?'));
        }

        if (($hello['insecure_control_plane'] ?? false) === true) {
            $this->reportWarn(
                'Gateway binding',
                'started with the loopback guard disabled',
                'Unset WEBTERM_INSECURE_CONTROL_PLANE unless you have another control in place.'
            );
        }
    }

    private function checkOrigins(): void
    {
        $origins = (array) config('webterm.security.allowed_origins', []);

        if ($origins === []) {
            $this->reportFail(
                'Allowed origins',
                'none configured, so every browser connection is refused',
                './lnms webterm:config set security.allowed_origins https://librenms.example.com'
            );

            return;
        }

        $this->reportOk('Allowed origins', implode(', ', array_map('strval', $origins)));
    }

    private function checkCredentials(): void
    {
        try {
            $provider = app(CredentialManager::class)->driver();
            $health = $provider->health();
        } catch (Throwable $e) {
            $this->reportFail('Credentials', $e->getMessage(), './lnms webterm:config set credentials.driver database');

            return;
        }

        if ($health->healthy) {
            $this->reportOk(sprintf('Credentials (%s)', $provider->name()), $health->summary);

            return;
        }

        $this->reportFail(sprintf('Credentials (%s)', $provider->name()), $health->summary, $health->remediation ?? '');
    }

    private function checkTargets(): void
    {
        $enabled = Target::query()->enabled()->count();

        if ($enabled === 0) {
            $this->reportFail(
                'Targets',
                'no device is enabled for terminal access',
                './lnms webterm:target:enable --device=<hostname>'
            );

            return;
        }

        $this->reportOk('Targets', sprintf('%d device(s) enabled', $enabled));

        $noPrincipal = Target::query()->enabled()->where(function ($q) {
            $q->whereNull('principal')->orWhere('principal', '');
        })->count();

        if ($noPrincipal > 0) {
            $this->reportFail(
                'Target principals',
                sprintf('%d enabled target(s) have no SSH username', $noPrincipal),
                './lnms webterm:target:enable --device=<hostname> --principal=<login>'
            );
        }

        if (Ability::query()->where('ability', Ability::USE)->count() === 0) {
            $this->reportFail(
                'Abilities',
                'nobody holds the "use" ability, so nobody can open a terminal',
                './lnms webterm:ability grant --user=<username> --ability=use'
            );
        }

        if (Grant::query()->where('effect', Grant::ALLOW)->count() === 0) {
            $this->reportFail(
                'Grants',
                'no allow grants exist, so nobody can reach any device',
                './lnms webterm:grant --user=<username> --device=<hostname>'
            );
        }
    }

    private function checkHostKeys(): void
    {
        $pinRequired = Target::query()->enabled()->where('host_key_policy', Target::POLICY_PIN)->get();
        $missing = [];

        foreach ($pinRequired as $target) {
            $has = HostKey::query()
                ->where('device_id', $target->device_id)
                ->where('status', HostKey::PINNED)
                ->exists();

            if (! $has) {
                $missing[] = $target->device_id;
            }
        }

        if ($missing === []) {
            $this->reportOk('Host keys', sprintf('%d pinned target(s)', $pinRequired->count()));

            return;
        }

        $this->reportFail(
            'Host keys',
            sprintf('%d target(s) require a pin but have none: %s', count($missing), implode(', ', $missing)),
            './lnms webterm:hostkey-scan'
        );
    }
}
