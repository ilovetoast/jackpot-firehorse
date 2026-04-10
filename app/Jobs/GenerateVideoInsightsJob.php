<?php

namespace App\Jobs;

use App\Enums\AITaskType;
use App\Enums\EventType;
use App\Exceptions\AIQuotaExceededException;
use App\Exceptions\PlanLimitExceededException;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\Tenant;
use App\Services\ActivityRecorder;
use App\Services\AiTagPolicyService;
use App\Services\AiUsageService;
use App\Services\FileTypeService;
use App\Services\VideoAiMinuteEstimator;
use App\Services\VideoInsightsSearchIndexWriter;
use App\Services\VideoInsightsService;
use App\Support\AiErrorSanitizer;
use App\Support\VideoInsights\VideoInsightsJobPreflight;
use App\Support\VideoInsights\VideoInsightsPreflightOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async video intelligence: sampled frames + optional Whisper + one vision call.
 * Does not participate in the thumbnail chain; failures do not affect asset finalization.
 */
class GenerateVideoInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public int $maxExceptions = 1;

    public $timeout = 900;

    public function __construct(
        public readonly string $assetId
    ) {
        $q = config('assets.video_ai.queue');

        $this->onQueue($q ?: config('queue.ai_low_queue', 'ai-low'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof AIQuotaExceededException || $exception instanceof PlanLimitExceededException) {
            return false;
        }

        return true;
    }

    public function handle(
        VideoInsightsService $videoInsights,
        AiUsageService $usageService,
        AiTagPolicyService $policyService,
        FileTypeService $fileTypeService,
        VideoAiMinuteEstimator $minuteEstimator,
        VideoInsightsSearchIndexWriter $videoSearchIndex,
    ): void {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }

        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        $meta = $asset->metadata ?? [];
        $preflight = VideoInsightsJobPreflight::evaluate(
            $fileType,
            (bool) config('assets.video_ai.enabled', true),
            $meta,
        );

        if ($preflight !== VideoInsightsPreflightOutcome::Proceed) {
            $this->applyPreflightOutcome($asset, $preflight, $meta);

            return;
        }

        $policy = $policyService->shouldProceedWithAiTagging($asset);
        if (! $policy['should_proceed']) {
            $this->markSkipped($asset, $policy['reason'] ?? 'policy');

            return;
        }

        if (! $asset->storage_root_path || ! $asset->storageBucket) {
            if ($this->attempts() < 15) {
                $this->release(45);

                return;
            }
            $this->markFailed($asset, 'Video file not yet available in storage.');
            Log::warning('[GenerateVideoInsightsJob] Missing storage path after waits', ['asset_id' => $asset->id]);

            return;
        }

        $this->patchMetadata($asset, ['ai_video_status' => 'processing']);

        $tenant = Tenant::find($asset->tenant_id);
        if (! $tenant) {
            $this->markSkipped($asset, 'tenant_not_found');

            return;
        }

        try {
            $usageService->checkUsage($tenant, 'video_insights', 1);
        } catch (PlanLimitExceededException $e) {
            $this->markSkipped($asset, 'plan_limit_exceeded');

            return;
        }

        try {
            $estMinutes = $minuteEstimator->estimateBillableMinutesForAsset($asset);
            $usageService->checkVideoAiMinuteBudget($tenant, $estMinutes);
        } catch (PlanLimitExceededException $e) {
            $this->markSkipped($asset, 'video_minute_limit_exceeded');

            return;
        }

        $agentRun = AIAgentRun::create([
            'agent_id' => 'video_insights',
            'agent_name' => 'Video insights',
            'triggering_context' => 'tenant',
            'environment' => app()->environment(),
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'task_type' => AITaskType::VIDEO_INSIGHTS,
            'entity_type' => 'asset',
            'entity_id' => $asset->id,
            'model_used' => (string) config('ai.video_insights.model', 'gpt-4o-mini'),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed',
            'started_at' => now(),
            'metadata' => [
                'asset_id' => $asset->id,
                'type' => 'video_insights',
                'subtype' => 'pending',
            ],
        ]);

        try {
            $results = $videoInsights->analyze($asset);

            try {
                $usageService->trackUsageWithCost(
                    $tenant,
                    'video_insights',
                    1,
                    $results['cost_usd'],
                    $results['tokens_in'],
                    $results['tokens_out'],
                    $results['model']
                );
            } catch (PlanLimitExceededException $e) {
                $agentRun->markAsFailed($e->getMessage());
                $this->markSkipped($asset, 'plan_limit_exceeded');

                return;
            }

            $transcript = $results['transcript'] ?? '';
            $transcriptStored = $transcript !== '' ? mb_substr($transcript, 0, 12000) : '';
            $billableMinutes = round(((float) ($results['effective_duration_sampled'] ?? 0)) / 60, 4);

            $metaBefore = $asset->metadata ?? [];
            $prevCost = (float) ($metaBefore['ai_video_insights_total_cost_usd'] ?? 0);
            $runCost = (float) ($results['cost_usd'] ?? 0);

            $patch = [
                'ai_video_status' => 'completed',
                'ai_video_insights' => [
                    'tags' => $results['tags'],
                    'summary' => $results['summary'],
                    'suggested_category' => $results['suggested_category'],
                    'metadata' => $results['metadata'],
                    'transcript' => $transcriptStored,
                    'moments' => is_array($results['moments'] ?? null) ? $results['moments'] : [],
                ],
                'ai_video_insights_total_cost_usd' => round($prevCost + $runCost, 6),
                'ai_video_frame_interval_seconds' => max(1, (int) config('assets.video_ai.frame_interval_seconds', 3)),
                'ai_video_insights_completed_at' => now()->toIso8601String(),
                'ai_video_insights_error' => null,
                'ai_video_insights_failed_at' => null,
            ];

            $this->patchMetadata($asset, $patch);

            $agentRun->markAsSuccessful(
                $results['tokens_in'],
                $results['tokens_out'],
                $results['cost_usd'],
                array_merge($agentRun->metadata ?? [], [
                    'frame_count' => $results['frame_count'],
                    'has_transcript' => $transcript !== '',
                    'type' => 'video_insights',
                    'subtype' => $transcript !== '' ? 'frame_analysis_and_transcription' : 'frame_analysis',
                    'billable_minutes' => $billableMinutes,
                    'vision_cost_usd' => (float) ($results['vision_cost_usd'] ?? 0),
                    'whisper_cost_usd' => (float) ($results['whisper_cost_usd'] ?? 0),
                ]),
                null,
                null,
                mb_substr($results['summary'] ?? '', 0, 500)
            );

            $asset->refresh();
            $videoSearchIndex->syncForAsset($asset);

            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_VIDEO_INSIGHTS_COMPLETED, [
                'agent_run_id' => $agentRun->id,
                'frame_count' => $results['frame_count'],
                'tag_count' => count($results['tags']),
                'cost' => $results['cost_usd'],
            ]);
        } catch (PlanLimitExceededException $e) {
            $agentRun->markAsFailed($e->getMessage());
            $this->markSkipped($asset, 'plan_limit_exceeded');
        } catch (AIQuotaExceededException $e) {
            $agentRun->markAsFailed($e->getMessage());
            $this->markFailed($asset, AiErrorSanitizer::forUser($e->getMessage()));
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_VIDEO_INSIGHTS_FAILED, [
                'error' => AiErrorSanitizer::forUser($e->getMessage()),
                'error_type' => 'quota_exceeded',
                'agent_run_id' => $agentRun->id,
            ]);
            $this->fail($e);
        } catch (\Throwable $e) {
            $raw = $e->getMessage();
            $agentRun->markAsFailed($raw);
            $this->markFailed($asset, AiErrorSanitizer::forUser($raw));
            Log::error('[GenerateVideoInsightsJob] Video insights failed', [
                'asset_id' => $asset->id,
                'agent_run_id' => $agentRun->id,
                'error' => $raw,
            ]);
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_VIDEO_INSIGHTS_FAILED, [
                'error' => AiErrorSanitizer::forUser($raw),
                'error_type' => get_class($e),
                'agent_run_id' => $agentRun->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $meta  Snapshot of asset metadata at preflight time.
     */
    protected function applyPreflightOutcome(Asset $asset, VideoInsightsPreflightOutcome $outcome, array $meta): void
    {
        match ($outcome) {
            VideoInsightsPreflightOutcome::NotVideoClearQueue => $this->clearQueuedFlag($asset),
            VideoInsightsPreflightOutcome::FeatureDisabled => $this->markSkipped($asset, 'video_ai_disabled'),
            VideoInsightsPreflightOutcome::UploadOptOut => $this->markSkipped($asset, 'upload_opt_out'),
            VideoInsightsPreflightOutcome::InsightsAlreadyComplete => $this->normalizeCompletedInsightsStatus($asset, $meta),
            VideoInsightsPreflightOutcome::Proceed => null,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function normalizeCompletedInsightsStatus(Asset $asset, array $meta): void
    {
        if (VideoInsightsJobPreflight::shouldPatchStatusToCompleted($meta)) {
            $this->patchMetadata($asset, ['ai_video_status' => 'completed']);
        }
    }

    protected function clearQueuedFlag(Asset $asset): void
    {
        $meta = $asset->metadata ?? [];
        $st = $meta['ai_video_status'] ?? null;
        if (in_array($st, ['queued', 'processing'], true)) {
            unset($meta['ai_video_status']);
            $asset->update(['metadata' => $meta]);
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    protected function patchMetadata(Asset $asset, array $patch): void
    {
        $asset->refresh();
        $meta = $asset->metadata ?? [];
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($meta[$k]);
            } else {
                $meta[$k] = $v;
            }
        }
        $asset->update(['metadata' => $meta]);
    }

    protected function markSkipped(Asset $asset, string $reason): void
    {
        $this->patchMetadata($asset, [
            'ai_video_status' => 'skipped',
            'ai_video_insights_skip_reason' => $reason,
            'ai_video_insights_skipped_at' => now()->toIso8601String(),
        ]);
    }

    protected function markFailed(Asset $asset, string $message): void
    {
        $this->patchMetadata($asset, [
            'ai_video_status' => 'failed',
            'ai_video_insights_error' => $message,
            'ai_video_insights_failed_at' => now()->toIso8601String(),
        ]);
    }
}
