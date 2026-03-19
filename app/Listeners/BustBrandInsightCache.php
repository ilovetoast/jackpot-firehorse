<?php

namespace App\Listeners;

use App\Events\AssetUploaded;
use App\Services\BrandInsightLLM;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Bust BrandInsightLLM cache when asset upload completes.
 *
 * Ensures insights feel alive, not stale.
 */
class BustBrandInsightCache implements ShouldQueue
{
    public function __construct(
        protected BrandInsightLLM $brandInsightLLM
    ) {
    }

    public function handle(AssetUploaded $event): void
    {
        $brand = $event->asset->brand;
        if ($brand) {
            $this->brandInsightLLM->bustCache($brand);
        }
    }
}
