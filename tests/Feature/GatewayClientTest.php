<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayException;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayUnreachableException;
use AdaptiveDataNetworks\WebTerm\Protocol;

/*
| CROSS-IMPLEMENTATION TESTS
|
| These run the real gateway binary and make real signed requests to it. They
| are the only thing that proves the PHP and Go halves of the control-plane
| signature agree; two implementations of one specification will otherwise
| drift, and the failure mode is a total outage that unit tests on either side
| would not catch.
|
| They skip when the binary is not available, and the CI gateway job sets
| WEBTERM_GATEWAY_BIN so they cannot skip where it matters.
*/

const NEEDS_BINARY = 'Set WEBTERM_GATEWAY_BIN to the built gateway to run cross-implementation tests.';

function gatewayBinary(): ?string
{
    $bin = getenv('WEBTERM_GATEWAY_BIN');

    return is_string($bin) && $bin !== '' && is_executable($bin) ? $bin : null;
}

/**
 * @return array{0: int, 1: string, 2: string} port, secret file, log file
 */
function startGateway(string $bin): array
{
    $dir = sys_get_temp_dir().'/webterm-test-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o700, true);

    $secretFile = $dir.'/gateway.secret';
    file_put_contents($secretFile, bin2hex(random_bytes(32))."\n");
    chmod($secretFile, 0o600);

    // Port 0 is not usable here because the gateway prints no chosen port, so
    // pick a high port and retry on collision.
    $port = random_int(20000, 30000);
    $log = $dir.'/gateway.log';

    $cmd = sprintf(
        'WEBTERM_SECRET_FILE=%s WEBTERM_LISTEN=127.0.0.1:%d WEBTERM_METRICS_LISTEN=127.0.0.1:%d '
        .'WEBTERM_ALLOWED_ORIGINS=https://librenms.example.com %s serve > %s 2>&1 & echo $!',
        escapeshellarg($secretFile),
        $port,
        $port + 1,
        escapeshellarg($bin),
        escapeshellarg($log)
    );

    $pid = (int) shell_exec($cmd);

    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(50_000);
    }

    register_shutdown_function(static function () use ($pid): void {
        if ($pid > 0) {
            @exec('kill '.$pid.' 2>/dev/null');
        }
    });

    return [$port, $secretFile, $log];
}

it('signs a request the Go gateway accepts', function () {
    // The load-bearing test: if the canonical string or the HKDF derivation
    // diverges by a single byte, this fails.
    [$port, $secret] = startGateway((string) gatewayBinary());

    $client = new GatewayClient('http://127.0.0.1:'.$port, $secret);
    $hello = $client->hello();

    expect($hello['name'])->toBe(Protocol::NAME)
        ->and($hello['protocol'])->toBe(Protocol::VERSION)
        ->and($hello['instance_id'])->toStartWith('gw-');
})->skip(fn (): bool => gatewayBinary() === null, NEEDS_BINARY);

it('completes the three-phase handoff', function () {
    [$port, $secret] = startGateway((string) gatewayBinary());
    $client = new GatewayClient('http://127.0.0.1:'.$port, $secret);

    $sessionId = strtoupper(bin2hex(random_bytes(13)));

    $created = $client->createSession([
        'protocol' => Protocol::VERSION,
        'session_id' => $sessionId,
        'target' => ['ip' => '10.0.0.1', 'port' => 22, 'host_key_policy' => 'pin'],
        'auth' => ['method' => 'password', 'username' => 'netops'],
        'limits' => ['idle_timeout' => 900, 'max_duration' => 3600],
    ]);

    expect($created['ticket'])->toBeString()
        ->and(strlen($created['ticket']))->toBe(43)   // 32 bytes, base64url unpadded
        ->and($created['session_id'])->toBe($sessionId);

    $client->supplyCredential($sessionId, ['method' => 'password', 'password' => 'hunter2']);

    $sessions = $client->listSessions();
    expect($sessions['pending'])->toBe(1);
})->skip(fn (): bool => gatewayBinary() === null, NEEDS_BINARY);

it('receives an ephemeral public key for the certificate flow, and never the private half', function () {
    [$port, $secret] = startGateway((string) gatewayBinary());
    $client = new GatewayClient('http://127.0.0.1:'.$port, $secret);

    $created = $client->createSession([
        'protocol' => Protocol::VERSION,
        'session_id' => strtoupper(bin2hex(random_bytes(13))),
        'target' => ['ip' => '10.0.0.1', 'port' => 22],
        'auth' => ['method' => 'signed_certificate', 'username' => 'netops'],
    ]);

    expect($created['public_key'])->toStartWith('ssh-ed25519 ')
        ->and(json_encode($created))->not->toContain('PRIVATE KEY');
})->skip(fn (): bool => gatewayBinary() === null, NEEDS_BINARY);

it('is rejected when the shared secret does not match', function () {
    [$port] = startGateway((string) gatewayBinary());

    $wrongSecret = sys_get_temp_dir().'/webterm-wrong-'.bin2hex(random_bytes(4));
    file_put_contents($wrongSecret, bin2hex(random_bytes(32)));

    $client = new GatewayClient('http://127.0.0.1:'.$port, $wrongSecret);

    expect(fn () => $client->hello())
        ->toThrow(GatewayException::class, 'same shared secret file');
})->skip(fn (): bool => gatewayBinary() === null, NEEDS_BINARY);

it('reports an unreachable gateway without hanging', function () {
    // Port 1 refuses immediately; the point is that the failure is fast and
    // the message names the service rather than surfacing a curl error code.
    $secret = sys_get_temp_dir().'/webterm-secret-'.bin2hex(random_bytes(4));
    file_put_contents($secret, bin2hex(random_bytes(32)));

    $client = new GatewayClient('http://127.0.0.1:1', $secret);

    expect(fn () => $client->hello())->toThrow(GatewayUnreachableException::class);
});

it('builds the canonical signing string exactly as the specification requires', function () {
    $canonical = GatewayClient::canonical('POST', '/api/v1/sessions', 1757068800, 'abc123', '{"a":1}');
    $fields = explode("\n", $canonical);

    expect($fields)->toHaveCount(7)
        ->and($fields[0])->toBe('lnms-webterm')
        ->and($fields[1])->toBe('v1')
        ->and($fields[2])->toBe('POST')
        ->and($fields[3])->toBe('/api/v1/sessions')
        ->and($fields[4])->toBe('1757068800')
        ->and($fields[5])->toBe('abc123')
        ->and($fields[6])->toBe(hash('sha256', '{"a":1}'));
});
