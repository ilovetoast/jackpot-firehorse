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

class GeneratePreviewJob implements ShouldQueue
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

        // Idempotency: Check if preview already generated
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['preview_generated']) && $existingMetadata['preview_generated'] === true) {
            Log::info('Preview generation skipped - already generated', [
                'asset_id' => $asset->id,
            ]);
            // Job chaining is handled by Bus::chain() in ProcessAssetJob
            // Chain will continue to next job automatically
            return;
        }

        // Ensure asset is in THUMBNAIL_GENERATED status (from GenerateThumbnailsJob)
        if ($asset->status !== AssetStatus::THUMBNAIL_GENERATED) {
            Log::warning('Preview generation skipped - asset has not completed thumbnail generation', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Generate preview (stub implementation)
        $preview = $this->generatePreview($asset);

        // Update asset metadata
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['preview_generated'] = true;
        $currentMetadata['preview_generated_at'] = now()->toIso8601String();
        if ($preview) {
            $currentMetadata['preview'] = $preview;
        }

        $asset->update([
            'metadata' => $currentMetadata,
        ]);

        // Emit preview generated event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null,
            'event_type' => 'asset.preview.generated',
            'metadata' => [
                'job' => 'GeneratePreviewJob',
                'has_preview' => !empty($preview),
            ],
            'created_at' => now(),
        ]);

        Log::info('Preview generated', [
            'asset_id' => $asset->id,
            'has_preview' => !empty($preview),
        ]);

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Generate preview for asset (stub implementation).
     *
     * @param Asset $asset
     * @return array|null
     */
    protected function generatePreview(Asset $asset): ?array
    {
        // Stub implementation - future phase will add actual preview generation
        // Returns null for now
        return null;
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
