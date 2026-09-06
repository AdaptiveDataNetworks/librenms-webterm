<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Session;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayException;
use AdaptiveDataNetworks\WebTerm\Models\Session as SessionModel;
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

            return ['live' => 0, 'reaped' => 0, 'revoked' => 0];
        }

        $instanceId = (string) ($report['instance_id'] ?? '');
        $liveIds = [];
        foreach ((array) ($report['sessions'] ?? []) as $s) {
            if (is_array($s) && isset($s['session_id'])) {
                $liveIds[] = (string) $s['session_id'];
            }
        }

        $reaped = $this->reap($liveIds, $instanceId);
        $revoked = $this->revoke($gateway);

        return ['live' => count($liveIds), 'reaped' => $reaped, 'revoked' => $revoked];
    }

    /**
     * @param  list<string>  $liveIds
     */
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
            // A pending session that has not yet been redeemed is not stale --
            // it may simply not have been connected to yet.
            if ($session->state === SessionModel::PENDING
                && $session->started_at !== null
                && $session->started_at->diffInSeconds(Carbon::now()) < 60) {
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
