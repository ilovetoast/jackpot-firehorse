<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetProcessingFailureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessAssetJob implements ShouldQueue
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
    public $backoff = [60, 300, 900]; // 1 minute, 5 minutes, 15 minutes

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

        // Skip if failed (don't reprocess failed assets automatically)
        if ($asset->status === AssetStatus::FAILED) {
            Log::warning('Asset processing skipped - asset is in failed state', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Only process assets that are VISIBLE (not hidden or failed)
        // Asset.status represents VISIBILITY, not processing progress
        // Processing jobs must NOT mutate Asset.status (assets must remain visible in grid)
        // Processing progress is tracked via thumbnail_status, metadata flags, and activity events
        if ($asset->status !== AssetStatus::VISIBLE) {
            Log::info('Asset processing skipped - asset is not visible', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Idempotency: Check if processing has already started (via metadata)
        // Individual jobs in the chain have their own idempotency checks
        $metadata = $asset->metadata ?? [];
        if (isset($metadata['processing_started'])) {
            Log::info('Asset processing skipped - processing already started', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Mark processing as started in metadata (for idempotency)
        // IMPORTANT: Asset.status must NOT be mutated here.
        // Asset.status represents VISIBILITY (UPLOADED = visible in grid, COMPLETED = visible in dashboard),
        // not processing progress. Mutating status would cause assets to disappear from the asset grid
        // (AssetController queries status = UPLOADED). Processing progress is tracked via:
        // - thumbnail_status (for thumbnail generation)
        // - metadata flags (processing_started, metadata_extracted, thumbnails_generated, etc.)
        // - activity events (asset.processing.started, etc.)
        // Only FinalizeAssetJob is authorized to change status from UPLOADED to COMPLETED.
        $metadata['processing_started'] = true;
        $metadata['processing_started_at'] = now()->toIso8601String();
        $asset->update(['metadata' => $metadata]);

        // Emit processing started event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null, // System event
            'event_type' => 'asset.processing.started',
            'metadata' => [
                'job' => 'ProcessAssetJob',
            ],
            'created_at' => now(),
        ]);

        Log::info('Asset processing started', [
            'asset_id' => $asset->id,
            'original_filename' => $asset->original_filename,
        ]);

        // Dispatch processing chain using Bus::chain()
        // Processing pipeline:
        // 1. ExtractMetadataJob - Extract file metadata
        // 2. GenerateThumbnailsJob - Generate thumbnail styles
        // 3. GeneratePreviewJob - Generate preview images
        // 4. AITaggingJob - AI-powered tagging
        // 5. FinalizeAssetJob - Mark asset as completed
        // 6. PromoteAssetJob - Move from temp/ to canonical assets/ location
        //    (runs after thumbnail generation, requires COMPLETED status)
        Bus::chain([
            new ExtractMetadataJob($asset->id),
            new GenerateThumbnailsJob($asset->id),
            new GeneratePreviewJob($asset->id),
            new AITaggingJob($asset->id),
            new FinalizeAssetJob($asset->id),
            new PromoteAssetJob($asset->id),
        ])->dispatch();
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
