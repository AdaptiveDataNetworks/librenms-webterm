<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Credentials\CredentialManager;
use AdaptiveDataNetworks\WebTerm\Database\MigrationRunner;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\Session;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Protocol;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

    /**
     * The gateway's /api/v1/hello response, so later checks can ask the
     * gateway about its own configuration rather than guessing from ours.
     *
     * @var array<string, mixed>
     */
    private array $gatewayHello = [];

    public function handle(): int
    {
        $this->line('');
        $this->line(sprintf(
            '  <options=bold>LibreNMS WebTerm</> %s   <fg=gray>PHP %s</>',
            self::pluginVersion(),
            PHP_VERSION
        ));
        $this->line('');

        $this->checkSchema();
        $this->checkKillSwitch();
        $this->checkSecret();
        $this->checkGateway();
        $this->checkSelinux();
        $this->checkOrigins();
        $this->checkStepUp();
        $this->checkConsole();
        $this->checkReconciler();
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

    /**
     * What to tell an operator whose secret file exists but cannot be read.
     *
     * Separated out so the wording is directly testable. The critical part is
     * the warning NOT to regenerate: the file is intact, and running `init`
     * over it would replace a secret the gateway is already using, breaking
     * every session mint on a working install.
     */
    public static function unreadableSecretRemediation(string $path, string $user): string
    {
        return implode("\n", [
            'the file is intact -- do NOT regenerate it. Grant read access instead:',
            sprintf('             sudo usermod -a -G librenms-webterm %s', $user),
            sprintf('             sudo chown root:librenms-webterm %s', $path),
            sprintf('             sudo chmod 0640 %s', $path),
            '             then log out and back in (group changes do not affect running',
            '             processes) and restart php-fpm',
        ]);
    }

    /**
     * The account doctor is running as, so the remediation names a real user
     * rather than saying "the web user" and leaving it to be guessed.
     */
    /**
     * The installed plugin version.
     *
     * Printed because a support exchange stalled on not knowing it: a setting
     * that silently did nothing in 1.0.2 looked identical to one that had not
     * been run.
     */
    private static function pluginVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return '(version unknown)';
        }

        $package = 'adaptivedatanetworks/librenms-webterm';

        try {
            $version = InstalledVersions::getPrettyVersion($package);
            $reference = InstalledVersions::getReference($package);
        } catch (Throwable) {
            return '(version unknown)';
        }

        if (! is_string($version)) {
            return '(version unknown)';
        }

        // A branch constraint reports as "dev-main" and nothing else, which
        // says nothing about what is actually deployed. Anyone tracking a
        // branch needs the commit, and anyone on a tag already has it in the
        // version.
        if (str_starts_with($version, 'dev-') && is_string($reference)) {
            return sprintf('%s (%s)', $version, substr($reference, 0, 12));
        }

        return $version;
    }

    private static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info)) {
                return (string) $info['name'];
            }
        }

        $env = getenv('USER') ?: getenv('LOGNAME');

        return is_string($env) && $env !== '' ? $env : 'the web user';
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

    /**
     * Schema state, and whether core's validate.php will complain about it.
     *
     * Two distinct problems share this check. Pending migrations are a real
     * fault -- the plugin will throw on a missing table. Rows left in core's
     * migrations table are only cosmetic, but they make `./validate.php` report
     * "extra migrations", which sits in the same list as genuine schema
     * corruption and is indistinguishable from it to anyone who has not read
     * the source.
     */
    private function checkSchema(): void
    {
        try {
            $runner = app(MigrationRunner::class);
            $pending = $runner->pending();
            $legacy = $runner->legacyRows();
            $ahead = $runner->appliedWithoutFiles();
        } catch (Throwable $e) {
            $this->reportFail('Database schema', 'cannot be read: '.$e->getMessage(), 'check the database connection, then ./lnms webterm:migrate');

            return;
        }

        // Checked before pending migrations, because this is the failure that
        // otherwise reports green: a downgrade leaves the schema ahead of the
        // code, nothing is pending, and every session dies at credential
        // resolution with an unknown-column error nobody sees.
        if ($ahead !== []) {
            $this->reportFail(
                'Database schema',
                sprintf('the database has %d migration(s) this build does not ship -- the schema is newer than the code', count($ahead)),
                'upgrade the plugin again, or undo them before downgrading: ./lnms webterm:migrate --rollback --step='.count($ahead)
            );

            return;
        }

        if ($pending !== []) {
            $this->reportFail(
                'Database schema',
                sprintf('%d migration(s) have not been applied', count($pending)),
                './lnms webterm:migrate'
            );

            return;
        }

        if ($legacy !== []) {
            $this->reportWarn(
                'Database schema',
                sprintf('%d migration(s) are recorded in core\'s table, so ./validate.php reports them as extra', count($legacy)),
                './lnms webterm:migrate  (moves them, changes no schema)'
            );

            return;
        }

        $this->reportOk('Database schema', 'up to date');
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

        if ($path === '') {
            $this->reportFail(
                'Shared secret',
                'no path configured',
                'set WEBTERM_GATEWAY_SECRET_FILE in /opt/librenms/.env (webterm:config refuses this key: '
                    .'it names a file, and a config row pointing somewhere the gateway is not reading '
                    .'would fail every session mint with an opaque 401)'
            );

            return;
        }

        // "Missing" and "present but unreadable" need completely different
        // advice. Telling someone to run `init` when the file already exists
        // invites them to regenerate a secret the gateway is already using,
        // which breaks every session mint -- so the two cases are separated.
        if (! file_exists($path)) {
            $this->reportFail(
                'Shared secret',
                sprintf('%s does not exist', $path),
                'librenms-webterm-gw init --path '.$path
            );

            return;
        }

        if (! is_readable($path)) {
            $this->reportFail(
                'Shared secret',
                sprintf('%s exists but is not readable by %s', $path, self::currentUser()),
                self::unreadableSecretRemediation($path, self::currentUser())
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
            $hello = $this->gatewayHello = (new GatewayClient)->hello();
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

    /**
     * The origin allow-list is the GATEWAY's setting, not the plugin's.
     *
     * This check used to read config('webterm.security.allowed_origins') and,
     * when it was empty, tell the operator to run
     * `webterm:config set security.allowed_origins ...`. Nothing reads that
     * key -- the gateway takes WEBTERM_ALLOWED_ORIGINS from its own
     * environment file -- so the remediation could not work, and following it
     * left the operator with a doctor that passed and a gateway that refused
     * every browser connection with 403.
     *
     * The gateway is asked directly. An older gateway does not report the
     * count, in which case we say where to look rather than guess.
     */
    /**
     * SELinux blocks the loopback proxy on RHEL by default, and doctor cannot
     * feel it.
     *
     * nginx and php-fpm both run as httpd_t. Port 8377 is unreserved_port_t,
     * which httpd_can_network_relay does not cover, so connecting to the
     * gateway needs httpd_can_network_connect -- a boolean that ships OFF, and
     * that LibreNMS's own SELinux instructions do not turn on (they set
     * httpd_can_sendmail, httpd_execmem and httpd_can_network_connect_db, none
     * of which help).
     *
     * This command runs under php-cli in the invoking user's domain, not
     * httpd_t, so it is NOT subject to that boolean: every gateway check above
     * can pass while every browser request fails. Reading the boolean is the
     * only way to see it from here.
     */
    private function checkSelinux(): void
    {
        $enforce = $this->readCommand('getenforce');

        if ($enforce === null) {
            return; // Not an SELinux system; nothing to say.
        }

        if (strtolower(trim($enforce)) !== 'enforcing') {
            $this->reportOk('SELinux', strtolower(trim($enforce)));

            return;
        }

        $boolean = $this->readCommand('getsebool httpd_can_network_connect');

        if ($boolean === null) {
            $this->reportWarn(
                'SELinux',
                'enforcing, and httpd_can_network_connect could not be read',
                'getsebool httpd_can_network_connect  -- it must be on, or nginx and php-fpm cannot reach the gateway'
            );

            return;
        }

        if (! str_contains($boolean, '--> on')) {
            $this->reportFail(
                'SELinux',
                'enforcing with httpd_can_network_connect off, so nginx and php-fpm cannot reach the gateway '
                    .'even though this check passed -- the CLI is not confined the way they are',
                'setsebool -P httpd_can_network_connect 1'
            );

            return;
        }

        $this->reportOk('SELinux', 'enforcing, httpd_can_network_connect on');
    }

    /**
     * Run a short command, or null when it is not available.
     */
    private function readCommand(string $command): ?string
    {
        $binary = strtok($command, ' ');

        if ($binary === false || trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null')) === '') {
            return null;
        }

        $output = shell_exec($command.' 2>/dev/null');

        return is_string($output) && trim($output) !== '' ? $output : null;
    }

    private function checkOrigins(): void
    {
        $count = $this->gatewayHello['allowed_origins'] ?? null;

        if ($count === null) {
            $this->reportWarn(
                'Allowed origins',
                'this gateway does not report them; they are set on the gateway, not here',
                'check WEBTERM_ALLOWED_ORIGINS in /etc/librenms-webterm/gateway.env'
            );

            return;
        }

        if ((int) $count === 0) {
            $this->reportFail(
                'Allowed origins',
                'the gateway has none, so it refuses every browser connection with 403',
                'set WEBTERM_ALLOWED_ORIGINS in /etc/librenms-webterm/gateway.env to your '
                    .'LibreNMS URL, then: systemctl restart librenms-webterm-gw'
            );

            return;
        }

        $this->reportOk('Allowed origins', sprintf('%d configured on the gateway', (int) $count));
    }

    /**
     * Step-up is on by default and is satisfied only by LibreNMS two-factor.
     *
     * Worth its own line because the failure is invisible otherwise: the
     * terminal button appears, the click is authorized all the way to the last
     * gate, and the denial reads StepUpRequired with nothing to say that the
     * operator simply has no TOTP enrolled.
     */
    private function checkStepUp(): void
    {
        if (! (bool) config('webterm.security.step_up', true)) {
            $this->reportOk('Step-up', 'not required');

            return;
        }

        $this->reportWarn(
            'Step-up',
            'required, so an operator must have LibreNMS two-factor enrolled to open a terminal',
            'enrol TOTP in LibreNMS (Preferences -> Two-Factor Auth), or turn it off with: '
                .'./lnms webterm:config set security.step_up false'
        );
    }

    /**
     * Who, if anyone, can open the admin console.
     *
     * The console answers 404 to an account without the admin ability, so that
     * an unauthorised user cannot confirm it exists. The cost is that "I have
     * not granted myself the ability" and "the console is broken" look
     * identical from a browser. This is the operator's side of that trade: it
     * is the one place that will say the console is simply unreachable.
     */
    private function checkConsole(): void
    {
        $holders = Ability::query()->where('ability', Ability::ADMIN)->count();

        if ($holders === 0) {
            $this->reportWarn(
                'Admin console',
                'nobody holds the admin ability, so /plugin/webterm/admin answers 404 for everyone',
                './lnms webterm:ability grant --user=<user> --ability=admin'
            );

            return;
        }

        $this->reportOk('Admin console', sprintf('%d user(s) may open it', $holders));
    }

    /**
     * Whether anything is actually reaping sessions.
     *
     * Derived rather than recorded: a pending session older than the ticket TTL
     * can never be redeemed, so if one is still sitting in the table the
     * reconciler has not run since it was created. That needs no new state and
     * detects exactly the condition an operator hits -- sessions stuck at
     * "pending" and, before the concurrency fix, a lockout with nothing open.
     *
     * The usual cause is that LibreNMS's scheduler was never installed. The
     * plugin registers webterm:reconcile on it, but nothing runs the scheduler
     * unless dist/librenms-scheduler.cron (or the systemd timer) is in place.
     */
    private function checkReconciler(): void
    {
        $deadline = Carbon::now()->subSeconds(Protocol::TICKET_TTL_SECONDS);

        $stale = Session::query()
            ->where('state', Session::PENDING)
            ->where('started_at', '<=', $deadline)
            ->count();

        if ($stale === 0) {
            $this->reportOk('Session reaping', 'nothing stale');

            return;
        }

        $this->reportWarn(
            'Session reaping',
            sprintf('%d session(s) are past their ticket expiry but still recorded as pending, so the reconciler is not running', $stale),
            'install LibreNMS\'s scheduler (dist/librenms-scheduler.cron or librenms-scheduler.timer), '
                .'then clear the backlog with: ./lnms webterm:reconcile'
        );
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
