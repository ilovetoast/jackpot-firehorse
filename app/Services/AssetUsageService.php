<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Detects whether an asset id appears in any composition document JSON (current or version history)
 * or as a composition / version thumbnail for the brand.
 */
final class AssetUsageService
{
    /**
     * True if this asset id is still referenced: current document, any version snapshot, or list/history thumbnail.
     */
    public function isAssetReferencedInCompositions(string $assetId, int $tenantId, int $brandId): bool
    {
        if ($assetId === '') {
            return false;
        }

        if ($this->isThumbnailForBrandCompositions($assetId, $tenantId, $brandId)) {
            return true;
        }

        $needles = $this->documentJsonNeedles($assetId);

        $inCurrent = DB::table('compositions')
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where(function ($q) use ($needles) {
                foreach ($needles as $n) {
                    $q->orWhere('document_json', 'like', '%'.$n.'%');
                }
            })
            ->exists();

        if ($inCurrent) {
            return true;
        }

        return DB::table('composition_versions')
            ->join('compositions', 'composition_versions.composition_id', '=', 'compositions.id')
            ->where('compositions.tenant_id', $tenantId)
            ->where('compositions.brand_id', $brandId)
            ->where(function ($q) use ($needles) {
                foreach ($needles as $n) {
                    $q->orWhere('composition_versions.document_json', 'like', '%'.$n.'%');
                }
            })
            ->exists();
    }

    private function isThumbnailForBrandCompositions(string $assetId, int $tenantId, int $brandId): bool
    {
        if (DB::table('compositions')
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('thumbnail_asset_id', $assetId)
            ->exists()) {
            return true;
        }

        return DB::table('composition_versions')
            ->join('compositions', 'composition_versions.composition_id', '=', 'compositions.id')
            ->where('compositions.tenant_id', $tenantId)
            ->where('compositions.brand_id', $brandId)
            ->where('composition_versions.thumbnail_asset_id', $assetId)
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function documentJsonNeedles(string $assetId): array
    {
        return [
            '"assetId":"'.$assetId.'"',
            '"resultAssetId":"'.$assetId.'"',
            '"referenceAssetIds":["'.$assetId.'"',
            ',"'.$assetId.'"',
            '%referenceAssetIds%'.$assetId.'%',
        ];
    }
}
