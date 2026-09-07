<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Models\Setting;
use AdaptiveDataNetworks\WebTerm\Support\RuntimeSettings;
use AdaptiveDataNetworks\WebTerm\Support\SettingValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
| webterm_config exists so an administrator can change settings without editing
| files. For a while nothing read it back: `webterm:config set` wrote a row,
| applied it to the CLI process, and the next web request fell back to the
| config file. Turning the plugin on appeared to succeed and did nothing.
*/

it('applies a setting written by the CLI to a subsequent request', function () {
    config()->set('webterm.audit.syslog', false);

    $this->artisan('webterm:config', ['action' => 'set', 'key' => 'enabled', 'value' => 'true'])
        ->assertExitCode(0);

    // A fresh process starts from the config file.
    config()->set('webterm.enabled', false);

    RuntimeSettings::apply();

    expect(config('webterm.enabled'))->toBeTrue();
});

it('coerces "false" to a real boolean, not a truthy string', function () {
    // The dangerous case: the string "false" is truthy, so a kill switch set to
    // "false" would read as ON.
    expect(SettingValue::coerce('false', true))->toBeFalse()
        ->and(SettingValue::coerce('true', false))->toBeTrue();
});

it('coerces types to match what the config file declares', function () {
    expect(SettingValue::coerce('900', 300))->toBe(900)
        ->and(SettingValue::coerce('  true  ', false))->toBeTrue()
        ->and(SettingValue::coerce('pin', 'tofu'))->toBe('pin')
        ->and(SettingValue::coerce('null', 'x'))->toBeNull();
});

it('splits a list setting the way the config file expects one', function () {
    // security.allowed_origins is a list; a bare string would break callers
    // that iterate it.
    expect(SettingValue::coerce('https://a.example.com, https://b.example.com', []))
        ->toBe(['https://a.example.com', 'https://b.example.com'])
        ->and(SettingValue::coerce('', []))->toBe([]);
});

it('applies list settings end to end', function () {
    config()->set('webterm.audit.syslog', false);
    config()->set('webterm.security.allowed_origins', []);

    Setting::create(['key' => 'security.allowed_origins', 'value' => 'https://librenms.example.com']);
    RuntimeSettings::flush();
    RuntimeSettings::apply();

    expect(config('webterm.security.allowed_origins'))->toBe(['https://librenms.example.com']);
});

it('survives a database with no settings table', function () {
    // This runs on every request, including during `lnms plugin:add` before the
    // migrations have been applied.
    Schema::drop('webterm_config');
    RuntimeSettings::flush();

    expect(fn () => RuntimeSettings::apply())->not->toThrow(Exception::class);
});

it('flushes its cache when a setting changes', function () {
    config()->set('webterm.audit.syslog', false);

    RuntimeSettings::apply();
    expect(Cache::has(RuntimeSettings::CACHE_KEY))->toBeTrue();

    $this->artisan('webterm:config', ['action' => 'set', 'key' => 'enabled', 'value' => 'true']);

    expect(Cache::has(RuntimeSettings::CACHE_KEY))->toBeFalse();
});

it('lets unset fall back to the configured default', function () {
    config()->set('webterm.audit.syslog', false);

    $this->artisan('webterm:config', ['action' => 'set', 'key' => 'enabled', 'value' => 'true']);
    config()->set('webterm.enabled', false);
    RuntimeSettings::apply();
    expect(config('webterm.enabled'))->toBeTrue();

    $this->artisan('webterm:config', ['action' => 'unset', 'key' => 'enabled']);
    config()->set('webterm.enabled', false);
    RuntimeSettings::apply();

    expect(config('webterm.enabled'))->toBeFalse();
});
