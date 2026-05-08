<?php

declare(strict_types=1);

/**
 * PHPUnit-only bootstrap (see phpunit.xml `bootstrap="tests/bootstrap.php"`).
 *
 * Always force an isolated test environment before Laravel or `.env` load.
 * `RefreshDatabase` runs `migrate:fresh` — if Laravel connected to your primary schema, that data is wiped.
 *
 * ## Why we delete bootstrap/cache/config.php
 *
 * If you ran `php artisan config:cache` while connected to your primary database, the cached file
 * contains a **frozen** `DB_DATABASE` value. PHPUnit env vars alone cannot override that —
 * Laravel would still connect to the wrong database and RefreshDatabase would wipe it.
 * Removing the cache forces config to be read from `config/*.php` + env for this run.
 *
 * ## Never use your primary schema name as DB_DATABASE for tests
 *
 * If your real data lives in a MySQL database literally named `testing`, PHPUnit's default would still
 * treat it as "safe". Use a distinct name (e.g. `jackpot_testing`) and set phpunit.xml + this bootstrap accordingly.
 */
$basePath = dirname(__DIR__);

$configCache = $basePath.'/bootstrap/cache/config.php';
if (is_file($configCache)) {
    @unlink($configCache);
}

// Belt-and-suspenders: stale route cache should not affect DB, but config cache is the usual foot-gun.
$routeCaches = glob($basePath.'/bootstrap/cache/routes*.php') ?: [];
foreach ($routeCaches as $routeCacheFile) {
    if (is_file($routeCacheFile)) {
        @unlink($routeCacheFile);
    }
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

putenv('APP_RUNNING_UNIT_TESTS=1');
$_ENV['APP_RUNNING_UNIT_TESTS'] = '1';
$_SERVER['APP_RUNNING_UNIT_TESTS'] = '1';

putenv('DB_DATABASE=testing');
$_ENV['DB_DATABASE'] = 'testing';
$_SERVER['DB_DATABASE'] = 'testing';

require $basePath.'/vendor/autoload.php';
