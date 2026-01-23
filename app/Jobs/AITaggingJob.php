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
            Log::info('AI tagging skipped - already completed', [
                'asset_id' => $asset->id,
            ]);
            // Job chaining is handled by Bus::chain() in ProcessAssetJob
            // Chain will continue to next job automatically
            return;
        }

        // Ensure thumbnails have been generated (check thumbnail_status, not asset status)
        // Asset.status remains UPLOADED throughout processing for visibility
        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            Log::warning('AI tagging skipped - thumbnails have not completed', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            ]);
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

        // Record AI tagging completed activity event
        ActivityRecorder::logAsset($asset, EventType::ASSET_AI_TAGGING_COMPLETED, [
            'job' => 'AITaggingJob',
            'tag_count' => count($tags),
        ]);

        Log::info('AI tagging completed', [
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
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
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
