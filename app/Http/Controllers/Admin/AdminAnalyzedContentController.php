<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AITaskType;
use App\Enums\AssetType;
use App\Http\Controllers\Controller;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Services\VideoInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hub for AI-analyzed content tooling: links to EBI, editor audit, and video-insights monitoring.
 */
class AdminAnalyzedContentController extends Controller
{
    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        $isSiteEngineering = in_array('site_engineering', $siteRoles);
        $canRegenerate = $user->can('assets.regenerate_thumbnails_admin');

        if (! $isSiteOwner && ! $isSiteAdmin && ! $isSiteEngineering && ! $canRegenerate) {
            abort(403, 'Only site owners, site admins, site engineering, or users with assets.regenerate_thumbnails_admin can access this page.');
        }
    }

    /**
     * GET /app/admin/ai/analyzed-content
     */
    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $base = Asset::query()
            ->whereNull('deleted_at')
            ->where('type', AssetType::ASSET)
            ->where('mime_type', 'like', 'video/%');

        $libraryVideoTotal = (clone $base)->count();

        $videoInsightCounts = [
            'library_video_total' => $libraryVideoTotal,
            'queued' => (clone $base)->where('metadata->ai_video_status', 'queued')->count(),
            'processing' => (clone $base)->where('metadata->ai_video_status', 'processing')->count(),
            'completed' => (clone $base)->where('metadata->ai_video_status', 'completed')->count(),
            'skipped' => (clone $base)->where('metadata->ai_video_status', 'skipped')->count(),
            'failed' => (clone $base)->where('metadata->ai_video_status', 'failed')->count(),
        ];

        $summed = $videoInsightCounts['queued']
            + $videoInsightCounts['processing']
            + $videoInsightCounts['completed']
            + $videoInsightCounts['skipped']
            + $videoInsightCounts['failed'];
        $videoInsightCounts['other_or_unset'] = max(0, $libraryVideoTotal - $summed);

        $runs = AIAgentRun::query()
            ->where('task_type', AITaskType::VIDEO_INSIGHTS)
            ->with(['tenant:id,name'])
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $entityIds = $runs->pluck('entity_id')->filter()->unique()->values()->all();
        $assetsById = $entityIds === []
            ? collect()
            : Asset::query()
                ->whereIn('id', $entityIds)
                ->get(['id', 'metadata'])
                ->keyBy('id');

        $videoInsightsQueue = (string) (config('assets.video_ai.queue') ?: config('queue.ai_low_queue', 'ai-low'));

        $recentLibraryVideos = (clone $base)
            ->with(['tenant:id,name'])
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get(['id', 'tenant_id', 'metadata', 'updated_at'])
            ->map(static function (Asset $a) {
                $meta = $a->metadata ?? [];

                return [
                    'id' => $a->id,
                    'tenant_name' => $a->tenant?->name,
                    'ai_video_status' => $meta['ai_video_status'] ?? null,
                    'skip_reason' => $meta['ai_video_insights_skip_reason'] ?? null,
                    'error' => $meta['ai_video_insights_error'] ?? null,
                    'updated_at' => $a->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $recentVideoRuns = $runs
            ->map(function (AIAgentRun $r) use ($assetsById) {
                $assetRow = $r->entity_id ? ($assetsById[$r->entity_id] ?? null) : null;
                $assetMeta = $assetRow ? ($assetRow->metadata ?? null) : null;
                $runMeta = $r->metadata ?? [];
                $step = $runMeta['step'] ?? null;
                if ($step === null && is_array($assetMeta)) {
                    $step = $assetMeta['ai_video_insights_step'] ?? null;
                }

                $durationSeconds = null;
                if ($r->started_at) {
                    $end = $r->completed_at ?? now();
                    $durationSeconds = $r->started_at->diffInSeconds($end);
                }

                return [
                    'id' => $r->id,
                    'entity_id' => $r->entity_id,
                    'status' => $r->status,
                    'summary' => $r->summary ? mb_substr((string) $r->summary, 0, 160) : null,
                    'error_message' => $r->error_message ? mb_substr((string) $r->error_message, 0, 120) : null,
                    'started_at' => $r->started_at?->toIso8601String(),
                    'completed_at' => $r->completed_at?->toIso8601String(),
                    'tenant_name' => $r->tenant?->name,
                    'estimated_cost' => $r->estimated_cost !== null ? (float) $r->estimated_cost : null,
                    'tokens_in' => $r->tokens_in,
                    'tokens_out' => $r->tokens_out,
                    'model_used' => $r->model_used,
                    'step' => $step,
                    'duration_seconds' => $durationSeconds,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Admin/AnalyzedContent/Index', [
            'video_ai_enabled' => (bool) config('assets.video_ai.enabled', true),
            'video_insight_counts' => $videoInsightCounts,
            'recent_library_videos' => $recentLibraryVideos,
            'video_insights_worker_queue' => $videoInsightsQueue,
            'queue_workers_enabled' => filter_var(env('QUEUE_WORKERS_ENABLED', true), FILTER_VALIDATE_BOOL),
            'recent_video_runs' => $recentVideoRuns,
            'admin_asset_url' => url('/app/admin/assets'),
            'video_insight_run_detail_base' => url('/app/admin/ai/analyzed-content/video-insights/runs'),
            'video_insight_frames_base' => url('/app/admin/ai/analyzed-content/video-insights/assets'),
        ]);
    }

    /**
     * JSON detail for troubleshooting: prompt, raw model output, asset insights, transcript.
     */
    public function videoInsightRunDetail(int $run): JsonResponse
    {
        $this->authorizeAdmin();

        $agentRun = AIAgentRun::query()
            ->where('task_type', AITaskType::VIDEO_INSIGHTS)
            ->with(['tenant:id,name'])
            ->findOrFail($run);

        $asset = $agentRun->entity_id
            ? Asset::query()->where('id', $agentRun->entity_id)->first()
            : null;

        $meta = $agentRun->metadata ?? [];
        $assetMeta = $asset?->metadata ?? [];

        return response()->json([
            'run' => [
                'id' => $agentRun->id,
                'status' => $agentRun->status,
                'model_used' => $agentRun->model_used,
                'tokens_in' => $agentRun->tokens_in,
                'tokens_out' => $agentRun->tokens_out,
                'estimated_cost' => $agentRun->estimated_cost !== null ? (float) $agentRun->estimated_cost : null,
                'started_at' => $agentRun->started_at?->toIso8601String(),
                'completed_at' => $agentRun->completed_at?->toIso8601String(),
                'duration_seconds' => $agentRun->started_at
                    ? $agentRun->started_at->diffInSeconds($agentRun->completed_at ?? now())
                    : null,
                'summary' => $agentRun->summary,
                'error_message' => $agentRun->error_message,
                'step' => $meta['step'] ?? null,
                'failed_at_step' => $meta['failed_at_step'] ?? null,
                'vision_prompt' => $meta['vision_prompt'] ?? null,
                'raw_llm_response' => $meta['raw_llm_response'] ?? null,
                'frame_count' => $meta['frame_count'] ?? null,
            ],
            'asset' => $asset ? [
                'id' => $asset->id,
                'ai_video_status' => $assetMeta['ai_video_status'] ?? null,
                'ai_video_insights_step' => $assetMeta['ai_video_insights_step'] ?? null,
                'ai_video_insights' => $assetMeta['ai_video_insights'] ?? null,
                'ai_video_insights_skip_reason' => $assetMeta['ai_video_insights_skip_reason'] ?? null,
                'ai_video_insights_error' => $assetMeta['ai_video_insights_error'] ?? null,
            ] : null,
            'tenant' => $agentRun->tenant
                ? ['id' => $agentRun->tenant->id, 'name' => $agentRun->tenant->name]
                : null,
            'admin_asset_operations_url' => $asset
                ? url('/app/admin/assets').'?asset_id='.rawurlencode((string) $asset->id)
                : null,
        ]);
    }

    /**
     * Re-sample frames from the source video (same FFmpeg rules as production insights).
     */
    public function videoInsightFrames(string $asset): JsonResponse
    {
        $this->authorizeAdmin();

        $assetModel = Asset::query()
            ->where('id', $asset)
            ->where('type', AssetType::ASSET)
            ->where('mime_type', 'like', 'video/%')
            ->firstOrFail();

        $payload = app(VideoInsightsService::class)->previewSampledFramesForAdmin($assetModel);

        return response()->json($payload);
    }
}
