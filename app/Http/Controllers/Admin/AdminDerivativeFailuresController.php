<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AITaskType;
use App\Models\AIAgentRun;
use App\Models\AssetDerivativeFailure;
use App\Models\Ticket;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase T-1: Admin visibility for derivative generation failures.
 *
 * READ-ONLY. Grouped views by processor, derivative_type, codec.
 */
class AdminDerivativeFailuresController extends Controller
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

        $groupBy = $request->get('group_by', 'processor'); // processor | derivative_type | codec
        $query = AssetDerivativeFailure::with(['asset:id,tenant_id,mime_type,storage_root_path'])
            ->whereNotNull('last_failed_at')
            ->orderBy('last_failed_at', 'desc');

        if ($request->filled('processor')) {
            $query->where('processor', $request->processor);
        }
        if ($request->filled('derivative_type')) {
            $query->where('derivative_type', $request->derivative_type);
        }
        if ($request->filled('codec')) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.codec')) = ?", [$request->codec]);
        }
        if ($request->filled('escalated')) {
            if ($request->escalated === 'yes') {
                $query->where(function ($q) {
                    $q->whereNotNull('escalation_ticket_id')->orWhere('failure_count', '>=', 3);
                });
            } else {
                $query->whereNull('escalation_ticket_id')
                    ->where(function ($q) {
                        $q->where('failure_count', '<', 3)->orWhereNull('failure_count');
                    });
            }
        }

        $failures = $query->paginate(25)->withQueryString();

        $failureIds = $failures->pluck('id')->toArray();
        $agentRunsByFailure = $this->getAgentRunsForFailures($failureIds);

        $failures->getCollection()->transform(function ($f) use ($agentRunsByFailure) {
            $aiRun = $agentRunsByFailure[$f->id] ?? null;
            $codec = $f->metadata['codec'] ?? null;

            return [
                'id' => $f->id,
                'asset_id' => $f->asset_id,
                'tenant_id' => $f->asset?->tenant_id,
                'derivative_type' => $f->derivative_type,
                'processor' => $f->processor,
                'failure_reason' => $f->failure_reason,
                'failure_count' => $f->failure_count ?? 0,
                'codec' => $codec,
                'mime' => $f->metadata['mime'] ?? null,
                'ai_severity' => $aiRun ? $this->parseSeverityFromResponse($aiRun) : null,
                'ai_summary' => $aiRun ? $this->parseSummaryFromResponse($aiRun) : null,
                'escalated' => ($f->failure_count ?? 0) >= 3 || $f->escalation_ticket_id !== null,
                'escalation_ticket_id' => $f->escalation_ticket_id,
                'last_failed_at' => $f->last_failed_at?->toIso8601String(),
            ];
        });

        $stats = [
            'total' => AssetDerivativeFailure::count(),
            'escalated' => AssetDerivativeFailure::where(function ($q) {
                $q->whereNotNull('escalation_ticket_id')->orWhere('failure_count', '>=', 3);
            })->count(),
        ];

        $groupedByProcessor = AssetDerivativeFailure::select('processor', DB::raw('count(*) as count'))
            ->groupBy('processor')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['processor' => $r->processor, 'count' => $r->count])
            ->toArray();

        $groupedByDerivativeType = AssetDerivativeFailure::select('derivative_type', DB::raw('count(*) as count'))
            ->groupBy('derivative_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['derivative_type' => $r->derivative_type, 'count' => $r->count])
            ->toArray();

        $groupedByCodec = AssetDerivativeFailure::selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.codec')), 'unknown') as codec, count(*) as cnt")
            ->groupByRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.codec')), 'unknown')")
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => ['codec' => $r->codec ?? 'unknown', 'count' => $r->cnt])
            ->toArray();

        return Inertia::render('Admin/DerivativeFailures/Index', [
            'failures' => $failures,
            'stats' => $stats,
            'groupedByProcessor' => $groupedByProcessor,
            'groupedByDerivativeType' => $groupedByDerivativeType,
            'groupedByCodec' => $groupedByCodec,
            'filters' => $request->only(['group_by', 'processor', 'derivative_type', 'codec', 'escalated']),
        ]);
    }

    public function show(int $failure): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();

        $record = AssetDerivativeFailure::with(['asset.tenant:id,name,slug'])->findOrFail($failure);

        $aiRun = AIAgentRun::where('agent_id', 'asset_derivative_failure_analyzer')
            ->where('task_type', AITaskType::ASSET_DERIVATIVE_FAILURE_ANALYSIS)
            ->where('status', 'success')
            ->whereNotNull('metadata')
            ->get()
            ->first(function ($r) use ($record) {
                $opts = $r->metadata['options'] ?? $r->metadata ?? [];
                return ($opts['derivative_failure_id'] ?? null) == $record->id;
            });

        $ticket = null;
        if ($record->escalation_ticket_id) {
            $ticket = Ticket::find($record->escalation_ticket_id);
        }

        return response()->json([
            'id' => $record->id,
            'asset_id' => $record->asset_id,
            'tenant' => $record->asset?->tenant ? [
                'id' => $record->asset->tenant->id,
                'name' => $record->asset->tenant->name,
                'slug' => $record->asset->tenant->slug,
            ] : null,
            'derivative_type' => $record->derivative_type,
            'processor' => $record->processor,
            'failure_reason' => $record->failure_reason,
            'failure_count' => $record->failure_count ?? 0,
            'codec' => $record->metadata['codec'] ?? null,
            'mime' => $record->metadata['mime'] ?? null,
            'failure_trace' => $record->metadata['exception_trace'] ?? null,
            'ai_summary' => $aiRun ? $this->parseSummaryFromResponse($aiRun) : null,
            'ai_severity' => $aiRun ? $this->parseSeverityFromResponse($aiRun) : null,
            'escalated' => ($record->failure_count ?? 0) >= 3 || $record->escalation_ticket_id !== null,
            'ticket' => $ticket ? [
                'id' => $ticket->id,
                'subject' => $ticket->metadata['subject'] ?? ('Ticket #' . $ticket->id),
                'status' => $ticket->status?->value ?? $ticket->status,
                'url' => route('admin.support.tickets.show', $ticket),
            ] : null,
            'last_failed_at' => $record->last_failed_at?->toIso8601String(),
        ]);
    }

    protected function getAgentRunsForFailures(array $failureIds): array
    {
        if (empty($failureIds)) {
            return [];
        }

        $runs = AIAgentRun::where('agent_id', 'asset_derivative_failure_analyzer')
            ->where('task_type', AITaskType::ASSET_DERIVATIVE_FAILURE_ANALYSIS)
            ->where('status', 'success')
            ->whereNotNull('metadata')
            ->orderBy('started_at', 'desc')
            ->get();

        $byFailure = [];
        foreach ($runs as $run) {
            $opts = $run->metadata['options'] ?? $run->metadata ?? [];
            $id = $opts['derivative_failure_id'] ?? null;
            if ($id && in_array($id, $failureIds, true) && ! isset($byFailure[$id])) {
                $byFailure[$id] = $run;
            }
        }

        return $byFailure;
    }

    protected function parseSeverityFromResponse($run): ?string
    {
        $text = $run->metadata['response'] ?? $run->metadata['prompt'] ?? null;
        if (! $text || stripos($text, '"severity"') === false) {
            return null;
        }
        if (preg_match('/"severity"\s*:\s*"([^"]+)"/', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    protected function parseSummaryFromResponse($run): ?string
    {
        $text = $run->metadata['response'] ?? $run->metadata['prompt'] ?? null;
        if (! $text || stripos($text, '"summary"') === false) {
            return null;
        }
        if (preg_match('/"summary"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $m)) {
            return trim(stripslashes($m[1]));
        }

        return null;
    }
}
