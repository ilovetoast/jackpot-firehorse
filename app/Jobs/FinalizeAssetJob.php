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

        // Idempotency: Skip if already completed
        if ($asset->status === AssetStatus::COMPLETED) {
            Log::info('Asset finalization skipped - already completed', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Only finalize assets that have completed AI tagging
        if ($asset->status !== AssetStatus::AI_TAGGED) {
            Log::warning('Asset finalization skipped - asset has not completed AI tagging', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Update status to COMPLETED
        $asset->update([
            'status' => AssetStatus::COMPLETED,
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
                'status' => AssetStatus::COMPLETED->value,
            ],
            'created_at' => now(),
        ]);

        Log::info('Asset finalized', [
            'asset_id' => $asset->id,
            'original_filename' => $asset->original_filename,
            'status' => AssetStatus::COMPLETED->value,
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
