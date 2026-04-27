<?php

namespace App\Services\Assets;

use App\Jobs\AiMetadataGenerationJob;
use App\Jobs\AiMetadataSuggestionJob;
use App\Jobs\AiTagAutoApplyJob;
use App\Jobs\ProcessVideoInsightsBatchJob;
use App\Models\Asset;
use App\Services\AiTagPolicyService;
use App\Services\AiUsageService;
use App\Services\FileTypeService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * When a studio-animation MP4 leaves intake staging (filed into a category), run vision tagging / metadata
 * and (for video) the video-insights job — deferred from {@see \App\Jobs\ProcessAssetJob} while intake_state was staged.
 *
 * Also used after manual "Publish & categorize" / assign-category flows for other staged uploads so video AI
 * and tagging can start once a category exists.
 */
final class StagedFiledAssetAiService
{
    public function runIfDeferred(Asset $asset): void
    {
        $meta = $asset->metadata ?? [];
        if (empty($meta['_studio_staged_defer_ai'])) {
            return;
        }

        unset(
            $meta['_studio_staged_defer_ai'],
            $meta['_skip_ai_tagging'],
            $meta['_skip_ai_metadata'],
            $meta['_skip_ai_video_insights'],
        );
        $asset->metadata = $meta;
        $asset->saveQuietly();

        $this->queueVisionTaggingAndVideoInsights($asset->fresh(), true);
    }

    /**
     * After an asset is filed into a category (builder finalize, intake staged publish, or assign-category),
     * run deferred studio AI if applicable; otherwise queue standard vision/metadata/video-insights when allowed.
     *
     * @param  bool  $userRequestedAiPipeline  When true (e.g. assign-category with run_ai_pipeline), queue video insights
     *                                         even if {@see config('assets.video_ai.auto_run_after_upload')} is false.
     */
    public function queueAfterStagedCategorization(?Asset $asset, bool $userRequestedAiPipeline = false): void
    {
        if ($asset === null) {
            return;
        }
        $meta = $asset->metadata ?? [];
        if (! empty($meta['_studio_staged_defer_ai'])) {
            $this->runIfDeferred($asset);

            return;
        }

        $this->queueVisionTaggingAndVideoInsights($asset->fresh(), $userRequestedAiPipeline);
    }

    private function queueVisionTaggingAndVideoInsights(?Asset $asset, bool $userRequestedAiPipeline = false): void
    {
        if ($asset === null) {
            return;
        }

        $policy = app(AiTagPolicyService::class)->shouldProceedWithAiTagging($asset);
        // Vision/metadata work runs on the dedicated ai queue so it does not
        // contend with thumbnail/preview workers on the images queue.
        $aiQueue = (string) config('queue.ai_queue', 'ai');
        $fileType = app(FileTypeService::class)->detectFileType(
            (string) ($asset->mime_type ?? ''),
            pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION)
        );

        $shouldQueueVideoInsights = $userRequestedAiPipeline || (bool) config('assets.video_ai.auto_run_after_upload', false);

        if (
            $fileType === 'video'
            && config('assets.video_ai.enabled', true)
            && $shouldQueueVideoInsights
            && ($policy['should_proceed'] ?? false)
        ) {
            $tenant = $asset->tenant;
            $canQueueInsights = false;
            if ($tenant !== null) {
                try {
                    app(AiUsageService::class)->checkUsage($tenant, 'video_insights', 1);
                    $canQueueInsights = true;
                } catch (\Throwable $e) {
                    Log::info('[StagedFiledAssetAi] Video insights skipped (plan/cap)', [
                        'asset_id' => $asset->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
            if ($canQueueInsights) {
                $m = $asset->metadata ?? [];
                $m['ai_video_status'] = 'queued';
                unset(
                    $m['ai_video_insights_completed_at'],
                    $m['ai_video_insights_error'],
                    $m['ai_video_insights_failed_at'],
                );
                $asset->update(['metadata' => $m]);
                $asset = $asset->fresh();
                if ($asset !== null) {
                    ProcessVideoInsightsBatchJob::dispatch([(string) $asset->id]);
                }
            }
        }

        if ($asset === null) {
            return;
        }
        if (! ($policy['should_proceed'] ?? false)) {
            return;
        }

        Bus::chain([
            (new AiMetadataGenerationJob($asset->id))->onQueue($aiQueue),
            (new AiTagAutoApplyJob($asset->id))->onQueue($aiQueue),
            (new AiMetadataSuggestionJob($asset->id))->onQueue($aiQueue),
        ])->onQueue($aiQueue)->dispatch();
    }
}
