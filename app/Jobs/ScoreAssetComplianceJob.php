<?php

namespace App\Jobs;

use App\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * @deprecated Legacy alias — forwards to {@see ScoreAssetBrandIntelligenceJob}.
 * Brand Compliance table scoring is retired; use Brand Intelligence only.
 */
class ScoreAssetComplianceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $assetId
    ) {}

    public function handle(): void
    {
        $asset = Asset::query()->find($this->assetId);
        if (! $asset || ! $asset->brand_id) {
            return;
        }

        ScoreAssetBrandIntelligenceJob::dispatch($asset);
    }
}
