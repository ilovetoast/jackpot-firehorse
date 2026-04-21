<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\Assets\ImageFocalPointAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue worker: AI focal point for photography (tenant toggle + category, or manual rerun).
 */
class ComputeImageFocalPointJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public string $assetId;

    public bool $force = false;

    public ?int $triggeredByUserId = null;

    public function __construct(string $assetId, bool $force = false, ?int $triggeredByUserId = null)
    {
        $this->assetId = $assetId;
        $this->force = $force;
        $this->triggeredByUserId = $triggeredByUserId;
        $this->onQueue(config('queue.images_queue', 'images'));
    }

    public function handle(ImageFocalPointAiService $service): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }

        try {
            $service->computeAndStoreIfEligible($asset, $this->force, $this->triggeredByUserId);
        } catch (\Throwable $e) {
            Log::warning('[ComputeImageFocalPointJob] Failed', [
                'asset_id' => $this->assetId,
                'force' => $this->force,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
