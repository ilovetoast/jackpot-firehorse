<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AITaskType;
use App\Models\AIAgentRun;
use App\Models\Download;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase D-2: Admin visibility for failed download ZIP builds.
 *
 * READ-ONLY. No retry, regenerate, or escalation toggles.
 */
class AdminDownloadFailuresController extends Controller
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

        $query = Download::withTrashed()
            ->with(['tenant:id,name,slug'])
            ->whereNotNull('last_failed_at')
            ->orderBy('last_failed_at', 'desc');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }
        if ($request->filled('failure_reason')) {
            $query->where('failure_reason', $request->failure_reason);
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

        $downloads = $query->paginate(25)->withQueryString();

        $downloadIds = $downloads->pluck('id')->toArray();
        $agentRunsByDownload = $this->getAgentRunsForDownloads($downloadIds);

        $downloads->getCollection()->transform(function ($download) use ($agentRunsByDownload) {
            $aiRun = $agentRunsByDownload[$download->id] ?? null;
            return [
                'id' => $download->id,
                'tenant' => $download->tenant ? [
                    'id' => $download->tenant->id,
                    'name' => $download->tenant->name,
                    'slug' => $download->tenant->slug,
                ] : null,
                'asset_count' => $download->assets()->count(),
                'total_bytes' => $download->download_options['estimated_bytes'] ?? $download->zip_size_bytes ?? null,
                'failure_reason' => $download->failure_reason?->value,
                'failure_count' => $download->failure_count ?? 0,
                'ai_severity' => $aiRun ? $this->parseSeverityFromResponse($aiRun) : null,
                'ai_summary' => $aiRun ? $this->parseSummaryFromResponse($aiRun) : null,
                'ai_recommendation' => $aiRun ? $this->parseRecommendationFromResponse($aiRun) : null,
                'escalated' => ($download->failure_count ?? 0) >= 3 || $download->escalation_ticket_id !== null,
                'escalation_ticket_id' => $download->escalation_ticket_id,
                'created_at' => $download->created_at?->toIso8601String(),
                'last_failed_at' => $download->last_failed_at?->toIso8601String(),
            ];
        });

        $stats = [
            'failed_last_24h' => Download::withTrashed()
                ->whereNotNull('last_failed_at')
                ->where('last_failed_at', '>=', now()->subDay())
                ->count(),
            'escalated' => Download::withTrashed()
                ->whereNotNull('last_failed_at')
                ->where(function ($q) {
                    $q->whereNotNull('escalation_ticket_id')->orWhere('failure_count', '>=', 3);
                })
                ->count(),
            'awaiting_review' => Download::withTrashed()
                ->whereNotNull('last_failed_at')
                ->whereNull('escalation_ticket_id')
                ->where(function ($q) {
                    $q->where('failure_count', '<', 3)->orWhereNull('failure_count');
                })
                ->where('last_failed_at', '>=', now()->subDay())
                ->count(),
        ];

        return Inertia::render('Admin/DownloadFailures/Index', [
            'downloads' => $downloads,
            'stats' => $stats,
            'filters' => $request->only(['tenant_id', 'failure_reason', 'escalated']),
        ]);
    }

    /**
     * Get detail for a single download (for drawer).
     */
    public function show(string $download): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();

        $download = Download::withTrashed()->with(['tenant:id,name,slug'])->findOrFail($download);

        $aiRun = AIAgentRun::where('agent_id', 'download_zip_failure_analyzer')
            ->where('task_type', AITaskType::DOWNLOAD_ZIP_FAILURE_ANALYSIS)
            ->where('tenant_id', $download->tenant_id)
            ->where('status', 'success')
            ->where('started_at', '>=', $download->last_failed_at?->subMinutes(5) ?? now()->subDay())
            ->where('started_at', '<=', $download->last_failed_at?->addMinutes(10) ?? now())
            ->orderBy('started_at', 'desc')
            ->first();

        $ticket = null;
        if ($download->escalation_ticket_id) {
            $ticket = Ticket::find($download->escalation_ticket_id);
        }

        return response()->json([
            'id' => $download->id,
            'tenant' => $download->tenant ? [
                'id' => $download->tenant->id,
                'name' => $download->tenant->name,
                'slug' => $download->tenant->slug,
            ] : null,
            'asset_count' => $download->assets()->count(),
            'total_bytes' => $download->download_options['estimated_bytes'] ?? $download->zip_size_bytes ?? null,
            'failure_reason' => $download->failure_reason?->value,
            'failure_count' => $download->failure_count ?? 0,
            'zip_build_chunk_index' => $download->zip_build_chunk_index ?? 0,
            'zip_total_chunks' => $download->zip_total_chunks,
            'zip_last_progress_at' => $download->zip_last_progress_at?->toIso8601String(),
            'last_progress_seconds_ago' => $download->zip_last_progress_at ? (int) $download->zip_last_progress_at->diffInSeconds(now(), false) : null,
            'failure_trace' => $download->download_options['zip_failure_trace'] ?? null,
            'ai_summary' => $aiRun ? $this->parseSummaryFromResponse($aiRun) : null,
            'ai_recommendation' => $aiRun ? $this->parseRecommendationFromResponse($aiRun) : null,
            'ai_severity' => $aiRun ? $this->parseSeverityFromResponse($aiRun) : null,
            'ai_response_raw' => $aiRun && isset($aiRun->metadata['response']) ? $aiRun->metadata['response'] : null,
            'escalated' => ($download->failure_count ?? 0) >= 3 || $download->escalation_ticket_id !== null,
            'ticket' => $ticket ? [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'url' => route('admin.support.tickets.show', $ticket),
            ] : null,
            'created_at' => $download->created_at?->toIso8601String(),
            'last_failed_at' => $download->last_failed_at?->toIso8601String(),
        ]);
    }

    protected function getAgentRunsForDownloads(array $downloadIds): array
    {
        if (empty($downloadIds)) {
            return [];
        }

        $runs = AIAgentRun::where('agent_id', 'download_zip_failure_analyzer')
            ->where('task_type', AITaskType::DOWNLOAD_ZIP_FAILURE_ANALYSIS)
            ->where('status', 'success')
            ->whereNotNull('metadata')
            ->orderBy('started_at', 'desc')
            ->get();

        $byDownload = [];
        foreach ($runs as $run) {
            $downloadId = $this->extractDownloadIdFromRun($run);
            if ($downloadId && in_array($downloadId, $downloadIds, true) && ! isset($byDownload[$downloadId])) {
                $byDownload[$downloadId] = $run;
            }
        }

        // Fallback: match by tenant_id + time window if metadata doesn't have download_id
        $downloads = Download::withTrashed()
            ->whereIn('id', $downloadIds)
            ->get()
            ->keyBy('id');

        foreach ($downloadIds as $id) {
            if (isset($byDownload[$id])) {
                continue;
            }
            $download = $downloads->get($id);
            if (! $download || ! $download->last_failed_at) {
                continue;
            }
            $run = $runs->first(function ($r) use ($download) {
                return $r->tenant_id === $download->tenant_id
                    && $r->started_at >= $download->last_failed_at->subMinutes(5)
                    && $r->started_at <= $download->last_failed_at->addMinutes(10);
            });
            if ($run) {
                $byDownload[$id] = $run;
            }
        }

        return $byDownload;
    }

    protected function extractDownloadIdFromRun(AIAgentRun $run): ?string
    {
        $options = $run->metadata['options'] ?? [];
        if (isset($options['download_id'])) {
            return (string) $options['download_id'];
        }
        if (config('ai.logging.store_prompts', false) && isset($run->metadata['prompt'])) {
            if (preg_match('/Download ID:\s*([a-f0-9-]+)/i', $run->metadata['prompt'], $m)) {
                return $m[1];
            }
        }
        return null;
    }

    protected function parseSeverityFromResponse(AIAgentRun $run): ?string
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

    protected function parseSummaryFromResponse(AIAgentRun $run): ?string
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

    protected function parseRecommendationFromResponse(AIAgentRun $run): ?string
    {
        $text = $run->metadata['response'] ?? $run->metadata['prompt'] ?? null;
        if (! $text || stripos($text, '"recommendation"') === false) {
            return null;
        }
        if (preg_match('/"recommendation"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $m)) {
            return trim(stripslashes($m[1]));
        }
        return null;
    }
}
