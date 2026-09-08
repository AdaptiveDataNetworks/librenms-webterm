<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Librenms;

use App\Models\Device;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\UI\DeviceTab as DeviceTabContract;

/**
 * The terminal, as a tab on the device page.
 *
 * It used to be a full-page pop-out at /plugin/WebTerm, which lost every scrap
 * of device context and offered no way back.
 *
 * Pure delegation to DeviceTabPresenter, which holds the behaviour. This class
 * cannot be loaded without LibreNMS -- implementing the core interface requires
 * it at class-load time -- so anything with logic in it would be unreachable in
 * the standalone suite.
 *
 * The important thing to understand about core's tab system: visible() is
 * COSMETIC. It gates whether the tab link is drawn and nothing else.
 * DeviceController authorizes only `view` on the device and then calls data()
 * directly, so registering this slug makes /device/{id}/webterm reachable by
 * anyone who can see the device, whatever visible() returns. Core lives with
 * that -- CaptureController::visible() returns hard false and its URL still
 * serves -- so the real authorization is inside data().
 */
final class DeviceTab implements CoreDependency, DeviceTabContract
{
    /** @return list<string> */
    public static function coreSymbols(): array
    {
        return [
            'App\Models\Device',
            'App\View\Components\Device\PageTabs',
            'LibreNMS\Interfaces\UI\DeviceTab',
        ];
    }

    public function slug(): string
    {
        return 'webterm';
    }

    public function icon(): string
    {
        return 'fa-terminal';
    }

    public function name(): string
    {
        return __('Terminal');
    }

    public function visible(Device $device): bool
    {
        return (new DeviceTabPresenter)->visibleFor((int) $device->device_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Device $device, Request $request): array
    {
        return (new DeviceTabPresenter)->dataFor($device);
    }
}
