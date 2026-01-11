<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduler heartbeat (records that scheduler is running)
// This is used by System Status page to detect if scheduler is healthy
Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put('laravel_scheduler_last_heartbeat', now()->toIso8601String(), now()->addMinutes(10));
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
