<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\TenantModule;
use App\Support\Database\TestDatabaseSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
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
     *
     * Never "fail open" when APP_ENV is not testing: the old behaviour returned early here, which skipped
     * the database-name check entirely while RefreshDatabase still ran — migrate:fresh could wipe the
     * dev database if .env pointed at it.
     */
    protected function assertTestDatabaseIsIsolated(): void
    {
        if (config('app.env') !== 'testing') {
            throw new RuntimeException(
                'Refusing to run tests: APP_ENV must be "testing" when using RefreshDatabase. '.
                'You are probably running PHPUnit without phpunit.xml (wrong working directory) or without '.
                'tests/bootstrap.php. cd into the Laravel project root and use ./vendor/bin/phpunit, '.
                'composer test, or ./vendor/bin/sail test. See docs/TESTING_DATABASE.md.'
            );
        }

        $connectionName = (string) config('database.default');
        $config = config("database.connections.{$connectionName}");
        if (! is_array($config)) {
            throw new RuntimeException(
                'Refusing to run tests: database connection ['.$connectionName.'] is not configured. See docs/TESTING_DATABASE.md.'
            );
        }

        $driver = (string) ($config['driver'] ?? 'mysql');
        $database = (string) ($config['database'] ?? '');

        $envDb = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');
        if (is_string($envDb) && $envDb !== '' && $envDb !== $database) {
            throw new RuntimeException(
                "Refusing to run tests: process DB_DATABASE [{$envDb}] disagrees with Laravel config database [{$database}]. ".
                'Fix .env / config cache (run `php artisan config:clear`) and ensure phpunit.xml + tests/bootstrap.php run. See docs/TESTING_DATABASE.md.'
            );
        }

        if (! TestDatabaseSchema::isPermittedForDestructiveRefresh($driver, $database)) {
            throw new RuntimeException(
                "Refusing to run tests: database [{$database}] (driver {$driver}) is not an isolated testing database. ".
                'Use DB_DATABASE=testing, a *_testing / *_test schema name, SQLite :memory:, or a path containing "testing". '.
                'Never point tests at your primary dev/prod schema. See docs/TESTING_DATABASE.md.'
            );
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                $row = DB::connection($connectionName)->selectOne('SELECT DATABASE() as db');
                $live = $row && isset($row->db) ? (string) $row->db : '';
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'Refusing to run tests: could not verify MySQL database context: '.$e->getMessage(),
                    0,
                    $e
                );
            }
            if ($live !== $database) {
                throw new RuntimeException(
                    "Refusing to run tests: MySQL reports active schema [{$live}] but config expects [{$database}]. ".
                    'You may be connected to the wrong database (e.g. user default schema). See docs/TESTING_DATABASE.md.'
                );
            }
        }

        if ($driver === 'pgsql') {
            try {
                $row = DB::connection($connectionName)->selectOne('SELECT current_database() as db');
                $live = $row && isset($row->db) ? (string) $row->db : '';
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'Refusing to run tests: could not verify PostgreSQL database context: '.$e->getMessage(),
                    0,
                    $e
                );
            }
            if ($live !== $database) {
                throw new RuntimeException(
                    'Refusing to run tests: PostgreSQL reports active database ['.$live.'] but config expects ['.$database.']. See docs/TESTING_DATABASE.md.'
                );
            }
        }
    }

    /**
     * Phase 8: Creator (Prostaff) module gate — prostaff flows require an enabled {@see TenantModule} row in tests.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function enableCreatorModuleForTenant(Tenant $tenant, array $overrides = []): TenantModule
    {
        $defaults = [
            'status' => 'active',
            'expires_at' => null,
            'granted_by_admin' => false,
        ];

        return TenantModule::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'module_key' => TenantModule::KEY_CREATOR,
            ],
            array_merge($defaults, $overrides)
        );
    }
}
