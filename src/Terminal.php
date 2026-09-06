<?php

declare(strict_types=1);

namespace Adn\WebTerm;

use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * Full-page terminal, reachable at /plugin/WebTerm.
 *
 * Implemented straight off the interface rather than extending LibreNMS's
 * App\Plugins\Hooks\PageHook abstract, matching the official example plugin.
 */
final class Terminal implements SinglePageHook
{
    public function authorize(Authenticatable $user): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        return [
            'content_view' => $pluginName.'::terminal',
            'settings' => $settings,
        ];
    }
}
