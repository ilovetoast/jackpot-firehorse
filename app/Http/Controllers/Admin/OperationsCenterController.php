<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\SystemIncident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unified Operations Center.
 *
 * All data from system_incidents OR failed_jobs. No log scraping.
 */
class OperationsCenterController extends Controller
{
    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        if (!$isSiteOwner && !$isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $tab = $request->get('tab', 'incidents');

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

        $assetsStalled = $incidents->where('source_type', 'asset')->values();

        $derivativeFailures = SystemIncident::whereNull('resolved_at')
            ->where(function ($q) {
                $q->where('source_type', 'derivative')
                    ->orWhere('metadata->derivative_failure', true);
            })
            ->orderBy('detected_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'source_id' => $i->source_id,
                'title' => $i->title,
                'severity' => $i->severity,
                'detected_at' => $i->detected_at?->toIso8601String(),
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
                    'failed_at' => $job->failed_at,
                    'exception' => \Illuminate\Support\Str::limit($job->exception ?? '', 200),
                ];
            });

        $queueHealth = $this->getQueueHealth();
        $schedulerHealth = $this->getSchedulerHealth();
        $visualMetadataIntegrity = $this->getVisualMetadataIntegrity();
        $mttrMetric = $this->getMTTRMetric();

        $horizonAvailable = class_exists(\Laravel\Horizon\Horizon::class);
        $horizonUrl = $horizonAvailable ? url(config('horizon.path', 'horizon')) : null;

        return Inertia::render('Admin/OperationsCenter/Index', [
            'tab' => $tab,
            'incidents' => $incidents,
            'assetsStalled' => $assetsStalled,
            'derivativeFailures' => $derivativeFailures,
            'failedJobs' => $failedJobs,
            'queueHealth' => $queueHealth,
            'schedulerHealth' => $schedulerHealth,
            'visualMetadataIntegrity' => $visualMetadataIntegrity,
            'mttrMetric' => $mttrMetric,
            'horizonAvailable' => $horizonAvailable,
            'horizonUrl' => $horizonUrl,
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

    /**
     * Visual Metadata Integrity Rate — SLO for media reliability.
     * State-derived: % of eligible assets where visualMetadataReady.
     * Incidents are diagnostic (visibility), not source of truth.
     */
    protected function getVisualMetadataIntegrity(): array
    {
        try {
            $eligible = Asset::whereSupportsThumbnailMetadata()->count();
            $invalid = Asset::whereSupportsThumbnailMetadata()->whereVisualMetadataInvalid()->count();
            $valid = max(0, $eligible - $invalid);
            $ratePercent = $eligible > 0 ? round(100 * $valid / $eligible, 1) : 100;

            $incidentsCount = SystemIncident::whereNull('resolved_at')
                ->where('title', 'Expected visual metadata missing')
                ->count();

            $sloTarget = 95;
            $status = $ratePercent >= $sloTarget ? 'healthy' : ($ratePercent >= 80 ? 'warning' : 'critical');

            return [
                'status' => $status,
                'rate_percent' => $ratePercent,
                'eligible' => $eligible,
                'invalid' => $invalid,
                'valid' => $valid,
                'incidents_count' => $incidentsCount,
                'slo_target_percent' => $sloTarget,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unknown',
                'rate_percent' => 0,
                'eligible' => 0,
                'invalid' => 0,
                'valid' => 0,
                'incidents_count' => 0,
                'slo_target_percent' => 95,
            ];
        }
    }

    /**
     * Mean Time To Repair (MTTR) — average resolution time for incidents in last 24h.
     * Enterprise telemetry: detected_at → resolved_at.
     */
    protected function getMTTRMetric(): array
    {
        try {
            $since = now()->subHours(24);
            $result = SystemIncident::whereNotNull('resolved_at')
                ->where('resolved_at', '>=', $since)
                ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, detected_at, resolved_at)) as mttr_minutes_avg')
                ->selectRaw('COUNT(*) as resolved_count')
                ->first();

            $mttrMinutes = $result && $result->mttr_minutes_avg !== null
                ? (float) $result->mttr_minutes_avg
                : null;

            return [
                'mttr_minutes_avg' => $mttrMinutes,
                'resolved_count_24h' => (int) ($result->resolved_count ?? 0),
                'window_hours' => 24,
            ];
        } catch (\Throwable $e) {
            return [
                'mttr_minutes_avg' => null,
                'resolved_count_24h' => 0,
                'window_hours' => 24,
            ];
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
            if (!$lastHeartbeat) {
                $lastHeartbeat = Cache::get('laravel_scheduler_last_heartbeat');
            }
            if (!$lastHeartbeat) {
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
