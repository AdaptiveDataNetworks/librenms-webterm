<?php

declare(strict_types=1);

namespace Adn\WebTerm\Tests;

use Adn\WebTerm\WebTermServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [WebTermServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Fixed rather than random: the credential tests assert that a stored
        // payload decrypts, and a key that changed between boot and assertion
        // would fail for the wrong reason.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('webterm-test-key', 2)));

        // In-memory SQLite: the authorization matrix is thousands of
        // combinations and must stay fast enough to run on every save.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Migrations are loaded by the provider only when the plugin is enabled in
     * LibreNMS, which never happens under Testbench -- so load them directly.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
