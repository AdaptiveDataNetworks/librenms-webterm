<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Support\IpGuard;

/*
| IpGuard decides where the gateway may connect. The gateway links no resolver,
| so this is the only boundary; a gap here is an SSRF pivot with the monitoring
| host's reach.
*/

it('accepts ordinary device addresses, including private ranges', function (string $ip) {
    // RFC1918 must NOT be blocked -- managed network equipment lives there, and
    // blocking it would block the entire purpose of the plugin.
    expect(IpGuard::isDialable($ip))->toBeTrue(sprintf('%s should be dialable', $ip));
})->with([
    '10.0.0.1',
    '192.168.1.254',
    '172.16.31.9',
    '203.0.113.10',
    '2001:db8::1',
    'fd00::1',
]);

it('refuses addresses that can only be a mistake or an attack', function (string $ip, string $expect) {
    expect(IpGuard::isDialable($ip))->toBeFalse(sprintf('%s must not be dialable', $ip))
        ->and(IpGuard::rejectionReason($ip))->toContain($expect);
})->with([
    ['127.0.0.1', 'loopback'],
    ['127.10.20.30', 'loopback'],
    ['::1', 'loopback'],
    ['0.0.0.0', 'unspecified'],
    ['::', 'unspecified'],
    ['169.254.169.254', 'link-local'],
    ['fe80::1', 'link-local'],
    ['224.0.0.1', 'multicast'],
    ['ff02::1', 'multicast'],
    ['255.255.255.255', 'reserved'],
]);

it('cannot be bypassed by expressing a blocked IPv4 address as IPv6', function (string $ip) {
    // Without normalisation, ::ffff:127.0.0.1 would sail past every IPv4 rule.
    expect(IpGuard::isDialable($ip))->toBeFalse(sprintf('%s must not be dialable', $ip));
})->with([
    '::ffff:127.0.0.1',
    '::ffff:169.254.169.254',
    '::ffff:0.0.0.0',
    '::127.0.0.1',
]);

it('refuses anything that is not an IP literal', function (string $candidate) {
    expect(IpGuard::isDialable($candidate))->toBeFalse()
        ->and(IpGuard::rejectionReason($candidate))->toContain('does not resolve DNS');
})->with([
    'core-sw-01',
    'core-sw-01.example.com',
    'localhost',
    '10.0.0.1:22',
    '10.0.0.256',
    'not an ip',
]);

it('treats an empty address as a rejection, not a pass', function () {
    expect(IpGuard::isDialable(''))->toBeFalse()
        ->and(IpGuard::rejectionReason(''))->toContain('empty');
});

it('validates port ranges', function () {
    expect(IpGuard::isValidPort(22))->toBeTrue()
        ->and(IpGuard::isValidPort(65535))->toBeTrue()
        ->and(IpGuard::isValidPort(1))->toBeTrue()
        ->and(IpGuard::isValidPort(0))->toBeFalse()
        ->and(IpGuard::isValidPort(-1))->toBeFalse()
        ->and(IpGuard::isValidPort(65536))->toBeFalse();
});
