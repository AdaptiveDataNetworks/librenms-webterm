<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTabPresenter;
use AdaptiveDataNetworks\WebTerm\Tests\Fakes\FakePluginManager;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeDevice;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

uses(RefreshDatabase::class);

/**
 * The terminal as a device-page tab.
 *
 * Core has no device-tab hook, so this appends to a public static on a core
 * view component. Not a supported extension point, which is why the guards
 * matter more than the feature: a device page that 500s because of us is a far
 * worse outcome than no tab, and this project has already shipped exactly that
 * once.
 */
function bootWithTab(): void
{
    config()->set('webterm.enabled', true);
    app()->instance(PluginManagerInterface::class, new FakePluginManager(enabled: true));
    (new WebTermServiceProvider(app()))->boot();
}

it('resolves its view as the name core will look for', function (): void {
    // DeviceController resolves 'device.tabs.{slug}'. If that misses, core
    // falls through to renderLegacyTab(), which includes a legacy file that
    // does not exist for our slug -- a 500 on the device page, not a blank tab.
    bootWithTab();

    expect(View::exists('device.tabs.webterm'))->toBeTrue();
});

it('adds exactly one file to the shared view namespace', function (): void {
    // addLocation() appends to the DEFAULT namespace, so anything in that
    // directory answers for any view name core fails to resolve. It cannot
    // shadow a core view -- core's paths are searched first -- but nothing
    // extra may live there.
    $files = [];
    $base = __DIR__.'/../../resources/views/core';

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $files[] = str_replace($base.'/', '', $file->getPathname());
    }

    expect($files)->toBe(['device/tabs/webterm.blade.php']);
});

it('places the tab after notes, and is idempotent about it', function (): void {
    $provider = new WebTermServiceProvider(app());
    $splice = (new ReflectionClass($provider))->getMethod('spliceTabAfter');

    $tabs = ['overview' => 'A', 'config' => 'B', 'notes' => 'C', 'edit' => 'D'];

    $once = $splice->invoke($provider, $tabs, 'notes', 'webterm', 'W');
    expect(array_keys($once))->toBe(['overview', 'config', 'notes', 'webterm', 'edit']);

    // Re-running must not move or duplicate it.
    $twice = $splice->invoke($provider, $once, 'notes', 'webterm', 'W');
    expect(array_keys($twice))->toBe(array_keys($once));
});

it('appends when the anchor tab is gone, rather than failing', function (): void {
    // Core renaming or removing 'notes' must cost us placement, not the tab.
    $provider = new WebTermServiceProvider(app());
    $splice = (new ReflectionClass($provider))->getMethod('spliceTabAfter');

    $result = $splice->invoke($provider, ['overview' => 'A'], 'notes', 'webterm', 'W');

    expect(array_keys($result))->toBe(['overview', 'webterm']);
});

it('does not register the tab when the plugin is switched off', function (): void {
    // visible() is the only thing gating the LINK, so it must respect the kill
    // switch even though the real decision lives in data().
    config()->set('webterm.enabled', false);

    expect((new DeviceTabPresenter)->visibleFor(42))->toBeFalse();
});

it('denies in data() rather than trusting visible()', function (): void {
    // /device/{id}/webterm is reachable by anyone who can view the device:
    // DeviceController authorizes `view` and calls data() directly, never
    // consulting visible(). So data() must make the real decision.
    bootWithTab();

    $data = (new DeviceTabPresenter)->dataFor(new FakeDevice(42));

    expect($data['webtermState'])->toBe('denied')
        ->and($data)->not->toHaveKey('webtermDeviceId');
});

/**
 * Renders the tab view the way DeviceController actually does.
 *
 * It does NOT spread a tab's data() return into the view. It nests it:
 *
 *     $data = $tab_controller->data($device, $request);
 *     $data_array = ['title' =>, 'device' =>, 'device_id' =>, 'data' => $data, ...];
 *     return view('device.tabs.'.$current_tab, $data_array);
 *
 * so a view reading $webtermState finds nothing and silently shows its fallback
 * text -- which is exactly what shipped. Core's own tabs read $data[...]; see
 * resources/views/device/tabs/config.blade.php.
 */
function renderTabLikeCore(array $data): string
{
    return view('device.tabs.webterm', [
        'title' => 'Terminal',
        'device' => null,
        'device_id' => 42,
        'data' => $data,
        'vars' => [],
        'current_tab' => 'webterm',
        'request' => request(),
    ])->render();
}

it('renders the ready state when core nests the data as it really does', function (): void {
    bootWithTab();

    $html = renderTabLikeCore(['webtermState' => 'ready', 'webtermDeviceId' => 42]);

    expect($html)->toContain('Open terminal')
        ->and($html)->toContain('plugin/WebTerm?device=42')
        ->and($html)->not->toContain('unavailable');
});

it('shows the real denial reason, not a generic fallback', function (): void {
    // The generic text is the view's last resort. Seeing it means the data
    // never arrived, which is a wiring bug rather than a denial.
    bootWithTab();

    $html = renderTabLikeCore([
        'webtermState' => 'denied',
        'webtermReason' => 'A deny rule blocks your access to this device.',
        'webtermFix' => './lnms webterm:grant --user=x --device=y --deny --remove',
    ]);

    expect($html)->toContain('A deny rule blocks your access')
        ->and($html)->toContain('webterm:grant')
        ->and($html)->not->toContain('The terminal is unavailable for this device.');
});
