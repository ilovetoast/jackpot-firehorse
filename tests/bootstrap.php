<?php

declare(strict_types=1);

/**
 * PHPUnit-only bootstrap (see phpunit.xml `bootstrap="tests/bootstrap.php"`).
 *
 * Always force the MySQL database name to `testing` before Laravel or `.env` load.
 * `RefreshDatabase` runs `migrate:fresh` — if this pointed at your dev DB (e.g. `laravel`),
 * that data is wiped. This file is not used by `artisan` or the web app.
 *
 * ## Why we delete bootstrap/cache/config.php
 *
 * If you ran `php artisan config:cache` while connected to your dev database, the cached file
 * contains a **frozen** `DB_DATABASE` value. PHPUnit env vars alone cannot override that —
 * Laravel would still connect to the wrong database and RefreshDatabase would wipe it.
 * Removing the cache forces config to be read from `config/*.php` + env for this run.
 */
$basePath = dirname(__DIR__);
$configCache = $basePath.'/bootstrap/cache/config.php';
if (is_file($configCache)) {
    @unlink($configCache);
}

putenv('DB_DATABASE=testing');
$_ENV['DB_DATABASE'] = 'testing';
$_SERVER['DB_DATABASE'] = 'testing';

require $basePath.'/vendor/autoload.php';
