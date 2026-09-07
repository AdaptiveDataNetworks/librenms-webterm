<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Support;

use AdaptiveDataNetworks\WebTerm\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Applies runtime settings from webterm_config over the config file.
 *
 * Without this, `webterm:config set` wrote a row that nothing ever read: the
 * value applied only to the CLI process that wrote it, and the next web request
 * fell back to the file. An administrator turning the plugin on would see the
 * command succeed and nothing change -- the worst kind of failure, because it
 * looks like it worked.
 *
 * Loading must survive a database that is absent, unmigrated or unreachable:
 * this runs on every request, including during `lnms plugin:add` before the
 * migrations have been applied.
 */
final class RuntimeSettings
{
    public const CACHE_KEY = 'webterm:runtime-settings';

    private const TTL = 60;

    public static function apply(): void
    {
        Guard::safely(
            static function (): bool {
                /** @var array<string, string> $settings */
                $settings = Cache::remember(self::CACHE_KEY, self::TTL, static function (): array {
                    // hasTable is cheap and avoids an exception on a fresh
                    // install where the migrations have not run yet.
                    if (! Schema::hasTable('webterm_config')) {
                        return [];
                    }

                    return Setting::query()->pluck('value', 'key')->all();
                });

                foreach ($settings as $key => $value) {
                    $path = 'webterm.'.$key;
                    config()->set($path, SettingValue::coerce((string) $value, config($path)));
                }

                return true;
            },
            false,
            'RuntimeSettings::apply'
        );
    }

    /**
     * Called whenever a setting changes, so the next request sees it rather
     * than waiting out the cache.
     */
    public static function flush(): void
    {
        Guard::safely(
            static fn (): bool => Cache::forget(self::CACHE_KEY),
            false,
            'RuntimeSettings::flush'
        );
    }
}
