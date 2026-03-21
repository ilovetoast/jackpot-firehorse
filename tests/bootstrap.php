<?php

declare(strict_types=1);

/**
 * PHPUnit-only bootstrap (see phpunit.xml `bootstrap="tests/bootstrap.php"`).
 *
 * Always force the MySQL database name to `testing` before Laravel or `.env` load.
 * `RefreshDatabase` runs `migrate:fresh` — if this pointed at your dev DB (e.g. `laravel`),
 * that data is wiped. This file is not used by `artisan` or the web app.
 */
putenv('DB_DATABASE=testing');
$_ENV['DB_DATABASE'] = 'testing';
$_SERVER['DB_DATABASE'] = 'testing';

require __DIR__.'/../vendor/autoload.php';
