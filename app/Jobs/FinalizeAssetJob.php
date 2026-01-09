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

class FinalizeAssetJob implements ShouldQueue
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

        // Idempotency: Skip if already ready
        if ($asset->status === AssetStatus::READY) {
            Log::info('Asset finalization skipped - already ready', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Update status to READY
        $asset->update([
            'status' => AssetStatus::READY,
        ]);

        // Update metadata with finalization timestamp
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['finalized_at'] = now()->toIso8601String();
        $currentMetadata['processing_completed'] = true;

        $asset->update([
            'metadata' => $currentMetadata,
        ]);

        // Emit asset finalized event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null,
            'event_type' => 'asset.finalized',
            'metadata' => [
                'job' => 'FinalizeAssetJob',
                'status' => AssetStatus::READY->value,
            ],
            'created_at' => now(),
        ]);

        Log::info('Asset finalized', [
            'asset_id' => $asset->id,
            'file_name' => $asset->file_name,
            'status' => AssetStatus::READY->value,
        ]);
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
