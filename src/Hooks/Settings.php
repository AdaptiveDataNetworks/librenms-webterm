<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Hooks;

use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Support\Guard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
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
     * Whether the current user actually holds WebTerm's admin ability.
     *
     * Note this is NOT the LibreNMS admin role. Core's Gate::before returns
     * true for every ability to a LibreNMS admin, which is why the console
     * gates on our own table instead -- and why holding the role says nothing
     * about whether the link will work.
     */
    private static function consoleAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return Ability::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('ability', Ability::ADMIN)
            ->exists();
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
                // The console answers 404 to anyone without WebTerm's own admin
                // ability -- deliberately, so its existence is not confirmed.
                // Linking to it unconditionally therefore hands a LibreNMS
                // admin who lacks that ability a button that goes nowhere.
                'webtermConsole' => self::consoleAccess(),
            ],
            ['content_view' => $pluginName.'::settings', 'settings' => [], 'webtermConsole' => false],
            'Settings::handle'
        );
    }
}
