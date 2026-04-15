<?php

namespace App\Services\BrandIntelligence;

use App\Enums\AlignmentDimension;
use App\Enums\BrandAlignmentState;
use App\Enums\MediaType;
use App\Enums\PdfBrandIntelligenceScanMode;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Centralized "suggested deep scan" heuristics after a standard PDF Brand Intelligence run.
 */
final class PdfBrandIntelligenceDeepScanRecommendation
{
    /**
     * @param  array<string, mixed>  $breakdown  Assembled breakdown_json (post-dimension evaluation).
     * @return array{
     *     deep_scan_recommended: bool,
     *     deep_scan_recommendation_reason: ?string,
     *     additional_pdf_pages_available: bool,
     *     additional_pdf_pages_count: int,
     *     raster_page_count: int,
     *     pdf_scan_mode_used: string,
     *     deep_scan_eligible: bool
     * }
     */
    public static function evaluate(
        Asset $asset,
        PdfBrandIntelligenceScanMode $modeUsed,
        array $breakdown,
        ?VisualEvaluationSourceResolver $resolver = null,
    ): array {
        $resolver ??= app(VisualEvaluationSourceResolver::class);
        $rasterCount = PdfBrandIntelligenceScanGates::rasterPageCount($asset, $resolver);
        $isPdf = MediaType::fromMime(strtolower((string) ($asset->mime_type ?? ''))) === MediaType::PDF;

        $additionalAvailable = $isPdf && $rasterCount > 1;
        $additionalCount = $isPdf ? max(0, $rasterCount - 1) : 0;

        $base = [
            'deep_scan_recommended' => false,
            'deep_scan_recommendation_reason' => null,
            'additional_pdf_pages_available' => $additionalAvailable,
            'additional_pdf_pages_count' => $additionalCount,
            'raster_page_count' => $rasterCount,
            'pdf_scan_mode_used' => $isPdf ? $modeUsed->value : 'not_applicable',
            'deep_scan_eligible' => $additionalAvailable,
        ];

        if (! $isPdf || $rasterCount <= 1) {
            return $base;
        }

        if ($modeUsed === PdfBrandIntelligenceScanMode::Deep) {
            return $base;
        }

        $weak = false;

        if (($breakdown['alignment_state'] ?? '') === BrandAlignmentState::INSUFFICIENT_EVIDENCE->value) {
            $weak = true;
        }
        if (($breakdown['insufficient_signal'] ?? false) === true) {
            $weak = true;
        }

        $conf = (float) ($breakdown['confidence'] ?? 1.0);
        if ($conf < 0.55) {
            $weak = true;
        }

        $oc = $breakdown['overall_confidence'] ?? null;
        if ($oc !== null && is_numeric($oc) && (float) $oc < 0.45) {
            $weak = true;
        }

        $ep = $breakdown['evaluable_proportion'] ?? null;
        if ($ep !== null && is_numeric($ep) && (float) $ep < 0.4) {
            $weak = true;
        }

        $watch = [
            AlignmentDimension::VISUAL_STYLE->value,
            AlignmentDimension::IDENTITY->value,
            AlignmentDimension::COPY_VOICE->value,
            AlignmentDimension::CONTEXT_FIT->value,
        ];
        $notEvaluable = 0;
        $contextFitWeak = false;
        foreach ($breakdown['dimensions'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $dim = (string) ($row['dimension'] ?? '');
            if (in_array($dim, $watch, true) && ($row['evaluable'] ?? true) === false) {
                $notEvaluable++;
            }
            if ($dim === AlignmentDimension::CONTEXT_FIT->value) {
                $c = isset($row['confidence']) && is_numeric($row['confidence']) ? (float) $row['confidence'] : 1.0;
                if (($row['evaluable'] ?? false) === true && $c < 0.38) {
                    $contextFitWeak = true;
                }
            }
        }
        if ($notEvaluable >= 2) {
            $weak = true;
        }
        if ($contextFitWeak) {
            $weak = true;
        }

        $recommended = $weak && $additionalAvailable;

        if ($recommended) {
            Log::info('[EBI] pdf_deep_scan_suggested', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'brand_id' => $asset->brand_id,
                'additional_pdf_pages_count' => $additionalCount,
                'raster_page_count' => $rasterCount,
                'pdf_scan_mode_used' => $modeUsed->value,
            ]);
        }

        return array_merge($base, [
            'deep_scan_recommended' => $recommended,
            'deep_scan_recommendation_reason' => $recommended
                ? 'More pages are available. A deeper scan may improve style, copy, and context detection.'
                : null,
        ]);
    }
}
