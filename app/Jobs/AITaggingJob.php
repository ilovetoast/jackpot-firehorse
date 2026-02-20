<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Services\ActivityRecorder;
use App\Services\AssetProcessingFailureService;
use App\Support\Logging\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AITaggingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Never retry forever - enforce maximum attempts.
     *
     * @var int
     */
    public $tries = 3; // Maximum retry attempts (enforced by AssetProcessingFailureService)

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $asset = Asset::findOrFail($this->assetId);

        PipelineLogger::warning('AI TAGGING: HANDLE START', [
            'asset_id' => $asset->id,
        ]);

        // Idempotency: Check if AI tagging already completed
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['ai_tagging_completed']) && $existingMetadata['ai_tagging_completed'] === true) {
            PipelineLogger::warning('AI TAGGING: SKIPPED', [
                'asset_id' => $asset->id,
                'reason' => 'already_completed',
            ]);
            Log::info('[AITaggingJob] AI tagging skipped - already completed', [
                'asset_id' => $asset->id,
            ]);
            // Ensure status is set even if already completed
            if (!isset($existingMetadata['_ai_tagging_status'])) {
                $existingMetadata['_ai_tagging_status'] = 'completed';
                $asset->update(['metadata' => $existingMetadata]);
            }
            // Job chaining is handled by Bus::chain() in ProcessAssetJob
            // Chain will continue to next job automatically
            return;
        }

        // Ensure thumbnails have been generated (check thumbnail_status, not asset status)
        // Asset.status remains UPLOADED throughout processing for visibility
        //
        // ARCHITECTURAL NOTE: This job uses "skip" model (marks as skipped, doesn't retry).
        // PopulateAutomaticMetadataJob uses "retry until ready" model (release() + reschedule).
        // Both models are valid, but consider standardizing on Option A (retry until ready)
        // for consistency across all image-derived jobs long-term.
        // See /docs/PIPELINE_SEQUENCING.md for architectural details.
        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            PipelineLogger::warning('AI TAGGING: SKIPPED', [
                'asset_id' => $asset->id,
                'reason' => 'thumbnail_unavailable',
            ]);
            Log::warning('[AITaggingJob] AI tagging skipped - thumbnails have not completed', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            ]);
            // Mark as skipped AND set ai_tagging_completed so pipeline can complete
            // (SKIPPED/FAILED thumbnails = unsupported type; no AI tagging possible)
            $this->markAsSkipped($asset, 'thumbnail_unavailable');
            return;
        }

        // NOTE: Reviewable AI tags come from AiMetadataGenerationService (AiMetadataGenerationJob),
        // which creates records in asset_tag_candidates. This job does NOT generate tags.
        // It only sets ai_tagging_completed so AssetCompletionService::isComplete() can pass.
        // The generateAITags() stub and metadata['ai_tags'] were never used by the UI.

        // Update asset metadata - set completion flag for pipeline sequencing
        // IMPORTANT: Asset.status must NOT be mutated here.
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['ai_tagging_completed'] = true;
        $currentMetadata['ai_tagging_completed_at'] = now()->toIso8601String();

        $asset->update([
            'metadata' => $currentMetadata,
        ]);

        // Record AI tagging completed activity event
        ActivityRecorder::logAsset($asset, EventType::ASSET_AI_TAGGING_COMPLETED, [
            'job' => 'AITaggingJob',
            'tag_count' => 0,
        ]);

        PipelineLogger::warning('AI TAGGING: COMPLETE', [
            'asset_id' => $asset->id,
        ]);

        Log::info('[AITaggingJob] AI tagging completed', [
            'asset_id' => $asset->id,
        ]);

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Mark asset as skipped.
     *
     * Sets explicit status for debugging: _ai_tagging_status = "skipped:{reason}"
     *
     * @param Asset $asset
     * @param string $reason Skip reason (e.g., 'thumbnail_unavailable')
     * @return void
     */
    protected function markAsSkipped(Asset $asset, string $reason): void
    {
        $metadata = $asset->metadata ?? [];
        $metadata['_ai_tagging_skipped'] = true;
        $metadata['_ai_tagging_skip_reason'] = $reason;
        $metadata['_ai_tagging_skipped_at'] = now()->toIso8601String();
        $metadata['_ai_tagging_status'] = "skipped:{$reason}"; // Explicit status for debugging
        // Set ai_tagging_completed so pipeline can complete (no thumbnails = no AI tags, but step is done)
        $metadata['ai_tagging_completed'] = true;
        $metadata['ai_tagging_completed_at'] = now()->toIso8601String();
        $asset->update(['metadata' => $metadata]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            // Mark as failed with explicit status
            $metadata = $asset->metadata ?? [];
            $metadata['_ai_tagging_failed'] = true;
            $metadata['_ai_tagging_error'] = $exception->getMessage();
            $metadata['_ai_tagging_failed_at'] = now()->toIso8601String();
            $metadata['_ai_tagging_status'] = 'failed'; // Explicit status for debugging
            $asset->update(['metadata' => $metadata]);
            
            // Use centralized failure recording service
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts()
            );
        }
    }
}
