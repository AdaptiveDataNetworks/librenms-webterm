<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Tests;

use AdaptiveDataNetworks\WebTerm\Database\MigrationRunner;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deprecations from OUR code must fail the suite.
        //
        // Two layers hide them by default. Testbench drops error_reporting to
        // 245, masking E_DEPRECATED entirely; and Laravel's HandleExceptions
        // installs a handler that routes deprecations to a log channel rather
        // than raising them, so phpunit.xml's failOnDeprecation never sees one.
        //
        // A curl_close() deprecation on PHP 8.5 reached a user's console
        // because of exactly this. The handler below is deliberately scoped to
        // src/: vendor deprecations are not ours to fix and would only make the
        // suite fail for reasons a contributor cannot act on.
        error_reporting(E_ALL);

        $src = (string) realpath(__DIR__.'/../src');
        $src = $src === '' ? '' : $src.DIRECTORY_SEPARATOR;

        $previous = set_error_handler(
            static function (int $severity, string $message, string $file = '', int $line = 0) use ($src) {
                if ($severity === E_DEPRECATED && $src !== '' && str_starts_with($file, $src)) {
                    throw new \RuntimeException(sprintf(
                        'Deprecation in %s:%d -- %s',
                        substr($file, strlen($src)),
                        $line,
                        $message
                    ));
                }

                return false; // defer to Laravel's handler
            }
        );

        $this->beforeApplicationDestroyed(static function () use ($previous): void {
            restore_error_handler();
            unset($previous);
        });
    }

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
     * Migrate through the runner the plugin actually uses in production.
     *
     * Not Testbench's loadMigrationsFrom(): that records migrations in core's
     * `migrations` table, which is precisely the thing we no longer do -- see
     * {@see MigrationRunner}. Driving
     * the real runner here means every test in the suite exercises it.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->app->make(MigrationRunner::class)->migrate();
    }
}
