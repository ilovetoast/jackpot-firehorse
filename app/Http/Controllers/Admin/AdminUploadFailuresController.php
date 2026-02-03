<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AITaskType;
use App\Models\AIAgentRun;
use App\Models\Ticket;
use App\Models\UploadSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase U-1: Admin visibility for failed uploads.
 *
 * READ-ONLY. No retry or escalation toggles.
 */
class AdminUploadFailuresController extends Controller
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

        $query = UploadSession::withTrashed()
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

        $uploads = $query->paginate(25)->withQueryString();

        $uploadIds = $uploads->pluck('id')->toArray();
        $agentRunsByUpload = $this->getAgentRunsForUploads($uploadIds);

        $uploads->getCollection()->transform(function ($upload) use ($agentRunsByUpload) {
            $aiRun = $agentRunsByUpload[$upload->id] ?? null;
            return [
                'id' => $upload->id,
                'tenant' => $upload->tenant ? [
                    'id' => $upload->tenant->id,
                    'name' => $upload->tenant->name,
                    'slug' => $upload->tenant->slug,
                ] : null,
                'stage' => $upload->upload_options['upload_failure_stage'] ?? null,
                'failure_reason' => $upload->failure_reason,
                'failure_count' => $upload->failure_count ?? 0,
                'ai_severity' => $aiRun ? $this->parseSeverityFromResponse($aiRun) : null,
                'ai_summary' => $aiRun ? $this->parseSummaryFromResponse($aiRun) : null,
                'ai_recommendation' => $aiRun ? $this->parseRecommendationFromResponse($aiRun) : null,
                'escalated' => ($upload->failure_count ?? 0) >= 3 || $upload->escalation_ticket_id !== null,
                'escalation_ticket_id' => $upload->escalation_ticket_id,
                'bytes_uploaded' => $upload->uploaded_size,
                'expected_size' => $upload->expected_size,
                'created_at' => $upload->created_at?->toIso8601String(),
                'last_failed_at' => $upload->last_failed_at?->toIso8601String(),
            ];
        });

        $stats = [
            'failed_last_24h' => UploadSession::withTrashed()
                ->whereNotNull('last_failed_at')
                ->where('last_failed_at', '>=', now()->subDay())
                ->count(),
            'escalated' => UploadSession::withTrashed()
                ->whereNotNull('last_failed_at')
                ->where(function ($q) {
                    $q->whereNotNull('escalation_ticket_id')->orWhere('failure_count', '>=', 3);
                })
                ->count(),
            'awaiting_review' => UploadSession::withTrashed()
                ->whereNotNull('last_failed_at')
                ->whereNull('escalation_ticket_id')
                ->where(function ($q) {
                    $q->where('failure_count', '<', 3)->orWhereNull('failure_count');
                })
                ->where('last_failed_at', '>=', now()->subDay())
                ->count(),
        ];

        return Inertia::render('Admin/UploadFailures/Index', [
            'uploads' => $uploads,
            'stats' => $stats,
            'filters' => $request->only(['tenant_id', 'failure_reason', 'escalated']),
        ]);
    }

    public function show(string $upload): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();

        $uploadSession = UploadSession::withTrashed()->with(['tenant:id,name,slug'])->findOrFail($upload);

        $aiRun = AIAgentRun::where('agent_id', 'upload_failure_analyzer')
            ->where('task_type', AITaskType::UPLOAD_FAILURE_ANALYSIS)
            ->where('tenant_id', $uploadSession->tenant_id)
            ->where('status', 'success')
            ->where('started_at', '>=', $uploadSession->last_failed_at?->subMinutes(5) ?? now()->subDay())
            ->where('started_at', '<=', $uploadSession->last_failed_at?->addMinutes(10) ?? now())
            ->orderBy('started_at', 'desc')
            ->first();

        $ticket = null;
        if ($uploadSession->escalation_ticket_id) {
            $ticket = Ticket::find($uploadSession->escalation_ticket_id);
        }

        $failureReason = $uploadSession->failure_reason;
        $failureReasonValue = is_string($failureReason) ? $failureReason : ($failureReason?->value ?? 'unknown');

        return response()->json([
            'id' => $uploadSession->id,
            'tenant' => $uploadSession->tenant ? [
                'id' => $uploadSession->tenant->id,
                'name' => $uploadSession->tenant->name,
                'slug' => $uploadSession->tenant->slug,
            ] : null,
            'stage' => $uploadSession->upload_options['upload_failure_stage'] ?? null,
            'failure_reason' => $failureReasonValue,
            'failure_count' => $uploadSession->failure_count ?? 0,
            'bytes_uploaded' => $uploadSession->uploaded_size,
            'expected_size' => $uploadSession->expected_size,
            'failure_trace' => $uploadSession->upload_options['upload_failure_trace'] ?? null,
            'ai_summary' => $aiRun ? $this->parseSummaryFromResponse($aiRun) : null,
            'ai_recommendation' => $aiRun ? $this->parseRecommendationFromResponse($aiRun) : null,
            'ai_severity' => $aiRun ? $this->parseSeverityFromResponse($aiRun) : null,
            'escalated' => ($uploadSession->failure_count ?? 0) >= 3 || $uploadSession->escalation_ticket_id !== null,
            'ticket' => $ticket ? [
                'id' => $ticket->id,
                'subject' => $ticket->metadata['subject'] ?? ('Ticket #' . $ticket->id),
                'status' => $ticket->status?->value ?? $ticket->status,
                'url' => route('admin.support.tickets.show', $ticket),
            ] : null,
            'created_at' => $uploadSession->created_at?->toIso8601String(),
            'last_failed_at' => $uploadSession->last_failed_at?->toIso8601String(),
        ]);
    }

    protected function getAgentRunsForUploads(array $uploadIds): array
    {
        if (empty($uploadIds)) {
            return [];
        }

        $runs = AIAgentRun::where('agent_id', 'upload_failure_analyzer')
            ->where('task_type', AITaskType::UPLOAD_FAILURE_ANALYSIS)
            ->where('status', 'success')
            ->whereNotNull('metadata')
            ->orderBy('started_at', 'desc')
            ->get();

        $byUpload = [];
        foreach ($runs as $run) {
            $uploadId = $this->extractUploadIdFromRun($run);
            if ($uploadId && in_array($uploadId, $uploadIds, true) && ! isset($byUpload[$uploadId])) {
                $byUpload[$uploadId] = $run;
            }
        }

        $uploads = UploadSession::withTrashed()
            ->whereIn('id', $uploadIds)
            ->get()
            ->keyBy('id');

        foreach ($uploadIds as $id) {
            if (isset($byUpload[$id])) {
                continue;
            }
            $upload = $uploads->get($id);
            if (! $upload || ! $upload->last_failed_at) {
                continue;
            }
            $run = $runs->first(function ($r) use ($upload) {
                return $r->tenant_id === $upload->tenant_id
                    && $r->started_at >= $upload->last_failed_at->subMinutes(5)
                    && $r->started_at <= $upload->last_failed_at->addMinutes(10);
            });
            if ($run) {
                $byUpload[$id] = $run;
            }
        }

        return $byUpload;
    }

    protected function extractUploadIdFromRun(AIAgentRun $run): ?string
    {
        $options = $run->metadata['options'] ?? $run->metadata ?? [];
        if (isset($options['upload_id'])) {
            return (string) $options['upload_id'];
        }
        if (config('ai.logging.store_prompts', false) && isset($run->metadata['prompt'])) {
            if (preg_match('/Upload ID:\s*([a-f0-9-]+)/i', $run->metadata['prompt'], $m)) {
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
