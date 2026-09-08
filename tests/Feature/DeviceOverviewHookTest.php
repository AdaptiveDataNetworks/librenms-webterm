<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Hooks\DeviceOverview;
use AdaptiveDataNetworks\WebTerm\Http\DevicePanelPresenter;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeDevice;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| LibreNMS renders this hook with `{{ $pluginView }}` in its own
| device/tabs/overview.blade.php. Returning an array threw
| "htmlspecialchars(): Argument #1 must be of type string, array given" inside
| core's template and 500'd EVERY device page -- for every device, whether or
| not WebTerm was configured, and Guard could not catch it because the failure
| happened after our hook returned.
|
| These tests exercise the same call core makes: e($result).
*/

beforeEach(function () {
    $this->app['view']->addNamespace('WebTerm', __DIR__.'/../../resources/views');
    // A device page always renders for an authenticated user; without one the
    // presenter takes its "unavailable" safety path and the assertions below
    // would be testing the wrong branch.
    $this->actingAs(new FakeUser(7));
});

it('returns something LibreNMS can render with {{ }}', function () {
    config()->set('webterm.enabled', true);

    $result = (new DeviceOverview)->handle('WebTerm', [], new FakeDevice(42));

    expect($result)->toBeInstanceOf(Htmlable::class);
    // Exactly what overview.blade.php line 14 does.
    expect(fn (): string => e($result))->not->toThrow(TypeError::class);
});

it('renders an empty string when there is nothing to show', function () {
    // Most devices are never terminal-enabled; the panel must vanish rather
    // than print a box on every device page.
    config()->set('webterm.enabled', true);

    expect(trim(e((new DeviceOverview)->handle('WebTerm', [], new FakeDevice(999)))))->toBe('');
});

it('renders real markup when the panel says a terminal is available', function () {
    config()->set('webterm.enabled', true);
    Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => 'database', 'host_key_policy' => 'pin', 'principal' => 'netops',
    ]);

    // Authorization itself is covered exhaustively elsewhere; here the point is
    // that a "ready" panel renders markup core can display.
    app()->bind(DevicePanelPresenter::class, fn () => new class extends DevicePanelPresenter
    {
        public function present(?Authenticatable $user, object $device): array
        {
            return ['state' => 'ready', 'message' => '', 'device_id' => 42, 'reason' => null];
        }
    });

    $html = e((new DeviceOverview)->handle('WebTerm', [], new FakeDevice(42)));

    expect($html)->toContain('panel')
        ->and($html)->toContain('Open terminal');
});

it('still returns a renderable value when everything goes wrong', function () {
    // Guard's fallback must also satisfy `{{ }}`; returning [] there would
    // reintroduce the same 500.
    config()->set('webterm.enabled', true);

    $broken = new class
    {
        public function __get(string $name): mixed
        {
            throw new RuntimeException('unexpected model shape');
        }
    };

    $result = (new DeviceOverview)->handle('WebTerm', [], $broken);

    expect($result)->toBeInstanceOf(Htmlable::class)
        ->and(fn (): string => e($result))->not->toThrow(TypeError::class);
});
