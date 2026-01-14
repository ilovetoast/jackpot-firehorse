<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queue Health Check Command
 * 
 * Dev-only command to detect stuck jobs and missing workers.
 * 
 * Checks:
 * - Jobs older than X minutes in queue
 * - No active workers detected
 * 
 * Usage: php artisan queue:health-check
 */
class QueueHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:health-check 
                            {--stale-minutes=5 : Consider jobs stale after this many minutes}
                            {--warn-only : Only log warnings, do not exit with error}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check queue health: detect stuck jobs and missing workers (dev only)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $staleMinutes = (int) $this->option('stale-minutes');
        $warnOnly = $this->option('warn-only');

        // Count total jobs in queue
        $totalJobs = DB::table('jobs')->count();
        
        if ($totalJobs === 0) {
            $this->info('✓ Queue is healthy: No jobs in queue');
            return Command::SUCCESS;
        }

        // Check for stale jobs (older than threshold)
        $staleThreshold = now()->subMinutes($staleMinutes);
        $staleJobs = DB::table('jobs')
            ->where('created_at', '<', $staleThreshold)
            ->count();

        $this->info("Queue Status:");
        $this->line("  Total jobs: {$totalJobs}");
        $this->line("  Stale jobs (>{$staleMinutes} min): {$staleJobs}");

        if ($staleJobs > 0) {
            $message = "⚠️  WARNING: {$staleJobs} stale job(s) detected in queue. Workers may not be running.";
            
            if ($warnOnly) {
                $this->warn($message);
                Log::warning('[QueueHealthCheck] Stale jobs detected', [
                    'total_jobs' => $totalJobs,
                    'stale_jobs' => $staleJobs,
                    'stale_minutes' => $staleMinutes,
                ]);
            } else {
                $this->error($message);
                $this->line('');
                $this->line('To fix:');
                $this->line('  1. Start queue workers: ./vendor/bin/sail up -d queue');
                $this->line('  2. Or manually process: ./vendor/bin/sail artisan queue:work --once');
                Log::error('[QueueHealthCheck] Stale jobs detected', [
                    'total_jobs' => $totalJobs,
                    'stale_jobs' => $staleJobs,
                    'stale_minutes' => $staleMinutes,
                ]);
                return Command::FAILURE;
            }
        } else {
            $this->info('✓ No stale jobs detected');
        }

        return Command::SUCCESS;
    }
}
