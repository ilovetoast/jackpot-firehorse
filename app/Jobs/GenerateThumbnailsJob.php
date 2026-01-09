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
use Illuminate\Support\Facades\Log;

class GenerateThumbnailsJob implements ShouldQueue
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

        // Idempotency: Check if thumbnails already generated
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['thumbnails_generated']) && $existingMetadata['thumbnails_generated'] === true) {
            Log::info('Thumbnail generation skipped - already generated', [
                'asset_id' => $asset->id,
            ]);
            // Job chaining is handled by Bus::chain() in ProcessAssetJob
            // Chain will continue to next job automatically
            return;
        }

        // Generate thumbnails (stub implementation)
        $thumbnails = $this->generateThumbnails($asset);

        // Update asset metadata
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['thumbnails_generated'] = true;
        $currentMetadata['thumbnails_generated_at'] = now()->toIso8601String();
        $currentMetadata['thumbnails'] = $thumbnails;

        // Update status to THUMBNAIL_GENERATED
        $asset->update([
            'status' => AssetStatus::THUMBNAIL_GENERATED,
            'metadata' => $currentMetadata,
        ]);

        // Emit thumbnails generated event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null,
            'event_type' => 'asset.thumbnails.generated',
            'metadata' => [
                'job' => 'GenerateThumbnailsJob',
                'thumbnail_count' => count($thumbnails),
            ],
            'created_at' => now(),
        ]);

        Log::info('Thumbnails generated', [
            'asset_id' => $asset->id,
            'thumbnail_count' => count($thumbnails),
        ]);

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Generate thumbnails for asset (stub implementation).
     *
     * @param Asset $asset
     * @return array
     */
    protected function generateThumbnails(Asset $asset): array
    {
        // Stub implementation - future phase will add actual thumbnail generation
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
