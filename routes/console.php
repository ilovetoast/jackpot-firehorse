<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// When scheduler runs only on worker (staging/production), set SCHEDULER_ENABLED=false on web to avoid
// running schedule:run there; worker must have SCHEDULER_ENABLED=true or unset (default true).
// When false in staging, no scheduled tasks are registered (heartbeat and all jobs are skipped).
$schedulerEnabled = config('app.env') !== 'staging'
    || filter_var(env('SCHEDULER_ENABLED', true), FILTER_VALIDATE_BOOLEAN);

if ($schedulerEnabled) {

// Scheduler heartbeat: written by the process that runs schedule:run (worker in staging, same machine in local).
// Stored in the default cache store (Cache::put). For web and worker to see the same value, use shared storage:
// - CACHE_STORE=redis or CACHE_STORE=database (same DB/Redis as web). Do NOT use "file" or "array" when
// scheduler runs on a different host than the web server; otherwise the web cannot see the heartbeat.
Schedule::call(function () {
    Cache::put('laravel_scheduler_last_heartbeat', now()->toIso8601String(), now()->addMinutes(10));
})->everyMinute()
    ->name('scheduler:heartbeat')
    ->withoutOverlapping()
    ->description('Record scheduler heartbeat for health monitoring');

// TODO: Uncomment when PruneAILogs deletion logic is implemented
// Schedule::command('ai:prune-logs --force')
//     ->daily()
//     ->at('02:00')
//     ->description('Delete old AI agent run records based on retention policy');

// Automation scheduled commands
Schedule::command('automation:scan-sla-risks')
    ->hourly()
    ->withoutOverlapping()
    ->description('Scan tickets for SLA breach risk');

Schedule::command('automation:scan-error-patterns')
    ->hourly()
    ->withoutOverlapping()
    ->description('Scan error logs for patterns and suggest internal tickets');

// AI Budget reset (runs on 1st of each month at midnight)
Schedule::command('ai:reset-monthly-budgets')
    ->monthlyOn(1, '00:00')
    ->withoutOverlapping()
    ->description('Reset monthly AI budget usage records for the new month');

// Abandoned upload session detection (runs every 15 minutes)
Schedule::command('uploads:detect-abandoned')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->description('Detect and mark abandoned upload sessions as failed');

// Upload cleanup (runs every 3 hours - configurable via env or command)
// Cleans up expired/terminal upload sessions and orphaned multipart uploads
Schedule::command('uploads:cleanup-expired')
    ->everyThreeHours()
    ->withoutOverlapping()
    ->description('Cleanup expired upload sessions and orphaned multipart uploads');

// Billing expiration checks (runs daily at 2:00 AM)
// Checks for expiring trial/comped accounts and processes expiration
Schedule::command('billing:check-expiring --days=7')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->description('Check for accounts expiring soon and send warnings');

// Process expired billing statuses (runs daily at 2:00 AM)
// Processes accounts with expired billing_status (trial/comped) and downgrades to free
Schedule::command('billing:process-expired')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->description('Process expired trial and comped accounts, downgrading to free plan');

// Aggregate asset metrics (runs daily at 2:00 AM)
// Aggregates previous day's metrics into daily/weekly/monthly aggregates for performance
Schedule::job(\App\Jobs\AggregateMetricsJob::class)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('aggregate-metrics')
    ->description('Aggregate asset metrics into periodic aggregates');

// Phase D5: Expired download cleanup (delete artifacts, verify, record metrics)
Schedule::job(new \App\Jobs\CleanupExpiredDownloadsJob())
    ->daily()
    ->withoutOverlapping()
    ->name('cleanup-expired-downloads')
    ->description('Delete expired download ZIPs from storage and verify cleanup');

// Asset deletion: backup for delayed DeleteAssetJob (catches jobs lost on Redis flush, worker restarts, or deploy)
// Primary path: soft delete dispatches DeleteAssetJob with 30-day delay. This job runs daily and processes
// any soft-deleted assets past grace period that weren't handled by delayed jobs (e.g. queue store lost).
Schedule::job(new \App\Jobs\ProcessExpiredAssetDeletionsJob())
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->name('process-expired-asset-deletions')
    ->description('Hard-delete soft-deleted assets past grace period (backup for delayed jobs)');

} // end if ($schedulerEnabled)
