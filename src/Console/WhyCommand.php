<?php

declare(strict_types=1);

namespace Adn\WebTerm\Console;

use Adn\WebTerm\Authorization\ShellAuthorizer;
use Adn\WebTerm\Console\Concerns\ResolvesDevices;
use Illuminate\Console\Command;

/**
 * Explain an authorization decision.
 *
 * Runs the REAL admit() rather than a description of it, so the answer cannot
 * drift from the behaviour. Prints the failing reason and the exact command
 * that fixes it -- the difference between an operator solving their own problem
 * and opening a support issue.
 */
final class WhyCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:why {--user= : Username or id} {--device= : Hostname or id}';

    protected $description = 'Explain whether a user may open a terminal on a device, and why not';

    public function handle(ShellAuthorizer $authorizer): int
    {
        $user = $this->findUser((string) $this->option('user'));
        $device = $this->findDevice((string) $this->option('device'));

        if ($user === null) {
            $this->error(sprintf('No such user: %s', $this->option('user')));

            return self::FAILURE;
        }

        if ($device === null) {
            $this->error(sprintf('No such device: %s', $this->option('device')));

            return self::FAILURE;
        }

        $decision = $authorizer->admit($user, $device);

        $this->line('');
        $this->line(sprintf(
            '  <options=bold>%s</> on <options=bold>%s</>',
            $user->username ?? $user->getAuthIdentifier(),
            $device->hostname ?? $device->device_id
        ));
        $this->line('');

        if ($decision->allowed) {
            $this->info('  ALLOWED');
            $this->line('');
            $this->line(sprintf(
                '  Limits: idle %ds, max %ds, %d concurrent',
                $decision->limits->idleTimeout,
                $decision->limits->maxDuration,
                $decision->limits->maxConcurrent,
            ));
            $this->line('');

            return self::SUCCESS;
        }

        $this->error('  DENIED: '.$decision->reason->value);
        $this->line('');
        $this->line('  '.$decision->message());

        $remediation = $decision->reason->remediation();
        if ($remediation !== null) {
            $this->line('');
            $this->line('  <options=bold>To fix:</>');
            $this->line('    '.$remediation);
        }

        $this->line('');

        return self::FAILURE;
    }
}
