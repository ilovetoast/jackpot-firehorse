<?php

namespace App\Observers;

use App\Models\BrandModelVersion;
use App\Services\BrandDNA\BrandFontLibrarySyncService;

/**
 * Keeps the Fonts library category in sync when Brand Guidelines typography changes.
 */
class BrandModelVersionObserver
{
    public function saved(BrandModelVersion $version): void
    {
        if (! $version->wasChanged('model_payload')) {
            return;
        }

        $version->loadMissing('brandModel.brand');
        $brand = $version->brandModel?->brand;
        if (! $brand) {
            return;
        }

        app(BrandFontLibrarySyncService::class)->syncFromVersion($brand, $version);
    }
}
