<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = parent::createApplication();

        $app['config']->set('app.env', 'testing');

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * RefreshDatabase truncates whatever it is pointed at. A stale DB_DATABASE
     * in the environment — or a phpunit.xml that stops being loaded — would
     * therefore wipe the working database on the next `php artisan test`,
     * silently and irreversibly.
     *
     * Refusing to boot unless the target database name is explicitly marked as
     * a test database is the only check that runs before any destructive code
     * does. It is deliberately a hard failure, not a warning.
     */
    private function guardAgainstNonTestDatabase(Application $app): void
    {
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($database === ':memory:') {
            return;
        }

        if (! str_contains(strtolower(basename($database)), 'test')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against "%s" on connection "%s": the database name '
                .'does not contain "test". Check phpunit.xml is being loaded and DB_DATABASE '
                .'is not overridden in your environment.',
                $database,
                $connection,
            ));
        }
    }
}
