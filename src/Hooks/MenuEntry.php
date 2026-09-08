<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Hooks;

use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Support\Guard;
use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook as MenuEntryHookContract;

/**
 * Puts the console in LibreNMS's navigation.
 *
 * It lands at Overview -> Plugins -> WebTerm, which is a sibling of
 * Overview -> Tools in the same dropdown -- one line above it in core's menu
 * blade. The Tools menu itself is genuinely unreachable: Oxidized and the RIPE
 * NCC API are hardcoded <li> elements there, gated by config and a Gate
 * ability, and there is no array a plugin can append to. MenuEntryHook is the
 * only navigation insertion point core offers.
 *
 * Two details here are load-bearing, and getting either wrong breaks every page
 * in LibreNMS rather than just the menu:
 *
 *   - handle() MUST return a two-element list. The menu blade destructures it
 *     as `@foreach($menu_hooks as [$view, $data])`.
 *   - authorize() is called OUTSIDE any try/catch in core, so it must not throw
 *     under any circumstance -- including a database that is unavailable or
 *     unmigrated.
 */
final class MenuEntry implements MenuEntryHookContract
{
    public function authorize(Authenticatable $user): bool
    {
        // Deliberately total. An exception here is not contained by anything,
        // so a missing table or an unreachable database must read as "no menu
        // entry", never as a broken LibreNMS.
        return Guard::safely(
            static fn (): bool => Ability::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('ability', Ability::ADMIN)
                ->exists(),
            false,
            'MenuEntry::authorize'
        );
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    public function handle(string $pluginName): array
    {
        return [$pluginName.'::menu-entry', []];
    }
}
