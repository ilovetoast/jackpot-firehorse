<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Enums\DerivativeProcessor;
use App\Enums\DerivativeType;
use App\Services\AssetDerivativeFailureService;
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
        Log::info('[GeneratePreviewJob] Job started', [
            'asset_id' => $this->assetId,
        ]);
        
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

        // Ensure thumbnails have been generated (check thumbnail_status, not asset status)
        // Asset.status remains UPLOADED throughout processing for visibility
        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            Log::warning('[GeneratePreviewJob] Preview generation skipped - thumbnails have not completed', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            ]);
            // CRITICAL: Don't return early - let chain continue even if preview can't be generated
            // The chain must continue to ComputedMetadataJob and other jobs
            // Just mark as skipped and continue
            $currentMetadata = $asset->metadata ?? [];
            $currentMetadata['preview_generated'] = false;
            $currentMetadata['preview_skipped'] = true;
            $currentMetadata['preview_skipped_reason'] = 'thumbnails_not_completed';
            $asset->update(['metadata' => $currentMetadata]);
            return; // Chain will continue automatically
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
            // preserveVisibility=true: derivative failure must never hide the asset
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts(),
                true
            );

            // Phase T-1: Record derivative failure for observability (never affects Asset.status)
            try {
                $processor = AssetDerivativeFailureService::inferProcessorFromException($exception);
                app(AssetDerivativeFailureService::class)->recordFailure(
                    $asset,
                    DerivativeType::PREVIEW,
                    $processor,
                    $exception
                );
            } catch (\Throwable $t1Ex) {
                \Illuminate\Support\Facades\Log::warning('[GeneratePreviewJob] AssetDerivativeFailureService recording failed', [
                    'asset_id' => $asset->id,
                    'error' => $t1Ex->getMessage(),
                ]);
            }
        }
    }
}
