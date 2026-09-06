<?php

declare(strict_types=1);

namespace Adn\WebTerm\Hooks;

use Adn\WebTerm\Http\DevicePanelPresenter;
use Adn\WebTerm\Support\Guard;
use App\Models\Device;
use Illuminate\Support\Facades\Auth;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/**
 * "Open Terminal" panel in the left column of the device overview tab.
 *
 * Implemented directly against the marker interface rather than extending
 * LibreNMS's App\Plugins\Hooks\DeviceOverviewHook abstract, so that this class
 * loads without LibreNMS core present (standalone tests) and so we are not
 * exposed to changes in the abstract's final handle().
 *
 * NOTE: PluginManager instantiates hooks with `new $class` and no arguments.
 * A required constructor parameter is a fatal error at class load and would
 * 500 every device page in LibreNMS. This class must never gain a constructor
 * with required arguments; resolve collaborators inside the method bodies.
 */
final class DeviceOverview implements DeviceOverviewHook
{
    /**
     * Deliberately takes no injected $user. LibreNMS binds
     * Illuminate\Contracts\Auth\Authenticatable, but NOT App\Models\User -- a
     * User type-hint silently yields a fresh, empty model. Reading the user
     * from the facade sidesteps the whole class of mistake.
     */
    public function authorize(): bool
    {
        return Guard::safely(
            static fn (): bool => Auth::check(),
            false,
            'DeviceOverview::authorize'
        );
    }

    /**
     * Must stay cheap: this runs on every device overview page load. No
     * synchronous network call to the gateway is permitted -- reachability is
     * read from a short-lived cache populated out of band.
     *
     * @param  array<string, mixed>  $settings
     * @param  Device  $device
     * @return array{0: string, 1: array<string, mixed>}|array{}
     */
    public function handle(string $pluginName, array $settings, $device): array
    {
        return Guard::safely(
            static function () use ($pluginName, $device): array {
                $panel = (new DevicePanelPresenter)->present(Auth::user(), $device);

                // Nothing to say about this device: render no panel at all
                // rather than a permanent "not configured" box on every device
                // in the estate.
                if ($panel['state'] === 'hidden') {
                    return [];
                }

                return [
                    $pluginName.'::device-overview',
                    ['device' => $device, 'pluginName' => $pluginName, 'panel' => $panel],
                ];
            },
            [],
            'DeviceOverview::handle'
        );
    }
}
