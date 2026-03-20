<?php

namespace App\Listeners;

use App\Events\AssetUploaded;
use App\Services\BrandInsightLLM;

/**
 * Bust brand insight caches when asset upload completes.
 *
 * Runs synchronously so Overview signals + LLM copy refresh on the next request (not after the queue runs).
 */
class BustBrandInsightCache
{
    public function __construct(
        protected BrandInsightLLM $brandInsightLLM
    ) {}

    public function handle(AssetUploaded $event): void
    {
        $brand = $event->asset->brand;
        if ($brand) {
            $this->brandInsightLLM->bustCache($brand);
        }
    }
}
