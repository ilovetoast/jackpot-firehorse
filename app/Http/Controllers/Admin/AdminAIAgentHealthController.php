<?php

namespace App\Http\Controllers\Admin;

use App\Models\AIAgentRun;
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

        $lastRunPerAgent = AIAgentRun::select('agent_id')
            ->selectRaw('MAX(agent_name) as agent_name')
            ->selectRaw('MAX(started_at) as last_run_at')
            ->selectRaw('MAX(CASE WHEN status = "success" THEN started_at END) as last_success_at')
            ->selectRaw('MAX(CASE WHEN status = "failed" THEN started_at END) as last_failed_at')
            ->groupBy('agent_id')
            ->orderByDesc('last_run_at')
            ->get()
            ->map(function ($r) {
                $lastRun = AIAgentRun::where('agent_id', $r->agent_id)
                    ->orderByDesc('started_at')
                    ->first();
                return [
                    'agent_id' => $r->agent_id,
                    'agent_name' => $r->agent_name ?? $r->agent_id,
                    'last_run_at' => $r->last_run_at ? Carbon::parse($r->last_run_at)->toIso8601String() : null,
                    'last_success_at' => $r->last_success_at ? Carbon::parse($r->last_success_at)->toIso8601String() : null,
                    'last_failed_at' => $r->last_failed_at ? Carbon::parse($r->last_failed_at)->toIso8601String() : null,
                    'last_status' => $lastRun?->status,
                    'last_severity' => $lastRun?->severity,
                    'last_summary' => $lastRun?->summary,
                ];
            })
            ->toArray();

        $failuresLast24h = AIAgentRun::where('status', 'failed')
            ->where('started_at', '>=', now()->subDay())
            ->orderByDesc('started_at')
            ->limit(50)
            ->get(['id', 'agent_id', 'agent_name', 'task_type', 'entity_type', 'entity_id', 'status', 'severity', 'summary', 'error_message', 'started_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'agent_id' => $r->agent_id,
                'agent_name' => $r->agent_name ?? $r->agent_id,
                'task_type' => $r->task_type,
                'entity_type' => $r->entity_type,
                'entity_id' => $r->entity_id,
                'status' => $r->status,
                'severity' => $r->severity,
                'summary' => $r->summary,
                'error_message' => $r->error_message ? substr($r->error_message, 0, 200) : null,
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
