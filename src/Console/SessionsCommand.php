<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Models\Session;
use AdaptiveDataNetworks\WebTerm\Protocol;
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
                    $this->stateLabel($s),
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

    /**
     * A pending row past the ticket TTL is dead, not waiting.
     *
     * Showing it as plain "pending" reads as "about to connect" when it can
     * never connect -- its ticket is single-use and expired.
     */
    private function stateLabel(Session $session): string
    {
        if ($session->state !== Session::PENDING) {
            return (string) $session->state;
        }

        $expired = $session->started_at !== null
            && $session->started_at->diffInSeconds(Carbon::now()) >= Protocol::TICKET_TTL_SECONDS;

        return $expired ? 'pending (expired)' : 'pending';
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
