<?php

declare(strict_types=1);

namespace Adn\WebTerm\Hooks;

use Adn\WebTerm\Support\Guard;
use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/**
 * Settings panel at /plugin/settings/WebTerm.
 *
 * Implemented against the interface, NOT LibreNMS's
 * App\Plugins\Hooks\SettingsHook abstract: that abstract calls data() twice and
 * feeds its own return value back in as $settings, which corrupts the panel.
 *
 * This panel shows read-only status only (gateway reachability, credential
 * provider health, protocol version). Secrets are never rendered here --
 * PluginSettingsController stores the whole settings bag as plaintext JSON and
 * echoes values into value="" attributes.
 */
final class Settings implements SettingsHook
{
    public function authorize(Authenticatable $user): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        return Guard::safely(
            static fn (): array => [
                'content_view' => $pluginName.'::settings',
                'settings' => $settings,
            ],
            ['content_view' => $pluginName.'::settings', 'settings' => []],
            'Settings::handle'
        );
    }
}
