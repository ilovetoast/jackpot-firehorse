<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\AssetProcessingFailureService;
use App\Services\ComputedMetadataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Computed Metadata Job
 *
 * Phase 5: Computes and persists technical metadata fields from asset files.
 *
 * Runs AFTER thumbnails are generated (needs file access).
 * Runs BEFORE AI metadata suggestions.
 */
class ComputedMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

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
    public function handle(ComputedMetadataService $service): void
    {
        $asset = Asset::findOrFail($this->assetId);

        // Skip if asset is not visible
        if ($asset->status !== AssetStatus::VISIBLE) {
            return;
        }

        // Idempotency: Check if already computed
        $metadata = $asset->metadata ?? [];
        if (isset($metadata['computed_metadata_completed']) && $metadata['computed_metadata_completed'] === true) {
            return;
        }

        try {
            // Compute metadata
            $service->computeMetadata($asset);

            // Mark as completed in metadata
            $metadata['computed_metadata_completed'] = true;
            $metadata['computed_metadata_completed_at'] = now()->toIso8601String();
            $asset->update(['metadata' => $metadata]);
        } catch (\Throwable $e) {
            Log::error('[ComputedMetadataJob] Failed to compute metadata', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark as failed but don't block processing
            $metadata['computed_metadata_failed'] = true;
            $metadata['computed_metadata_failed_at'] = now()->toIso8601String();
            $metadata['computed_metadata_error'] = $e->getMessage();
            $asset->update(['metadata' => $metadata]);

            // Don't re-throw - allow processing to continue
            // Computed metadata is non-critical
        }
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
