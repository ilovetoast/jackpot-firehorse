<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('email:templates:refresh', function () {
    $this->call('db:seed', ['--class' => \Database\Seeders\NotificationTemplateSeeder::class]);
})->purpose('Update notification_templates rows from NotificationTemplateSeeder (transactional HTML / branding)');

// When scheduler runs only on worker (staging/production), set SCHEDULER_ENABLED=false on web to avoid
// running schedule:run there; worker must have SCHEDULER_ENABLED=true or unset (default true).
// When false in staging, no scheduled tasks are registered (heartbeat and all jobs are skipped).
$schedulerEnabled = config('app.env') !== 'staging'
    || filter_var(env('SCHEDULER_ENABLED', true), FILTER_VALIDATE_BOOLEAN);

if ($schedulerEnabled) {

    // Scheduler heartbeat: written by the process that runs schedule:run (worker in staging, same machine in local).
    // Use explicit redis store so web and worker see the same value (CACHE_STORE=redis on both).
    // TTL 120 seconds; SystemStatusController marks unhealthy if null or older than 2 minutes.
    Schedule::call(function () {
        try {
            Cache::store('redis')->put('scheduler:heartbeat', now(), 120);
        } catch (\Throwable $e) {
            Cache::put('laravel_scheduler_last_heartbeat', now()->toIso8601String(), now()->addMinutes(10));
        }
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

    // Asset Processing Watchdog: detect stuck assets (grace in config/reliability.php)
    Schedule::command('assets:watchdog')
        ->everyTenMinutes()
        ->withoutOverlapping()
        ->description('Detect assets stuck in uploading or thumbnail generation');

    // System Auto-Recovery: reconcile, retry, create tickets for unresolved incidents
    Schedule::command('system:auto-recover')
        ->everyTenMinutes()
        ->withoutOverlapping()
        ->description('Auto-recover unresolved system incidents');

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

    // Studio: staged layer-extraction masks/previews (expired review sessions)
    Schedule::command('studio:cleanup-layer-extraction-sessions')
        ->hourly()
        ->withoutOverlapping()
        ->description('Delete expired Studio layer extraction sessions and ephemeral mask files');

    // Pending upload approvals: one digest per approver per brand per day (plan-gated notifications)
    Schedule::command('approvals:send-pending-digests')
        ->dailyAt('08:00')
        ->withoutOverlapping()
        ->description('Batched email to approvers for pending team/creator upload approvals');

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

    // Sentry AI: pull unresolved issues from Sentry API into sentry_issues (no AI analysis)
    Schedule::job(new \App\Jobs\PullSentryIssuesJob)
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->name('pull-sentry-issues')
        ->description('Pull unresolved Sentry issues and upsert into sentry_issues');

    // Phase D5: Expired download cleanup (delete artifacts, verify, record metrics)
    Schedule::job(new \App\Jobs\CleanupExpiredDownloadsJob)
        ->daily()
        ->withoutOverlapping()
        ->name('cleanup-expired-downloads')
        ->description('Delete expired download ZIPs from storage and verify cleanup');

    // Generative editor: purge orphaned AI layer assets (lifecycle=orphaned) after grace period
    Schedule::job(new \App\Jobs\CleanupOrphanedGenerativeAssetsJob)
        ->daily()
        ->withoutOverlapping()
        ->name('cleanup-orphaned-generative-assets')
        ->description('Hard-delete orphaned generative_layer AI assets after configured days');

    // Asset deletion: backup for delayed DeleteAssetJob (catches jobs lost on Redis flush, worker restarts, or deploy)
    // Primary path: soft delete dispatches DeleteAssetJob with 30-day delay. This job runs daily and processes
    // any soft-deleted assets past grace period that weren't handled by delayed jobs (e.g. queue store lost).
    Schedule::job(new \App\Jobs\ProcessExpiredAssetDeletionsJob)
        ->dailyAt('03:00')
        ->withoutOverlapping()
        ->name('process-expired-asset-deletions')
        ->description('Hard-delete soft-deleted assets past grace period (backup for delayed jobs)');

    // Metadata insights: DB-backed value + field suggestion sync; one queued job per tenant (caps + 24h cooldown in job)
    Schedule::call(function () {
        \App\Models\Tenant::query()
            ->where('ai_insights_enabled', true)
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) {
                \App\Jobs\RunMetadataInsightsJob::dispatch((int) $id);
            });
    })->name('metadata-insights:dispatch')
        ->dailyAt('04:25')
        ->withoutOverlapping(120)
        ->description('Queue metadata insights sync jobs for tenants with ai_insights_enabled');

    // Disposable demo workspace cleanup (Phase 4): only registers when enabled; respects demo.cleanup_dry_run.
    if (config('demo.cleanup_enabled')) {
        $demoCleanupCmd = (bool) config('demo.cleanup_dry_run', false)
            ? 'demo:cleanup-expired --dry-run --force'
            : 'demo:cleanup-expired --force';

        Schedule::command($demoCleanupCmd)
            ->dailyAt('04:45')
            ->withoutOverlapping(120)
            ->name('demo:cleanup-expired')
            ->description('Delete expired/archived disposable demo tenants and tenants/{uuid}/ storage');
    }

} // end if ($schedulerEnabled)
