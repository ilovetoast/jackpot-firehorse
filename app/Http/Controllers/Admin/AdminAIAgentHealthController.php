<?php

namespace App\Http\Controllers\Admin;

use App\Models\AIAgentRun;
use App\Models\Tenant;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase A-1: AI Agent Health observability.
 *
 * READ-ONLY. Answers: Did the agent run? What did it conclude? Was escalation recommended?
 * Does NOT log prompts or tokens.
 */
class AdminAIAgentHealthController extends Controller
{
    protected function authorizeAdmin(): void
    {
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        // Latest row per agent_id via MAX(id) (avoids N+1 timeouts on large ai_agent_runs tables).
        $latestRunIdsSub = AIAgentRun::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('agent_id');

        $aggByAgent = AIAgentRun::query()
            ->select('agent_id')
            ->selectRaw('MAX(CASE WHEN status = "success" THEN started_at END) as last_success_at')
            ->selectRaw('MAX(CASE WHEN status = "failed" THEN started_at END) as last_failed_at')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $lastRunPerAgent = AIAgentRun::query()
            ->joinSub($latestRunIdsSub, 'latest_agent_runs', 'ai_agent_runs.id', '=', 'latest_agent_runs.id')
            ->orderByDesc('ai_agent_runs.started_at')
            ->get([
                'ai_agent_runs.agent_id',
                'ai_agent_runs.agent_name',
                'ai_agent_runs.started_at',
                'ai_agent_runs.status',
                'ai_agent_runs.severity',
                'ai_agent_runs.summary',
                'ai_agent_runs.error_message',
            ])
            ->map(function ($lastRun) use ($aggByAgent) {
                $agg = $aggByAgent->get($lastRun->agent_id);

                return [
                    'agent_id' => $lastRun->agent_id,
                    'agent_name' => $lastRun->agent_name ?? $lastRun->agent_id,
                    'last_run_at' => $lastRun->started_at?->toIso8601String(),
                    'last_success_at' => $agg?->last_success_at ? Carbon::parse($agg->last_success_at)->toIso8601String() : null,
                    'last_failed_at' => $agg?->last_failed_at ? Carbon::parse($agg->last_failed_at)->toIso8601String() : null,
                    'last_status' => $lastRun->status,
                    'last_severity' => $lastRun->severity,
                    'last_summary' => $lastRun->summary,
                    'last_error_message' => $lastRun->error_message,
                ];
            })
            ->toArray();

        $failuresQuery = AIAgentRun::where('status', 'failed')
            ->where('started_at', '>=', now()->subDay())
            ->orderByDesc('started_at')
            ->limit(50)
            ->get(['id', 'agent_id', 'agent_name', 'task_type', 'entity_type', 'entity_id', 'tenant_id', 'status', 'severity', 'summary', 'error_message', 'started_at']);

        $tenantNames = Tenant::whereIn('id', $failuresQuery->pluck('tenant_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $failuresLast24h = $failuresQuery
            ->map(fn ($r) => [
                'id' => $r->id,
                'agent_id' => $r->agent_id,
                'agent_name' => $r->agent_name ?? $r->agent_id,
                'task_type' => $r->task_type,
                'entity_type' => $r->entity_type,
                'entity_id' => $r->entity_id,
                'tenant_id' => $r->tenant_id,
                'tenant_name' => $r->tenant_id ? ($tenantNames[$r->tenant_id] ?? null) : null,
                'status' => $r->status,
                'severity' => $r->severity,
                'summary' => $r->summary,
                'error_message' => $r->error_message,
                'started_at' => $r->started_at?->toIso8601String(),
            ])
            ->toArray();

        $bySeverity = AIAgentRun::whereNotNull('severity')
            ->select('severity', DB::raw('count(*) as count'))
            ->groupBy('severity')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['severity' => $r->severity, 'count' => $r->count])
            ->toArray();

        $stats = [
            'total_runs_24h' => AIAgentRun::where('started_at', '>=', now()->subDay())->count(),
            'failures_24h' => AIAgentRun::where('status', 'failed')->where('started_at', '>=', now()->subDay())->count(),
            'success_24h' => AIAgentRun::where('status', 'success')->where('started_at', '>=', now()->subDay())->count(),
        ];

        return Inertia::render('Admin/AIAgentHealth/Index', [
            'lastRunPerAgent' => $lastRunPerAgent,
            'failuresLast24h' => $failuresLast24h,
            'bySeverity' => $bySeverity,
            'stats' => $stats,
        ]);
    }
}
