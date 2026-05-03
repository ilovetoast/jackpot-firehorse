<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DeployedAtManifest;
use App\Models\AssetDerivativeFailure;
use App\Models\AIAgentRun;
use App\Models\Brand;
use App\Models\Download;
use App\Models\SystemIncident;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\UploadSession;
use App\Models\User;
use App\Enums\TicketStatus;
use App\Services\Admin\StudioCompositionVideoExportAdminMetrics;
use App\Enums\TicketTeam;
use App\Enums\TicketType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;

/**
 * Admin Command Center - Executive Overview.
 *
 * Single aggregated endpoint for dashboard metrics.
 */
class AdminOverviewController extends Controller
{
    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        if (!$isSiteOwner && !$isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }
    }

    /**
     * Admin Overview (Command Center Dashboard).
     */
    public function index(Request $request): Response
    {
        Log::info('[AdminOverview] Index request');
        $this->authorizeAdmin();

        try {
            $metrics = $this->getOverviewMetrics();
        } catch (\Throwable $e) {
            Log::error('[AdminOverview] getOverviewMetrics failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            report($e);
            $metrics = $this->getOverviewMetricsFallback();
        }

        Log::info('[AdminOverview] Index response');
        return Inertia::render('Admin/Dashboard', [
            'metrics' => $metrics,
        ]);
    }

    /**
     * API: Overview metrics (for polling or initial load).
     */
    public function metrics(): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json($this->getOverviewMetrics());
    }

    protected function getOverviewMetrics(): array
    {
        $cached = cache()->remember('admin_overview_metrics', 60, function () {
            return $this->buildCachedOverviewMetrics();
        });

        return array_merge($cached, [
            'release' => $this->buildReleasePayload(),
            'horizon_workers' => $this->getHorizonWorkerCount(),
        ]);
    }

    /**
     * Heavy Command Center metrics cached 60s. Excludes {@see buildReleasePayload()} and
     * {@see getHorizonWorkerCount()} so deploy/commit/env lines are not stuck behind this TTL.
     */
    protected function buildCachedOverviewMetrics(): array
    {
        $incidents = $this->getIncidentMetrics();
        $queue = $this->getQueueHealth();
        $scheduler = $this->getSchedulerHealth();
        $autoRecovery = $this->getAutoRecoveryMetrics();
        $support = $this->getSupportMetrics();
        $ai = $this->getAIMetrics();
        $failures = $this->getFailureMetrics();
        $org = $this->getOrganizationMetrics();
        $healthScore = $this->computeHealthScore($incidents, $queue, $scheduler, $failures);

        return [
            'incidents' => $incidents,
            'queue' => $queue,
            'scheduler' => $scheduler,
            'auto_recovery' => $autoRecovery,
            'support' => $support,
            'ai' => $ai,
            'failures' => $failures,
            'organization' => $org,
            'health_score' => $healthScore,
        ];
    }

    /**
     * Fallback when getOverviewMetrics throws (e.g. Redis/DB timeout). Prevents 502/503.
     */
    protected function getOverviewMetricsFallback(): array
    {
        return array_merge([
            'incidents' => ['critical' => 0, 'error' => 0, 'warning' => 0, 'total_unresolved' => 0],
            'queue' => ['status' => 'unknown', 'pending_count' => null, 'failed_count' => null],
            'scheduler' => ['status' => 'unknown', 'last_heartbeat' => null, 'heartbeat_age_minutes' => null],
            'auto_recovery' => [],
            'support' => ['open_tickets' => 0, 'engineering_tickets' => 0, 'total_tickets' => 0],
            'ai' => [],
            'failures' => [
                'download_failures_24h' => 0,
                'upload_failures_24h' => 0,
                'derivative_total' => 0,
                'derivative_escalated' => 0,
                'studio_video_export_failures_24h' => 0,
            ],
            'organization' => ['tenants' => 0, 'users' => 0],
            'health_score' => null,
        ], [
            'release' => $this->buildReleasePayload(),
            'horizon_workers' => $this->getHorizonWorkerCount(),
        ]);
    }

    protected function getIncidentMetrics(): array
    {
        $unresolved = SystemIncident::whereNull('resolved_at')->get();
        $critical = $unresolved->where('severity', 'critical')->count();
        $error = $unresolved->where('severity', 'error')->count();
        $warning = $unresolved->where('severity', 'warning')->count();

        return [
            'critical' => $critical,
            'error' => $error,
            'warning' => $warning,
            'total_unresolved' => $unresolved->count(),
        ];
    }

    protected function getQueueHealth(): array
    {
        try {
            $driver = config('queue.default');
            if ($driver === 'redis') {
                $pending = Queue::size(config('queue.connections.redis.queue', 'default')) + Queue::size('downloads');
            } else {
                $pending = (int) DB::table('jobs')->count();
            }
            $failed = (int) DB::table('failed_jobs')->count();
            $status = $failed > 10 ? 'unhealthy' : ($failed > 0 ? 'warning' : 'healthy');
            return [
                'status' => $status,
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
            if (!$lastHeartbeat) {
                $lastHeartbeat = Cache::get('laravel_scheduler_last_heartbeat');
            }
            if (!$lastHeartbeat) {
                return ['status' => 'not_running', 'last_heartbeat' => null, 'heartbeat_age_minutes' => null];
            }
            $time = $lastHeartbeat instanceof \Carbon\Carbon ? $lastHeartbeat : \Carbon\Carbon::parse($lastHeartbeat);
            $minutes = (int) $time->diffInMinutes(now());
            $status = $minutes >= 2 ? 'unhealthy' : ($minutes >= 1 ? 'delayed' : 'healthy');
            return [
                'status' => $status,
                'last_heartbeat' => $time->toIso8601String(),
                'heartbeat_age_minutes' => $minutes,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'unknown', 'last_heartbeat' => null, 'heartbeat_age_minutes' => null];
        }
    }

    protected function getAutoRecoveryMetrics(): array
    {
        $since = now()->subDay();
        $resolved = SystemIncident::whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $since)
            ->get();
        $autoResolved = $resolved->where('auto_resolved', true)->count();
        $withMetadata = $resolved->filter(fn ($i) => ($i->metadata['auto_recovered'] ?? false));
        $escalatedToTicket = \App\Models\Ticket::where('metadata->source', 'operations_incident')
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'incidents_resolved_24h' => $resolved->count(),
            'auto_recovered_24h' => max($autoResolved, $withMetadata->count()),
            'escalated_to_ticket_24h' => $escalatedToTicket,
            'success_rate' => $resolved->count() > 0
                ? round(($autoResolved / $resolved->count()) * 100, 1)
                : 100,
        ];
    }

    protected function getSupportMetrics(): array
    {
        $activeStatuses = [
            TicketStatus::OPEN,
            TicketStatus::WAITING_ON_SUPPORT,
            TicketStatus::IN_PROGRESS,
        ];
        // Support queue only: tenant-facing + tenant-internal (excludes internal/engineering queue).
        $supportOpen = Ticket::whereIn('type', [TicketType::TENANT, TicketType::TENANT_INTERNAL])
            ->whereIn('status', $activeStatuses)
            ->count();
        $engineering = Ticket::where('type', TicketType::INTERNAL)
            ->where('assigned_team', TicketTeam::ENGINEERING)
            ->whereIn('status', $activeStatuses)
            ->count();

        return [
            'open_tickets' => $supportOpen,
            'engineering_tickets' => $engineering,
            'total_tickets' => Ticket::count(),
        ];
    }

    protected function getAIMetrics(): array
    {
        $since = now()->subDay();
        $failures = AIAgentRun::where('status', 'failed')
            ->where('started_at', '>=', $since)
            ->count();
        $runs = AIAgentRun::where('started_at', '>=', $since)->count();
        $withCost = AIAgentRun::where('started_at', '>=', $since)->whereNotNull('estimated_cost')->sum('estimated_cost');

        return [
            'failures_24h' => $failures,
            'runs_24h' => $runs,
            'cost_24h_usd' => round($withCost, 4),
        ];
    }

    protected function getFailureMetrics(): array
    {
        $since = now()->subDay();
        $downloadFailures = Download::withTrashed()
            ->whereNotNull('last_failed_at')
            ->where('last_failed_at', '>=', $since)
            ->count();
        $uploadFailures = UploadSession::withTrashed()
            ->whereNotNull('last_failed_at')
            ->where('last_failed_at', '>=', $since)
            ->count();
        $derivativeTotal = AssetDerivativeFailure::count();
        $derivativeEscalated = AssetDerivativeFailure::where(function ($q) {
            $q->whereNotNull('escalation_ticket_id')->orWhere('failure_count', '>=', 3);
        })->count();

        $studioVideoExportFailures24h = StudioCompositionVideoExportAdminMetrics::failureCountLast24Hours();

        return [
            'download_failures_24h' => $downloadFailures,
            'upload_failures_24h' => $uploadFailures,
            'derivative_total' => $derivativeTotal,
            'derivative_escalated' => $derivativeEscalated,
            'studio_video_export_failures_24h' => $studioVideoExportFailures24h,
        ];
    }

    protected function getOrganizationMetrics(): array
    {
        return [
            'total_tenants' => Tenant::count(),
            'total_users' => User::count(),
            'total_brands' => Brand::count(),
            'active_subscriptions' => Subscription::where('stripe_status', 'active')->count(),
        ];
    }

    protected function computeHealthScore(array $incidents, array $queue, array $scheduler, array $failures): array
    {
        $score = 100;
        $deductions = [];

        if (($incidents['critical'] ?? 0) > 0) {
            $d = min(30, $incidents['critical'] * 10);
            $score -= $d;
            $deductions[] = "{$incidents['critical']} critical incident(s)";
        }
        if (($incidents['error'] ?? 0) > 0) {
            $d = min(15, $incidents['error'] * 3);
            $score -= $d;
            $deductions[] = "{$incidents['error']} error(s)";
        }
        if (($queue['status'] ?? '') === 'unhealthy') {
            $score -= 20;
            $deductions[] = 'Queue unhealthy';
        } elseif (($queue['status'] ?? '') === 'warning') {
            $score -= 5;
            $deductions[] = 'Queue warning';
        }
        if (($scheduler['status'] ?? '') === 'unhealthy' || ($scheduler['status'] ?? '') === 'not_running') {
            $score -= 15;
            $deductions[] = 'Scheduler down';
        } elseif (($scheduler['status'] ?? '') === 'delayed') {
            $score -= 5;
            $deductions[] = 'Scheduler delayed';
        }
        if (($queue['failed_count'] ?? 0) > 5) {
            $score -= min(10, ($queue['failed_count'] - 5));
            $deductions[] = "{$queue['failed_count']} failed jobs";
        }

        $score = max(0, min(100, $score));
        $status = $score >= 90 ? 'healthy' : ($score >= 70 ? 'stable' : ($score >= 50 ? 'degraded' : 'critical'));

        return [
            'score' => $score,
            'status' => $status,
            'deductions' => $deductions,
        ];
    }

    protected function getLastDeployTimestamp(): ?string
    {
        $path = base_path('.deploy_timestamp');
        if (file_exists($path)) {
            return trim((string) file_get_contents($path));
        }

        return null;
    }

    /**
     * Version metadata for the Command Center (env, optional .release-info.json, .deploy_timestamp).
     *
     * Optional JSON at base_path('.release-info.json'): commit|sha, committed_at|time, message, status_url
     * When those are absent, {@see DeployedAtManifest} (same {@see base_path('DEPLOYED_AT')} as System status) fills commit, message, deploy time, and release id.
     */
    protected function buildReleasePayload(): array
    {
        $fromFile = [];
        $jsonPath = base_path('.release-info.json');
        if (is_file($jsonPath) && is_readable($jsonPath)) {
            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            if (is_array($decoded)) {
                $fromFile = [
                    'commit' => $decoded['commit'] ?? $decoded['sha'] ?? null,
                    'committed_at' => $decoded['committed_at'] ?? $decoded['time'] ?? null,
                    'message' => $decoded['message'] ?? null,
                    'status_url' => $decoded['status_url'] ?? null,
                ];
            }
        }

        $commit = $fromFile['commit'] ?? env('APP_BUILD_COMMIT');
        $committedAt = $fromFile['committed_at'] ?? env('APP_BUILD_TIME');
        $message = $fromFile['message'] ?? env('APP_BUILD_MESSAGE');
        $statusUrl = $fromFile['status_url'] ?? env('APP_STATUS_PAGE_URL');

        if (is_string($message) && $message !== '') {
            $message = Str::limit(trim($message), 160);
        } else {
            $message = null;
        }

        $commit = is_string($commit) && $commit !== '' ? trim($commit) : null;
        $committedAt = is_string($committedAt) && $committedAt !== '' ? trim($committedAt) : null;
        $statusUrl = is_string($statusUrl) && $statusUrl !== '' ? trim($statusUrl) : null;
        $deployedAt = $this->getLastDeployTimestamp();
        $releaseId = null;

        // Same DEPLOYED_AT file as System status (web-mirror-deploy.sh) — fills gaps when .release-info.json / APP_BUILD_* are absent.
        $manifest = DeployedAtManifest::read();
        if (is_array($manifest)) {
            if ($commit === null && isset($manifest['Commit']) && trim((string) $manifest['Commit']) !== '') {
                $commit = trim((string) $manifest['Commit']);
            }
            if ($message === null && isset($manifest['Message']) && trim((string) $manifest['Message']) !== '') {
                $message = Str::limit(trim((string) $manifest['Message']), 160);
            }
            foreach (['Release', 'Release dir'] as $releaseKey) {
                if (isset($manifest[$releaseKey]) && trim((string) $manifest[$releaseKey]) !== '') {
                    $releaseId = trim((string) $manifest[$releaseKey]);
                    break;
                }
            }
            if (($deployedAt === null || $deployedAt === '') && isset($manifest['Deployed at'])) {
                $fromManifest = trim((string) $manifest['Deployed at']);
                if ($fromManifest !== '') {
                    $deployedAt = $fromManifest;
                }
            }
        }

        return [
            'commit' => $commit,
            'committed_at' => $committedAt,
            'message' => $message,
            'status_url' => $statusUrl,
            'deployed_at' => is_string($deployedAt) && $deployedAt !== '' ? $deployedAt : null,
            'release_id' => $releaseId,
        ];
    }

    protected function getHorizonWorkerCount(): ?int
    {
        // Horizon worker count requires Redis inspection; optional metric
        return null;
    }
}
