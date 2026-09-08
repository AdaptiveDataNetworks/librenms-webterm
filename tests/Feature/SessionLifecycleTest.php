<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Models\Session;
use AdaptiveDataNetworks\WebTerm\Protocol;
use AdaptiveDataNetworks\WebTerm\Session\Reconciler;
use AdaptiveDataNetworks\WebTerm\Tests\Support\UnreachableGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function sessionRow(string $id, string $state, int $ageSeconds, int $userId = 7): Session
{
    return Session::create([
        'session_id' => $id,
        'user_id' => $userId,
        'device_id' => 42,
        'state' => $state,
        'method' => 'password',
        'started_at' => Carbon::now()->subSeconds($ageSeconds),
        'last_seen_at' => Carbon::now()->subSeconds($ageSeconds),
    ]);
}

/**
 * The reported symptom: a few failed connection attempts and the operator is
 * locked out with "too many connections" and no terminal open anywhere.
 *
 * A row is written BEFORE the SSH dial, so a dial that fails leaves it pending.
 * Nothing in the request path closes it, and release needed both the gateway
 * and a scheduler tick.
 */
it('does not let a failed connection attempt hold a slot once its ticket has expired', function (): void {
    // Three dead attempts -- the default limit is 3.
    sessionRow('01AAAAAAAAAAAAAAAAAAAAAAAA', Session::PENDING, Protocol::TICKET_TTL_SECONDS + 5);
    sessionRow('01BBBBBBBBBBBBBBBBBBBBBBBB', Session::PENDING, Protocol::TICKET_TTL_SECONDS + 60);
    sessionRow('01CCCCCCCCCCCCCCCCCCCCCCCC', Session::PENDING, 3600);

    expect(Session::query()->where('user_id', 7)->live()->count())->toBe(3)
        // ...but none of them can ever be redeemed, so none occupies a slot.
        ->and(Session::query()->where('user_id', 7)->occupying()->count())->toBe(0);
});

it('still counts an attempt that is genuinely in flight', function (): void {
    // Inside the ticket TTL the browser may simply not have connected yet.
    sessionRow('01DDDDDDDDDDDDDDDDDDDDDDDD', Session::PENDING, 5);

    expect(Session::query()->where('user_id', 7)->occupying()->count())->toBe(1);
});

it('counts a live session against the limit however old it is', function (): void {
    sessionRow('01EEEEEEEEEEEEEEEEEEEEEEEE', Session::ACTIVE, 7200);

    expect(Session::query()->where('user_id', 7)->occupying()->count())->toBe(1);
});

it('marks sessions the gateway reports as live, which nothing ever did', function (): void {
    // The Reconciler's docblock promised this and the code did not do it, so a
    // running terminal read as "pending" forever in the console and the CLI.
    $row = sessionRow('01FFFFFFFFFFFFFFFFFFFFFFFF', Session::PENDING, 10);

    $gateway = new class extends GatewayClient
    {
        public function listSessions(): array
        {
            return [
                'instance_id' => 'gw-test',
                'sessions' => [['session_id' => '01FFFFFFFFFFFFFFFFFFFFFFFF']],
            ];
        }
    };

    (new Reconciler(gateway: $gateway))->run();

    $row->refresh();

    expect($row->state)->toBe(Session::ACTIVE)
        ->and($row->last_seen_at)->not->toBeNull();
});

it('still releases slots when the gateway cannot be reached at all', function (): void {
    // Every other release path needs the gateway -- which is exactly what is
    // broken when sessions pile up.
    sessionRow('01GGGGGGGGGGGGGGGGGGGGGGGG', Session::PENDING, Protocol::TICKET_TTL_SECONDS + 10);

    $result = (new Reconciler(gateway: new UnreachableGateway))->run();

    expect($result['reaped'])->toBe(1)
        ->and(Session::query()->find('01GGGGGGGGGGGGGGGGGGGGGGGG')->state)->toBe(Session::CLOSED);
});
