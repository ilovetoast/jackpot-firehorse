<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Command;

/**
 * Clear caches that could cause stale pipeline state.
 * Run after deployment or when troubleshooting Brand Guidelines PDF pipeline issues.
 *
 * Usage (from Laravel root):
 *   sail artisan pipeline:clear-caches
 *   php artisan pipeline:clear-caches
 */
class PipelineClearCachesCommand extends Command
{
    protected $signature = 'pipeline:clear-caches';

    protected $description = 'Clear caches that could cause stale Brand Guidelines pipeline state';

    public function handle(): int
    {
        $this->info('Clearing caches...');

        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        Artisan::call('queue:restart');

        $this->info('Caches cleared. If using Horizon, run: sail artisan horizon:terminate');

        return Command::SUCCESS;
    }
}
