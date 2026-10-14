<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $environment = static fn (string $name): mixed => getenv($name) !== false
            ? getenv($name)
            : ($_ENV[$name] ?? $_SERVER[$name] ?? null);
        if ($environment('APP_ENV') !== 'testing'
            || $environment('DB_CONNECTION') !== 'sqlite'
            || $environment('DB_DATABASE') !== ':memory:'
            || ! in_array($environment('DB_URL'), [null, ''], true)) {
            throw new RuntimeException('Tests require an explicit testing environment and in-memory SQLite before application bootstrap.');
        }

        if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Clear cached application configuration before running database tests.');
        }

        $app = parent::createApplication();
        $connection = $app['db']->connection();

        if (! $app->environment('testing')
            || $connection->getDriverName() !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Tests require APP_ENV=testing and an isolated in-memory SQLite database before schema setup.');
        }

        return $app;
    }
}
