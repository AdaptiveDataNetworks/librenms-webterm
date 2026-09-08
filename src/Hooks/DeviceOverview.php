<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Hooks;

use AdaptiveDataNetworks\WebTerm\Http\DevicePanelPresenter;
use AdaptiveDataNetworks\WebTerm\Support\Guard;
use App\Models\Device;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/**
 * "Open Terminal" panel in the left column of the device overview tab.
 *
 * RETURN TYPE IS PART OF THE CONTRACT, AND EACH HOOK'S DIFFERS
 * -----------------------------------------------------------
 * LibreNMS renders this one with `{{ $pluginView }}` in
 * resources/views/device/tabs/overview.blade.php, so the return value must be
 * something `e()` accepts -- a View or another Htmlable. Core's own abstract
 * declares `: \Illuminate\Contracts\View\View`.
 *
 * Returning an array here (the shape MenuEntryHook uses) throws
 * "htmlspecialchars(): Argument #1 must be of type string, array given" inside
 * core's own template, which 500s EVERY DEVICE PAGE -- not just this panel, and
 * whether or not WebTerm is configured. Guard cannot catch it, because the
 * failure happens in LibreNMS's view after our hook has returned.
 *
 * For reference, the contracts differ per hook:
 *   DeviceOverviewHook -> View       PortTabHook   -> View
 *   MenuEntryHook      -> array      SinglePageHook -> array
 *   SettingsHook       -> array
 *
 * NOTE: PluginManager instantiates hooks with `new $class` and no arguments. A
 * required constructor parameter is a fatal error at class load and would 500
 * every device page just as surely.
 */
final class DeviceOverview implements DeviceOverviewHook
{
    /**
     * Deliberately takes no injected $user. LibreNMS binds
     * Illuminate\Contracts\Auth\Authenticatable, but NOT App\Models\User -- a
     * User type-hint silently yields a fresh, empty model.
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
     * synchronous network call is permitted -- reachability comes from a
     * short-lived cache populated out of band.
     *
     * @param  array<string, mixed>  $settings
     * @param  object  $device  A LibreNMS App\Models\Device. Typed loosely
     *                          because the parameter itself is untyped (core
     *                          injects it by name) and because tests
     *                          substitute a stand-in.
     */
    public function handle(string $pluginName, array $settings, $device): Htmlable
    {
        return Guard::safely(
            static function () use ($pluginName, $device): Htmlable {
                // Resolved from the container rather than constructed here:
                // PluginManager forbids constructor arguments, but the
                // container still allows the presenter to be substituted in
                // tests and by anyone extending this.
                $panel = app(DevicePanelPresenter::class)->present(Auth::user(), $device);

                // Nothing to say about this device: render nothing at all
                // rather than a permanent "not configured" box on every device
                // in the estate. An empty Htmlable is still safe for `{{ }}`.
                if ($panel['state'] === 'hidden') {
                    return new HtmlString('');
                }

                return view($pluginName.'::device-overview', [
                    'device' => $device,
                    'pluginName' => $pluginName,
                    'panel' => $panel,
                ]);
            },
            new HtmlString(''),
            'DeviceOverview::handle'
        );
    }
}
