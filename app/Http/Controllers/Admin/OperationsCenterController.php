<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationErrorEvent;
use App\Models\SystemIncident;
use App\Models\Tenant;
use App\Services\Reliability\ReliabilityMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unified Operations Center.
 *
 * Data from system_incidents, failed_jobs, and application_error_events. No log scraping.
 */
class OperationsCenterController extends Controller
{
    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $tab = (string) $request->get('tab', 'overview');
        $allowedTabs = ['overview', 'queue', 'incidents', 'application-errors', 'reliability', 'failed-jobs'];
        if (! in_array($tab, $allowedTabs, true)) {
            $tab = 'overview';
        }

        $incidents = SystemIncident::whereNull('resolved_at')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'error' THEN 2 WHEN 'warning' THEN 3 ELSE 4 END")
            ->orderBy('detected_at', 'desc')
            ->limit(100)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'source_type' => $i->source_type,
                'source_id' => $i->source_id,
                'tenant_id' => $i->tenant_id,
                'severity' => $i->severity,
                'title' => $i->title,
                'message' => $i->message,
                'retryable' => $i->retryable,
                'requires_support' => $i->requires_support,
                'detected_at' => $i->detected_at?->toIso8601String(),
                'repair_attempts' => $i->metadata['repair_attempts'] ?? $i->metadata['recovery_attempt_count'] ?? 0,
                'last_repair_attempt_at' => $i->metadata['last_repair_attempt_at'] ?? $i->metadata['last_recovery_attempt_at'] ?? null,
            ]);

        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(25)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);

                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'job_name' => $payload['displayName'] ?? class_basename($payload['job'] ?? 'Unknown'),
                    'queue' => $job->queue,
                    // UTC ISO8601 so the browser parses one unambiguous instant (then toLocaleString = viewer local).
                    'failed_at' => $job->failed_at !== null && $job->failed_at !== ''
                        ? Carbon::parse($job->failed_at)->utc()->toIso8601String()
                        : null,
                    'exception' => \Illuminate\Support\Str::limit($job->exception ?? '', 200),
                ];
            });

        $applicationErrors = collect();
        if (Schema::hasTable('application_error_events')) {
            $applicationErrorRows = ApplicationErrorEvent::query()
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
            $tenantIds = $applicationErrorRows->pluck('tenant_id')->filter()->unique()->values()->all();
            $tenantLabels = $tenantIds === []
                ? collect()
                : Tenant::query()->whereIn('id', $tenantIds)->get(['id', 'name', 'slug'])->keyBy('id');
            $applicationErrors = $applicationErrorRows->map(function ($e) use ($tenantLabels) {
                $tid = $e->tenant_id;
                $t = $tid ? $tenantLabels->get($tid) : null;

                return [
                    'id' => $e->id,
                    'category' => $e->category,
                    'code' => $e->code,
                    'source_type' => $e->source_type,
                    'source_id' => $e->source_id,
                    'tenant_id' => $e->tenant_id,
                    'tenant_name' => $t?->name,
                    'tenant_slug' => $t?->slug,
                    'message' => $e->message,
                    'context' => $e->context,
                    'created_at' => $e->created_at?->toIso8601String(),
                ];
            });
        }

        $metricsService = app(ReliabilityMetricsService::class);
        $queueHealth = $this->getQueueHealth();
        $schedulerHealth = $this->getSchedulerHealth();
        $reliabilityMetrics = $metricsService->getAll();

        $horizonAvailable = class_exists(\Laravel\Horizon\Horizon::class);
        $horizonUrl = $horizonAvailable ? url(config('horizon.path', 'horizon')) : null;

        return Inertia::render('Admin/OperationsCenter/Index', [
            'tab' => $tab,
            'incidents' => $incidents,
            'failedJobs' => $failedJobs,
            'applicationErrors' => $applicationErrors,
            'queueHealth' => $queueHealth,
            'schedulerHealth' => $schedulerHealth,
            'reliabilityMetrics' => $reliabilityMetrics,
            'horizonAvailable' => $horizonAvailable,
            'horizonUrl' => $horizonUrl,
        ]);
    }

    /**
     * Remove all rows from the failed_jobs table (same as `php artisan queue:flush`).
     * Does not re-run jobs; use Horizon retry if you need to replay work.
     */
    public function flushFailedJobs(): JsonResponse
    {
        $this->authorizeAdmin();

        $before = (int) DB::table('failed_jobs')->count();
        Artisan::call('queue:flush');

        return response()->json([
            'cleared' => $before,
            'message' => $before === 0
                ? 'No failed job records to remove.'
                : "Removed {$before} failed job record(s).",
        ]);
    }

    protected function getQueueHealth(): array
    {
        try {
            $driver = config('queue.default');
            if ($driver === 'redis') {
                $pending = Queue::size(config('queue.connections.redis.queue', 'default')) + Queue::size('downloads');
                $failed = (int) DB::table('failed_jobs')->count();

                return [
                    'status' => $failed > 10 ? 'unhealthy' : ($failed > 0 ? 'warning' : 'healthy'),
                    'pending_count' => $pending,
                    'failed_count' => $failed,
                ];
            }
            $pending = (int) DB::table('jobs')->count();
            $failed = (int) DB::table('failed_jobs')->count();

            return [
                'status' => $failed > 10 ? 'unhealthy' : ($failed > 0 ? 'warning' : 'healthy'),
                'pending_count' => $pending,
                'failed_count' => $failed,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'unknown', 'pending_count' => 0, 'failed_count' => 0];
        }
    }

    protected function getSchedulerHealth(): array
    {
        try {
            $lastHeartbeat = null;
            try {
                $lastHeartbeat = Cache::store('redis')->get('scheduler:heartbeat');
            } catch (\Throwable $e) {
                // ignore
            }
            if (! $lastHeartbeat) {
                $lastHeartbeat = Cache::get('laravel_scheduler_last_heartbeat');
            }
            if (! $lastHeartbeat) {
                return ['status' => 'not_running', 'last_heartbeat' => null];
            }
            $time = $lastHeartbeat instanceof \Carbon\Carbon ? $lastHeartbeat : \Carbon\Carbon::parse($lastHeartbeat);
            $minutes = $time->diffInMinutes(now());
            $status = $minutes >= 2 ? 'unhealthy' : ($minutes >= 1 ? 'delayed' : 'healthy');

            return [
                'status' => $status,
                'last_heartbeat' => $time->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'unknown', 'last_heartbeat' => null];
        }
    }
}
