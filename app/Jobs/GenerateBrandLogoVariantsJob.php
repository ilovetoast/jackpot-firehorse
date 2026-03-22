<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Services\BrandDNA\BrandLogoVariantAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Idempotent: {@see BrandLogoVariantAutomationService} skips slots that already have assets.
 */
class GenerateBrandLogoVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public int $brandId,
        public int $brandModelVersionId
    ) {}

    public function handle(BrandLogoVariantAutomationService $automation): void
    {
        $brand = Brand::query()->find($this->brandId);
        $version = BrandModelVersion::query()->find($this->brandModelVersionId);
        if (! $brand || ! $version) {
            return;
        }

        $version->loadMissing('brandModel');
        if (! $version->brandModel || (int) $version->brandModel->brand_id !== (int) $brand->id) {
            return;
        }

        $automation->sync($brand, $version);
    }
}
