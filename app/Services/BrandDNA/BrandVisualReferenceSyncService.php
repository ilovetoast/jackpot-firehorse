<?php

namespace App\Services\BrandDNA;

use App\Jobs\GenerateAssetEmbeddingJob;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandVisualReference;
use App\Models\BrandModelVersionAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Syncs visual references from brand_model_version_assets (version pivot)
 * to brand_visual_references (embedding source).
 *
 * Single source of truth: brand_model_version_assets.
 * brand_visual_references is a projection for embeddings.
 */
class BrandVisualReferenceSyncService
{
    public function syncFromVersion(BrandModelVersion $version): void
    {
        $brand = $version->brandModel?->brand;
        if (! $brand) {
            return;
        }

        $pivotRows = BrandModelVersionAsset::where('brand_model_version_id', $version->id)
            ->where('builder_context', 'visual_reference')
            ->get();

        DB::transaction(function () use ($brand, $pivotRows) {
            BrandVisualReference::where('brand_id', $brand->id)->delete();

            foreach ($pivotRows as $row) {
                $type = $this->mapReferenceType($row->reference_type);
                $create = [
                    'brand_id' => $brand->id,
                    'asset_id' => $row->asset_id,
                    'embedding_vector' => null,
                    'type' => $type,
                ];
                if (Schema::hasColumn('brand_visual_references', 'reference_type')) {
                    $create['reference_type'] = $type === BrandVisualReference::TYPE_LOGO
                        ? BrandVisualReference::REFERENCE_TYPE_IDENTITY
                        : BrandVisualReference::REFERENCE_TYPE_STYLE;
                }
                if (Schema::hasColumn('brand_visual_references', 'reference_tier')) {
                    $create['reference_tier'] = BrandVisualReference::TIER_GUIDELINE;
                }
                if (Schema::hasColumn('brand_visual_references', 'weight')) {
                    $create['weight'] = BrandVisualReference::TIER_WEIGHT_DEFAULTS[BrandVisualReference::TIER_GUIDELINE];
                }
                $ref = BrandVisualReference::create($create);
                GenerateAssetEmbeddingJob::dispatch($row->asset_id, $ref->id)->onQueue(config('queue.images_queue', 'images'));
            }
        });
    }

    public function syncFromActiveVersion(Brand $brand): void
    {
        $activeVersion = $brand->brandModel?->activeVersion;
        if (! $activeVersion) {
            BrandVisualReference::where('brand_id', $brand->id)->delete();
            return;
        }

        $this->syncFromVersion($activeVersion);
    }

    protected function mapReferenceType(?string $referenceType): string
    {
        $valid = [
            BrandVisualReference::TYPE_LOGO,
            BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE,
            BrandVisualReference::TYPE_LIFESTYLE_PHOTOGRAPHY,
            BrandVisualReference::TYPE_PRODUCT_PHOTOGRAPHY,
            BrandVisualReference::TYPE_GRAPHICS_LAYOUT,
        ];
        if ($referenceType && in_array($referenceType, $valid, true)) {
            return $referenceType;
        }
        return BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE;
    }
}
