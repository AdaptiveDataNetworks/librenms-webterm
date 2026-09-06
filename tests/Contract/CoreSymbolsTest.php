<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Audit\Severity;
use AdaptiveDataNetworks\WebTerm\Librenms\CoreDependency;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTarget;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceVisibility;
use AdaptiveDataNetworks\WebTerm\Librenms\EventlogWriter;
use AdaptiveDataNetworks\WebTerm\Librenms\RoleReader;
use AdaptiveDataNetworks\WebTerm\Librenms\TwoFactorReader;
use App\Models\Device;

/*
| Contract tests against LibreNMS core.
|
| None of what we use is a published API with compatibility guarantees, so each
| adapter names the exact symbols it depends on and these tests assert they
| still exist. They SKIP when LibreNMS is absent (the normal standalone test
| run) and RUN in the nightly integration job against a real checkout. A
| rename upstream then surfaces as a failure naming the symbol, months before a
| user hits it.
*/

$adapters = [
    DeviceVisibility::class,
    DeviceTarget::class,
    EventlogWriter::class,
    TwoFactorReader::class,
    RoleReader::class,
];

it('declares its core dependencies', function (string $adapter) {
    expect(is_subclass_of($adapter, CoreDependency::class))->toBeTrue()
        ->and($adapter::coreSymbols())->not->toBeEmpty();
})->with($adapters);

it('depends only on symbols that exist in LibreNMS core', function (string $adapter) {

    foreach ($adapter::coreSymbols() as $symbol) {
        if (! str_contains($symbol, '::')) {
            expect(class_exists($symbol) || interface_exists($symbol) || enum_exists($symbol))
                ->toBeTrue("LibreNMS core no longer provides {$symbol} (required by {$adapter})");

            continue;
        }

        [$class, $method] = explode('::', $symbol, 2);

        expect(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class))
            ->toBeTrue("LibreNMS core no longer provides {$class} (required by {$adapter})");

        expect(method_exists($class, $method))
            ->toBeTrue("LibreNMS core no longer provides {$class}::{$method}() (required by {$adapter})");
    }
})->with($adapters)->skip(
    fn (): bool => ! class_exists(Device::class),
    'LibreNMS core not installed; this runs in the integration job.'
);

it('confirms our severity values still match LibreNMS', function () {

    // EventlogWriter maps by integer value, so a renumbering upstream would
    // silently change the severity of every event we log.
    foreach (Severity::cases() as $ours) {
        expect(LibreNMS\Enum\Severity::tryFrom($ours->value))
            ->not->toBeNull("LibreNMS\\Enum\\Severity has no case for value {$ours->value} ({$ours->name})");
    }
})->skip(
    fn (): bool => ! enum_exists(LibreNMS\Enum\Severity::class),
    'LibreNMS core not installed; this runs in the integration job.'
);
