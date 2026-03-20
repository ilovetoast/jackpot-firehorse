<?php

namespace App\Services\BrandDNA;

use App\Models\Asset;
use App\Models\Brand;

/**
 * Legacy deterministic Brand Compliance scoring has been removed.
 * {@see \App\Services\BrandIntelligence\BrandIntelligenceEngine} is the only scoring path.
 *
 * This class remains as a narrow stub so old type references resolve; it performs no scoring
 * and does not write to deprecated tables.
 */
class BrandComplianceService
{
    /**
     * @return null Scoring is handled exclusively by Brand Intelligence (EBI).
     */
    public function scoreAsset(Asset $asset, Brand $brand): ?array
    {
        return null;
    }

    /**
     * Previously wrote a compliance row for assets without raster thumbnails; no longer used.
     */
    public function upsertFileTypeUnsupported(Asset $asset, Brand $brand): void {}
}
