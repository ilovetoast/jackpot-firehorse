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

class ExtractMetadataJob implements ShouldQueue
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

        // Idempotency: Check if metadata already extracted
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['metadata_extracted']) && $existingMetadata['metadata_extracted'] === true) {
            Log::info('Metadata extraction skipped - already extracted', [
                'asset_id' => $asset->id,
            ]);
            // Job chaining is handled by Bus::chain() in ProcessAssetJob
            // Chain will continue to next job automatically
            return;
        }

        // Ensure asset is in PROCESSING status (set by ProcessAssetJob)
        if ($asset->status !== AssetStatus::PROCESSING) {
            Log::warning('Metadata extraction skipped - asset is not in processing state', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Extract metadata (stub implementation)
        $metadata = $this->extractMetadata($asset);

        // Update asset metadata
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['metadata_extracted'] = true;
        $currentMetadata['extracted_at'] = now()->toIso8601String();
        $currentMetadata['metadata'] = $metadata;

        $asset->update([
            'metadata' => $currentMetadata,
        ]);

        // Emit metadata extracted event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null,
            'event_type' => 'asset.metadata.extracted',
            'metadata' => [
                'job' => 'ExtractMetadataJob',
                'metadata_keys' => array_keys($metadata),
            ],
            'created_at' => now(),
        ]);

        Log::info('Metadata extracted', [
            'asset_id' => $asset->id,
            'metadata_keys' => array_keys($metadata),
        ]);

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Extract metadata from asset (stub implementation).
     *
     * @param Asset $asset
     * @return array
     */
    protected function extractMetadata(Asset $asset): array
    {
        // Stub implementation - future phase will add actual metadata extraction
        // For now, return basic metadata from file properties
        return [
            'original_filename' => $asset->original_filename,
            'size_bytes' => $asset->size_bytes,
            'mime_type' => $asset->mime_type,
            'extracted_by' => 'stub',
        ];
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
