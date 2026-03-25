<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Detects whether an asset id appears in any composition document JSON for a brand.
 */
final class AssetUsageService
{
    /**
     * True if this asset id appears in any composition's {@code document_json} for the tenant/brand
     * (layers may use {@code assetId} or {@code resultAssetId}).
     */
    public function isAssetReferencedInCompositions(string $assetId, int $tenantId, int $brandId): bool
    {
        if ($assetId === '') {
            return false;
        }

        // JSON encodes UUIDs as quoted strings — match both common layer keys.
        $needleAssetId = '"assetId":"'.$assetId.'"';
        $needleResult = '"resultAssetId":"'.$assetId.'"';

        return DB::table('compositions')
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where(function ($q) use ($needleAssetId, $needleResult) {
                $q->where('document_json', 'like', '%'.$needleAssetId.'%')
                    ->orWhere('document_json', 'like', '%'.$needleResult.'%');
            })
            ->exists();
    }
}
