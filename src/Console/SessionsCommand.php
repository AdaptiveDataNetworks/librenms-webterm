<?php

declare(strict_types=1);

namespace Adn\WebTerm\Console;

use Adn\WebTerm\Audit\AuditLogger;
use Adn\WebTerm\Audit\Event;
use Adn\WebTerm\Gateway\GatewayClient;
use Adn\WebTerm\Models\Session;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * List live sessions, and end them.
 *
 * Reports LibreNMS's view alongside the gateway's, because a discrepancy is
 * itself diagnostic: it means the reconciler is not running.
 */
final class SessionsCommand extends Command
{
    protected $signature = 'webterm:sessions
        {--kill= : Session id to terminate}
        {--reason=terminated by an administrator : Reason recorded in the audit trail}';

    protected $description = 'List or terminate WebTerm sessions';

    public function handle(AuditLogger $audit): int
    {
        if ($kill = $this->option('kill')) {
            return $this->kill((string) $kill, (string) $this->option('reason'), $audit);
        }

        $sessions = Session::query()->live()->orderByDesc('started_at')->get();

        if ($sessions->isEmpty()) {
            $this->info('No live sessions.');
        } else {
            $this->table(
                ['session', 'user', 'device', 'state', 'started', 'target'],
                $sessions->map(fn (Session $s): array => [
                    $s->session_id,
                    $s->user_id,
                    $s->device_id,
                    $s->state,
                    $s->started_at?->diffForHumans() ?? '',
                    $s->target ?? '',
                ])->all()
            );
        }

        try {
            $report = (new GatewayClient)->listSessions();
            $gatewayLive = (int) ($report['live'] ?? 0);

            if ($gatewayLive !== $sessions->count()) {
                $this->warn(sprintf(
                    'LibreNMS shows %d live session(s), the gateway shows %d. '
                    .'Run ./lnms webterm:reconcile, and check that the scheduler is running.',
                    $sessions->count(),
                    $gatewayLive
                ));
            }
        } catch (Throwable $e) {
            $this->warn('Could not reach the gateway: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function kill(string $sessionId, string $reason, AuditLogger $audit): int
    {
        $session = Session::query()->find($sessionId);

        try {
            (new GatewayClient)->killSession($sessionId, $reason);
        } catch (Throwable $e) {
            $this->error('Could not reach the gateway: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($session !== null) {
            $session->state = Session::CLOSED;
            $session->ended_at = Carbon::now();
            $session->close_reason = $reason;
            $session->save();

            $audit->log(Event::SessionKilled, null, $session->device_id, $sessionId, detail: ['reason' => $reason]);
        }

        $this->info(sprintf('Terminated %s.', $sessionId));

        return self::SUCCESS;
    }
}
