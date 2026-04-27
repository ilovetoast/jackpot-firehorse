<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\AiMetadataSuggestionService;
use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Services\EmbeddedUsageRightsSuggestionService;
use App\Support\Logging\PipelineStepTimer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After embedded metadata is indexed, may suggest {@see usage_rights} from IPTC copyright notice etc.
 * Runs for every processed asset (no AI quota). Pairs with {@see AiMetadataSuggestionJob} merge logic.
 */
class EmbeddedUsageRightsSuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public $tries = 32;

    public int $maxExceptions = 1;

    public function __construct(
        public string $assetId
    ) {
        $this->configureImagesQueue();
    }

    public function handle(
        EmbeddedUsageRightsSuggestionService $embeddedService,
        AiMetadataSuggestionService $suggestionService
    ): void {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }

        if ($asset->status !== AssetStatus::VISIBLE) {
            return;
        }

        $timer = PipelineStepTimer::start('EmbeddedUsageRightsSuggestionJob', $this->assetId, null);
        $timer->lap('ready', $asset, null);
        $timer->lap('before_merge', $asset, null);
        $embeddedService->mergeEmbeddedUsageRightsIntoAsset($asset, $suggestionService);
        $timer->lap('after_merge', $asset, null);
    }
}
