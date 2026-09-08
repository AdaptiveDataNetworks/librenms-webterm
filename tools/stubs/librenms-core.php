<?php

/**
 * Declarations of the LibreNMS core symbols this plugin implements against.
 *
 * NOT autoloaded and NOT shipped: `.gitattributes` export-ignores /tools, and
 * nothing requires this file. It exists only so `phpstan analyse` can resolve
 * symbols that live in LibreNMS, which is absent when the plugin is analysed on
 * its own.
 *
 * Everywhere else the plugin names core by string (`$model = 'App\Models\Device'`)
 * precisely to avoid needing this. That trick is unavailable for an interface
 * you must actually implement: PageTabs::getTab() is declared `: DeviceTab`, so
 * a duck-typed class is a TypeError at runtime, and `implements DeviceTab`
 * raises phpstan's interface.notFound -- which, unlike class.notFound, cannot be
 * ignored via ignoreErrors.
 *
 * tests/Contract asserts these symbols still exist against a real LibreNMS
 * checkout, so a drift upstream fails the integration job rather than silently
 * making this stub a lie.
 */

declare(strict_types=1);

namespace App\Models {
    class Device
    {
        public int $device_id;

        public ?string $hostname;

        public function displayName(): string
        {
            return '';
        }
    }
}

namespace App\View\Components\Device {
    class PageTabs
    {
        /** @var array<string, class-string> */
        public static array $tabsClasses = [];
    }
}

namespace LibreNMS\Interfaces\UI {
    use App\Models\Device;
    use Illuminate\Http\Request;

    interface DeviceTab
    {
        public function visible(Device $device): bool;

        public function slug(): string;

        public function icon(): string;

        public function name(): string;

        /** @return array<string, mixed> */
        public function data(Device $device, Request $request): array;
    }
}
