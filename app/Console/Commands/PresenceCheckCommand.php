<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Presence Redis diagnostic command.
 *
 * Use to troubleshoot presence/online indicator when running locally (Sail or host).
 *
 * Usage: php artisan presence:check
 *        sail artisan presence:check
 */
class PresenceCheckCommand extends Command
{
    protected $signature = 'presence:check';

    protected $description = 'Diagnose Redis connection for presence (online indicator)';

    public function handle(): int
    {
        $connection = config('database.redis.presence_connection', 'default');
        $host = config("database.redis.{$connection}.host", config('database.redis.default.host'));

        $this->info('Presence Redis Diagnostic');
        $this->line('─────────────────────────');
        $this->line("Connection: {$connection}");
        $this->line("Host: {$host}");
        $this->line('');

        try {
            $redis = Redis::connection($connection);
            $redis->ping();
            $this->info('✓ Redis connection successful');
        } catch (\Throwable $e) {
            $this->error('✗ Redis connection failed');
            $this->line($e->getMessage());
            $this->newLine();
            $this->warn('Troubleshooting:');
            $this->line('  • Sail: Ensure containers are up (./vendor/bin/sail up)');
            $this->line('  • Sail: Redis host "redis" resolves inside container');
            $this->line('  • Host: If running php artisan serve outside Docker, set REDIS_HOST=127.0.0.1');
            $this->line('  • Host: Or set REDIS_PRESENCE_CONNECTION=presence and REDIS_PRESENCE_HOST=127.0.0.1');
            return Command::FAILURE;
        }

        $this->line('');
        $this->info('Presence is ready. The online indicator will work when Redis is reachable.');

        return Command::SUCCESS;
    }
}
