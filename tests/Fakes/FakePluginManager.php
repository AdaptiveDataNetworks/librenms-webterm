<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Tests\Fakes;

use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use RuntimeException;

/**
 * Stands in for LibreNMS's plugin manager, which core binds and Testbench does
 * not. Enough of it to boot the provider and drive the migration listener.
 */
final class FakePluginManager implements PluginManagerInterface
{
    /** @var array<int, array{string, string, string}> */
    public array $published = [];

    public function __construct(
        private readonly bool $enabled = true,
        private readonly bool $throw = false,
    ) {}

    public function publishHook(string $pluginName, string $hookType, string $implementationClass): bool
    {
        $this->published[] = [$pluginName, $hookType, $implementationClass];

        return true;
    }

    public function hasHooks(string $hookType, array $args = [], ?string $plugin = null): bool
    {
        return $this->published !== [];
    }

    public function call(string $hookType, array $args = [], ?string $plugin = null): array
    {
        return [];
    }

    public function getSettings(string $pluginName): array
    {
        return [];
    }

    public function setSettings(string $pluginName, array $settings): bool
    {
        return true;
    }

    public function pluginExists(string $pluginName): bool
    {
        return true;
    }

    public function pluginEnabled(string $pluginName): bool
    {
        if ($this->throw) {
            throw new RuntimeException('core is having a bad day');
        }

        return $this->enabled;
    }

    public function cleanupPlugins(): void {}
}
