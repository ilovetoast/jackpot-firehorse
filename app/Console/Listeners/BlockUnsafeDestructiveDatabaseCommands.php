<?php

declare(strict_types=1);

namespace App\Console\Listeners;

use App\Support\Database\TestDatabaseSchema;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * Blocks migrate:fresh, migrate:refresh, and db:wipe against non-sandbox databases unless explicitly allowed.
 *
 * {@see TestCase::assertTestDatabaseIsIsolated()} protects PHPUnit. This listener protects interactive
 * `php artisan migrate:fresh` (and similar) from wiping a primary dev DB when misconfigured.
 */
final class BlockUnsafeDestructiveDatabaseCommands
{
    private const COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'db:wipe',
    ];

    public function handle(CommandStarting $event): void
    {
        $name = $event->command;
        if ($name === null || ! in_array($name, self::COMMANDS, true)) {
            return;
        }

        if (App::environment('testing')) {
            return;
        }

        if (filter_var(env('ALLOW_DATABASE_DESTRUCTION', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $input = $event->input;
        if ($input === null) {
            return;
        }

        $connectionOption = null;
        if ($input->hasOption('database')) {
            $connectionOption = $input->getOption('database');
        }

        $connectionName = is_string($connectionOption) && $connectionOption !== ''
            ? $connectionOption
            : (string) config('database.default');

        $cfg = config("database.connections.{$connectionName}");
        if (! is_array($cfg)) {
            throw new RuntimeException(
                "Blocked {$name}: unknown database connection [{$connectionName}]. See docs/TESTING_DATABASE.md."
            );
        }

        $driver = (string) ($cfg['driver'] ?? '');
        $database = (string) ($cfg['database'] ?? '');

        if (TestDatabaseSchema::isPermittedForDestructiveRefresh($driver, $database)) {
            return;
        }

        throw new RuntimeException(
            "Blocked {$name} on connection [{$connectionName}] (driver {$driver}, database [{$database}]). ".
            'Destructive schema commands are only allowed when the target is a dedicated sandbox '.
            '(name `testing`, suffix `_testing` / `_test`, SQLite :memory: or path containing `testing`, '.
            'or a name listed in DESTRUCTIVE_ALLOWED_DATABASES). '.
            'To override intentionally, set ALLOW_DATABASE_DESTRUCTION=true in the environment for this command. '.
            'See docs/TESTING_DATABASE.md.'
        );
    }
}
