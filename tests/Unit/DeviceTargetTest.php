<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTarget;

/**
 * Stands in for App\Models\Device. Only the surface DeviceTarget touches is
 * modelled; the contract test is what asserts the real model still offers it.
 */
function fakeDevice(
    ?string $ip = null,
    ?string $overwriteIp = null,
    ?string $hostname = null,
    mixed $sshPort = null,
): object {
    return new class($ip, $overwriteIp, $hostname, $sshPort)
    {
        public function __construct(
            public ?string $ip,
            public ?string $overwrite_ip,
            public ?string $hostname,
            private mixed $sshPort,
        ) {}

        public function getAttrib(string $name, mixed $default = null): mixed
        {
            return $name === DeviceTarget::PORT_ATTRIB ? $this->sshPort : $default;
        }
    };
}

it('prefers the resolved ip column', function () {
    $target = (new DeviceTarget)->resolve(fakeDevice(ip: '10.0.0.5', hostname: 'core-sw-01'));

    expect($target)->not->toBeNull()
        ->and($target->ip)->toBe('10.0.0.5')
        ->and($target->port)->toBe(22);
});

it('falls back to an ip override, then to a hostname that is itself a literal', function () {
    expect((new DeviceTarget)->resolve(fakeDevice(overwriteIp: '10.0.0.6'))?->ip)->toBe('10.0.0.6')
        ->and((new DeviceTarget)->resolve(fakeDevice(hostname: '10.0.0.7'))?->ip)->toBe('10.0.0.7');
});

it('refuses a device whose only address is a DNS name', function () {
    // The gateway links no resolver. Resolving here instead would reintroduce
    // the ambiguity of a name that resolves differently at connect time.
    $device = fakeDevice(hostname: 'core-sw-01.example.com');

    expect((new DeviceTarget)->resolve($device))->toBeNull()
        ->and((new DeviceTarget)->rejectionReason($device))->toContain('does not resolve DNS');
});

it('applies the per-device ssh port override', function () {
    expect((new DeviceTarget)->resolve(fakeDevice(ip: '10.0.0.5', sshPort: '2222'))?->port)->toBe(2222);
});

it('refuses an out-of-range or non-numeric port override rather than silently using 22', function (mixed $port) {
    // Silently falling back would connect somewhere the administrator did not
    // intend, which is worse than refusing.
    expect((new DeviceTarget)->resolve(fakeDevice(ip: '10.0.0.5', sshPort: $port)))->toBeNull();
})->with([[0], [-1], [65536], ['ssh'], ['22abc']]);

it('treats an empty port override as the default', function () {
    expect((new DeviceTarget)->resolve(fakeDevice(ip: '10.0.0.5', sshPort: ''))?->port)->toBe(22);
});

it('refuses devices whose address is loopback or link-local', function (string $ip) {
    expect((new DeviceTarget)->resolve(fakeDevice(ip: $ip)))->toBeNull();
})->with(['127.0.0.1', '::1', '169.254.169.254', '0.0.0.0', '224.0.0.1']);

it('never throws, whatever shape the device turns out to be', function () {
    // LibreNMS core moves; a device that does not look like we expect must
    // yield "no target", never an exception that disables the plugin.
    $broken = new class
    {
        public function __get(string $name): mixed
        {
            throw new RuntimeException('unexpected model shape');
        }
    };

    expect((new DeviceTarget)->resolve($broken))->toBeNull()
        ->and((new DeviceTarget)->rejectionReason($broken))->toBeString();
});

it('formats ipv6 targets with brackets', function () {
    expect((string) (new DeviceTarget)->resolve(fakeDevice(ip: '2001:db8::1')))->toBe('[2001:db8::1]:22')
        ->and((string) (new DeviceTarget)->resolve(fakeDevice(ip: '10.0.0.5')))->toBe('10.0.0.5:22');
});
