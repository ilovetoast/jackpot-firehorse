<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\ComputedMetadataService;
use App\Jobs\Concerns\QueuesOnImagesChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async equivalent of AssetController::regenerateSystemMetadata — computeMetadata + PopulateAutomaticMetadataJob.
 */
class RegenerateSystemMetadataQueuedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public $tries = 8;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly string $assetId
    ) {
        $this->configureImagesQueue();
    }

    public function handle(ComputedMetadataService $computedMetadataService): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }
        if ($asset->status !== AssetStatus::VISIBLE) {
            return;
        }
        if (! $asset->storage_root_path || ! $asset->storageBucket) {
            Log::info('[RegenerateSystemMetadataQueuedJob] Skipping — no storage', ['asset_id' => $this->assetId]);

            return;
        }

        try {
            $computedMetadataService->computeMetadata($asset);
        } catch (\Throwable $e) {
            Log::warning('[RegenerateSystemMetadataQueuedJob] computeMetadata failed', [
                'asset_id' => $this->assetId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        PopulateAutomaticMetadataJob::dispatch($this->assetId)->onQueue(config('queue.images_queue', 'images'));
    }
}
