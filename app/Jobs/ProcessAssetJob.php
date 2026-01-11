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

        // Idempotency: Skip if already processing or completed
        if (in_array($asset->status, [
            AssetStatus::PROCESSING,
            AssetStatus::THUMBNAIL_GENERATED,
            AssetStatus::AI_TAGGED,
            AssetStatus::COMPLETED,
        ])) {
            Log::info('Asset processing skipped - already processing or completed', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Skip if failed (don't reprocess failed assets automatically)
        if ($asset->status === AssetStatus::FAILED) {
            Log::warning('Asset processing skipped - asset is in failed state', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Only process assets that are in UPLOADED state
        if ($asset->status !== AssetStatus::UPLOADED) {
            Log::warning('Asset processing skipped - asset is not in uploaded state', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Update status to PROCESSING
        $asset->update([
            'status' => AssetStatus::PROCESSING,
        ]);

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
