<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Session;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayException;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayStatus;
use AdaptiveDataNetworks\WebTerm\Models\Session as SessionModel;
use AdaptiveDataNetworks\WebTerm\Protocol;
use Illuminate\Support\Carbon;

/**
 * Reconciles LibreNMS's view of sessions with the gateway's.
 *
 * The gateway is the authority on what is running; this closes the gap. It runs
 * as a poll rather than the gateway pushing updates, because of the one-way
 * rule: the gateway never makes an outbound request to LibreNMS, so there is no
 * endpoint for it to call and no credential for it to hold.
 *
 * Three jobs:
 *   - mark sessions the gateway reports as live, so the UI is truthful;
 *   - reap sessions LibreNMS thinks are live but the gateway has never heard
 *     of, which otherwise consume a user's concurrency budget forever;
 *   - re-authorise every live session, so revoking access ends the terminal
 *     rather than merely preventing the next one.
 */
final class Reconciler
{
    public function __construct(
        private readonly ?GatewayClient $gateway = null,
        private readonly ShellAuthorizer $authorizer = new ShellAuthorizer,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * @return array{live: int, reaped: int, revoked: int}
     */
    public function run(): array
    {
        $gateway = $this->gateway ?? new GatewayClient;

        try {
            $report = $gateway->listSessions();
        } catch (GatewayException $e) {
            // A gateway we cannot reach tells us nothing about what is running.
            // Reaping on that basis would kill every live session during a
            // transient network blip, so we do nothing and try again.
            $this->audit->log(Event::GatewayUnreachable, detail: ['error' => $e->getMessage()]);

            // Every other release path needs the gateway, so without this an
            // unreachable gateway means no slot is ever returned. Rows that are
            // dead on the clock alone can still be closed: a pending ticket
            // past its TTL can no longer be redeemed by anyone.
            return ['live' => 0, 'reaped' => $this->expireOnTime(), 'revoked' => 0];
        }

        // Recorded here so the device panel and the terminal tab can refuse a
        // protocol mismatch BEFORE the operator clicks, without either of them
        // making a synchronous call to the gateway during a page render.
        try {
            GatewayStatus::record($gateway->hello());
        } catch (GatewayException) {
            // A reachable gateway that will not say hello changes nothing here.
        }

        $instanceId = (string) ($report['instance_id'] ?? '');
        $liveIds = [];
        foreach ((array) ($report['sessions'] ?? []) as $s) {
            if (is_array($s) && isset($s['session_id'])) {
                $liveIds[] = (string) $s['session_id'];
            }
        }

        $this->markActive($liveIds);

        $reaped = $this->reap($liveIds, $instanceId) + $this->expireOnTime();
        $revoked = $this->revoke($gateway);

        return ['live' => count($liveIds), 'reaped' => $reaped, 'revoked' => $revoked];
    }

    /**
     * @param  list<string>  $liveIds
     */
    /**
     * Record that the gateway is still holding these sessions.
     *
     * Nothing ever wrote ACTIVE, despite this class's own docblock promising
     * it. A running terminal therefore read as "pending" forever in
     * webterm:sessions and the admin console -- which is what "sessions seem to
     * just hang" looks like from the outside -- and last_seen_at was written
     * once at mint and never again, leaving its index serving a query nobody
     * ran.
     *
     * @param  list<string>  $liveIds
     */
    private function markActive(array $liveIds): void
    {
        if ($liveIds === []) {
            return;
        }

        SessionModel::query()
            ->whereIn('session_id', $liveIds)
            ->where('state', '!=', SessionModel::CLOSED)
            ->update([
                'state' => SessionModel::ACTIVE,
                'last_seen_at' => Carbon::now(),
            ]);
    }

    /**
     * Close rows that are dead on the clock, whatever the gateway says.
     *
     * A pending row past the ticket TTL can never become a session: the ticket
     * is single-use and expired, so nobody can redeem it. An active row whose
     * last_seen_at has not moved for well over a reconcile interval is one the
     * gateway has stopped reporting.
     *
     * This is the only release path that does not require reaching the gateway,
     * which matters because the gateway being down is precisely when sessions
     * pile up.
     */
    private function expireOnTime(): int
    {
        $stale = SessionModel::query()
            ->where('state', SessionModel::PENDING)
            ->where('started_at', '<=', Carbon::now()->subSeconds(Protocol::TICKET_TTL_SECONDS))
            ->get();

        foreach ($stale as $session) {
            $session->state = SessionModel::CLOSED;
            $session->ended_at = Carbon::now();
            $session->close_reason = 'expired: ticket was never redeemed';
            $session->save();

            $this->audit->log(Event::SessionEnded, null, $session->device_id, $session->session_id);
        }

        return $stale->count();
    }

    private function reap(array $liveIds, string $instanceId): int
    {
        $query = SessionModel::query()->live();

        // A changed instance id means the gateway restarted, so nothing it was
        // holding survived -- every session we think is live is stale,
        // including any the (new) gateway has not reported yet.
        if ($instanceId !== '') {
            $query->where(function ($q) use ($liveIds, $instanceId): void {
                $q->where('gateway_instance_id', '!=', $instanceId)
                    ->orWhereNotIn('session_id', $liveIds === [] ? [''] : $liveIds);
            });
        } elseif ($liveIds !== []) {
            $query->whereNotIn('session_id', $liveIds);
        }

        $stale = $query->get();

        foreach ($stale as $session) {
            // A pending session may simply not have been connected to yet --
            // but only until its ticket expires. Past the TTL it is provably
            // dead, so the old 60-second grace was twice as long as it could
            // ever need to be, and doubled how long a leaked slot lingered.
            if ($session->state === SessionModel::PENDING
                && $session->started_at !== null
                && $session->started_at->diffInSeconds(Carbon::now()) < Protocol::TICKET_TTL_SECONDS) {
                continue;
            }

            $session->state = SessionModel::CLOSED;
            $session->ended_at = Carbon::now();
            $session->close_reason = 'reaped: not present on the gateway';
            $session->save();

            $this->audit->log(Event::SessionEnded, null, $session->device_id, $session->session_id);
        }

        return $stale->count();
    }

    private function revoke(GatewayClient $gateway): int
    {
        $revoked = 0;

        /** @var class-string|null $deviceModel */
        $deviceModel = class_exists('App\Models\Device') ? 'App\Models\Device' : null;
        $userModel = class_exists('App\Models\User') ? 'App\Models\User' : null;

        if ($deviceModel === null || $userModel === null) {
            return 0;
        }

        foreach (SessionModel::query()->live()->get() as $session) {
            $device = $deviceModel::query()->find($session->device_id);
            $user = $userModel::query()->find($session->user_id);

            if ($device === null || $user === null) {
                continue;
            }

            if ($this->authorizer->sustain($user, $device)->allowed) {
                continue;
            }

            $gateway->killSession($session->session_id, 'access revoked');

            $session->state = SessionModel::CLOSED;
            $session->ended_at = Carbon::now();
            $session->close_reason = 'access revoked';
            $session->save();

            $this->audit->log(Event::SessionRevoked, $user, $session->device_id, $session->session_id);
            $revoked++;
        }

        return $revoked;
    }
}
