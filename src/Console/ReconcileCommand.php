<?php

declare(strict_types=1);

namespace Adn\WebTerm\Console;

use Adn\WebTerm\Session\Reconciler;
use Illuminate\Console\Command;

/**
 * Reconcile LibreNMS's session view with the gateway's.
 *
 * Runs from the scheduler. Also runnable by hand, which matters because
 * LibreNMS installations vary in whether Laravel's scheduler is actually wired
 * up -- webterm:doctor checks when this last ran and says so.
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'webterm:reconcile';

    protected $description = 'Reconcile terminal sessions with the gateway, reaping and revoking as needed';

    public function handle(Reconciler $reconciler): int
    {
        $result = $reconciler->run();

        $this->info(sprintf(
            '%d live, %d reaped, %d revoked.',
            $result['live'],
            $result['reaped'],
            $result['revoked']
        ));

        return self::SUCCESS;
    }
}
