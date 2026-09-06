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
}
