<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * Single source of truth for "this database name is allowed for RefreshDatabase / migrate:fresh in tests"
 * vs "this is a dedicated sandbox schema for destructive artisan commands".
 */
final class TestDatabaseSchema
{
    /**
     * Extra DB names (comma-separated in .env) permitted for migrate:fresh outside APP_ENV=testing
     * (e.g. a disposable staging schema in CI).
     *
     * @return list<string>
     */
    public static function destructiveAllowlistFromEnv(): array
    {
        $raw = (string) env('DESTRUCTIVE_ALLOWED_DATABASES', '');

        return array_values(array_filter(array_map(static fn (string $s): string => trim($s), explode(',', $raw))));
    }

    public static function isPermittedForDestructiveRefresh(string $driver, string $database): bool
    {
        if (in_array($database, self::destructiveAllowlistFromEnv(), true)) {
            return true;
        }

        $driver = strtolower($driver);

        if ($driver === 'sqlite') {
            if ($database === ':memory:' || $database === '') {
                return true;
            }

            return str_contains($database, 'testing')
                || str_contains($database, 'test.sqlite')
                || str_contains($database, '/testing')
                || str_contains($database, '\\testing');
        }

        if (in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            return $database === 'testing'
                || str_ends_with($database, '_testing')
                || str_ends_with($database, '_test');
        }

        return false;
    }
}
