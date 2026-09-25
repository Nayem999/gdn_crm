<?php

namespace Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The test database also carries the stand-in tables some suites use —
     * see tests/Fixtures/migrations for why they cannot be created mid-test.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $register = fn (Migrator $migrator) => $migrator->path(__DIR__.'/Fixtures/migrations');

        $app->afterResolving('migrator', $register);

        if ($app->resolved('migrator')) {
            $register($app->make('migrator'));
        }

        return $app;
    }
}
