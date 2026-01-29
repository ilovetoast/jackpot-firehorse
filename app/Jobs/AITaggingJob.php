<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Services\ActivityRecorder;
use App\Services\AssetProcessingFailureService;
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

        // Idempotency: Check if AI tagging already completed
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['ai_tagging_completed']) && $existingMetadata['ai_tagging_completed'] === true) {
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
            Log::warning('[AITaggingJob] AI tagging skipped - thumbnails have not completed', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            ]);
            $this->markAsSkipped($asset, 'thumbnail_unavailable');
            return;
        }

        // AI tagging (stub implementation)
        $tags = $this->generateAITags($asset);

        // Update asset metadata
        // IMPORTANT: Asset.status must NOT be mutated here.
        // Asset.status represents VISIBILITY (UPLOADED = visible in grid, COMPLETED = visible in dashboard),
        // not processing progress. Mutating status would cause assets to disappear from the asset grid
        // (AssetController queries status = UPLOADED). Processing progress is tracked via:
        // - metadata flags (ai_tagging_completed, ai_tagging_completed_at)
        // - activity events (asset.ai_tagging.completed)
        // Only FinalizeAssetJob is authorized to change status from UPLOADED to COMPLETED.
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['ai_tagging_completed'] = true;
        $currentMetadata['ai_tagging_completed_at'] = now()->toIso8601String();
        if (!empty($tags)) {
            $currentMetadata['ai_tags'] = $tags;
        }

        $asset->update([
            'metadata' => $currentMetadata,
        ]);

        // Record AI tagging completed activity event (AI Tagging - general/freeform tags)
        ActivityRecorder::logAsset($asset, EventType::ASSET_AI_TAGGING_COMPLETED, [
            'job' => 'AITaggingJob',
            'tag_count' => count($tags),
            'tags' => $tags,
        ]);

        Log::info('[AITaggingJob] AI tagging completed', [
            'asset_id' => $asset->id,
            'tag_count' => count($tags),
        ]);

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Generate AI tags for asset (stub implementation).
     *
     * @param Asset $asset
     * @return array
     */
    protected function generateAITags(Asset $asset): array
    {
        // Stub implementation - future phase will add actual AI tagging
        // Returns empty array for now
        return [];
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
