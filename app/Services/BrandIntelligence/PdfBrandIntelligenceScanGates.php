<?php

namespace App\Services\BrandIntelligence;

use App\Enums\MediaType;
use App\Enums\PdfBrandIntelligenceScanMode;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Eligibility and future billing/plan hooks for PDF Brand Intelligence scan modes.
 */
final class PdfBrandIntelligenceScanGates
{
    /**
     * Enforce plan/credits for deep PDF BI. Called from HTTP dispatch and again from {@see ScoreAssetBrandIntelligenceJob}
     * so stale clients or manual queue pushes cannot bypass billing once implemented.
     *
     * TODO(billing): Deduct AI / EBI credits for deep PDF scans (idempotent debit keyed by job id).
     * TODO(billing): Enforce plan tier (e.g. advanced vs standard) when product SKUs define deep BI.
     * TODO(billing): Rate-limit deep rescans per tenant/day if abuse becomes a concern.
     */
    public static function assertMayDispatchDeepScan(?User $user, Asset $asset): void
    {
        // Intentionally empty — authorization remains at HTTP policy level; billing hooks land here later.
        unset($user, $asset);
    }

    /**
     * Worker-safe guard: only run as {@see PdfBrandIntelligenceScanMode::Deep} when PDF + multi-page rasters exist.
     * Otherwise downgrade to standard and log (defense in depth vs. forged queue payloads).
     */
    public static function resolveEffectivePdfScanModeInJob(Asset $asset, PdfBrandIntelligenceScanMode $requested): PdfBrandIntelligenceScanMode
    {
        if ($requested !== PdfBrandIntelligenceScanMode::Deep) {
            return $requested;
        }

        $eligibility = self::deepScanEligibility($asset);
        if (($eligibility['deep_scan_eligible'] ?? false) === true) {
            return PdfBrandIntelligenceScanMode::Deep;
        }

        Log::warning('[EBI] pdf_deep_scan_downgraded_in_job', [
            'asset_id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'raster_page_count' => $eligibility['raster_page_count'] ?? null,
        ]);

        return PdfBrandIntelligenceScanMode::Standard;
    }

    /**
     * @return array{deep_scan_eligible: bool, raster_page_count: int}
     */
    public static function deepScanEligibility(Asset $asset, ?VisualEvaluationSourceResolver $resolver = null): array
    {
        $n = self::rasterPageCount($asset, $resolver);

        return [
            'deep_scan_eligible' => self::isPdfRoot($asset) && $n > 1,
            'raster_page_count' => $n,
        ];
    }

    public static function rasterPageCount(Asset $asset, ?VisualEvaluationSourceResolver $resolver = null): int
    {
        if (! self::isPdfRoot($asset)) {
            return 0;
        }
        $resolver ??= app(VisualEvaluationSourceResolver::class);
        $rows = PdfBrandIntelligencePageRasterCatalog::discoverRastersByPage($asset, $resolver);

        return count($rows);
    }

    protected static function isPdfRoot(Asset $asset): bool
    {
        return MediaType::fromMime(strtolower((string) ($asset->mime_type ?? ''))) === MediaType::PDF;
    }
}
