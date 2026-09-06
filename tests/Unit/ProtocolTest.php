<?php

declare(strict_types=1);

use Adn\WebTerm\Protocol;

it('is generated from protocol.json and stays in sync', function () {
    $spec = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/protocol/protocol.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect(Protocol::VERSION)->toBe($spec['version'])
        ->and(Protocol::SUBPROTOCOL)->toBe($spec['subprotocol'])
        ->and(Protocol::TICKET_BYTES)->toBe($spec['ticket']['bytes'])
        ->and(Protocol::TICKET_TTL_SECONDS)->toBe($spec['ticket']['ttl_seconds']);
});

it('keeps the websocket ping interval below nginx proxy_read_timeout', function () {
    // nginx defaults proxy_read_timeout to 60s. If we ping less often than that,
    // every idle terminal dies after a minute and the bug looks like a hang.
    expect(Protocol::WS_PING_INTERVAL_SECONDS)->toBeLessThan(60);
});

it('uses a 32 byte single-use ticket with a short ttl', function () {
    expect(Protocol::TICKET_BYTES)->toBe(32)
        ->and(Protocol::TICKET_TTL_SECONDS)->toBeLessThanOrEqual(60);
});
