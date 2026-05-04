<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Services\AssetProcessingFailureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Real tag candidates are created by {@see AiMetadataGenerationJob} on the `ai` queue.
 *             This class is kept so legacy queued payloads do not crash; it performs no work.
 */
class AITaggingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public $tries = 32;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly string $assetId
    ) {
        $this->configureImagesQueue();
    }

    public function handle(): void
    {
        Log::warning('[AITaggingJob] Deprecated no-op — use AiMetadataGenerationJob on the `ai` queue.', [
            'asset_id' => $this->assetId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            $metadata = $asset->metadata ?? [];
            $metadata['_ai_tagging_failed'] = true;
            $metadata['_ai_tagging_error'] = $exception->getMessage();
            $metadata['_ai_tagging_failed_at'] = now()->toIso8601String();
            $metadata['_ai_tagging_status'] = 'failed';
            $asset->update(['metadata' => $metadata]);

            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts(),
                true
            );
        }
    }
}
