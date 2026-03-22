<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Must run BEFORE {@see RefreshDatabase} applies migrations. Laravel calls this from
     * {@see BaseTestCase::setUp()} after the application is booted but before traits run.
     *
     * Previously we called {@see assertTestDatabaseIsIsolated()} after parent::setUp(), which is
     * too late — RefreshDatabase had already run migrate:fresh on whatever DB was configured.
     */
    protected function setUpTraits(): void
    {
        $uses = array_flip(class_uses_recursive(static::class));
        if (isset($uses[RefreshDatabase::class])) {
            $this->assertTestDatabaseIsIsolated();
        }

        parent::setUpTraits();
    }

    /**
     * RefreshDatabase runs migrate:fresh. If DB_DATABASE points at the dev database, data is destroyed.
     * phpunit.xml + tests/bootstrap.php should force DB_DATABASE=testing; this is a last-resort check.
     */
    protected function assertTestDatabaseIsIsolated(): void
    {
        if (config('app.env') !== 'testing') {
            return;
        }

        $connectionName = (string) config('database.default');
        $config = config("database.connections.{$connectionName}");
        if (! is_array($config)) {
            return;
        }

        $driver = $config['driver'] ?? 'mysql';
        $database = $config['database'] ?? '';

        if ($driver === 'sqlite') {
            if ($database === ':memory:' || $database === '') {
                return;
            }
            $path = (string) $database;
            if (str_contains($path, 'testing') || str_contains($path, 'test.sqlite')) {
                return;
            }

            throw new RuntimeException(
                'Refusing to run tests: SQLite path does not look like a dedicated test database. '.
                'Use :memory: or a path containing "testing". See docs/TESTING_DATABASE.md.'
            );
        }

        $database = (string) $database;
        $allowedExact = ['testing'];
        if (in_array($database, $allowedExact, true)) {
            return;
        }

        foreach (['_testing', '_test'] as $suffix) {
            if ($database !== '' && str_ends_with($database, $suffix)) {
                return;
            }
        }

        throw new RuntimeException(
            "Refusing to run tests: database [{$database}] is not an isolated testing database. ".
            'Use DB_DATABASE=testing (see phpunit.xml and tests/bootstrap.php). See docs/TESTING_DATABASE.md.'
        );
    }
}
