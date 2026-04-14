<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\BrandIntelligence\BrandColorPaletteAlignmentEvaluator;

final class ColorEvaluator implements DimensionEvaluatorInterface
{
    public function __construct(
        private BrandColorPaletteAlignmentEvaluator $paletteEvaluator,
    ) {}

    public function evaluate(Asset $asset, Brand $brand, EvaluationContext $context): DimensionResult
    {
        $detail = $this->paletteEvaluator->evaluate($asset, $brand);
        $evidence = [];
        $blockers = [];

        $brandColorsAvailable = $detail['brand_colors_available'] ?? false;
        $assetColorsAvailable = $detail['asset_colors_available'] ?? false;
        $evaluated = $detail['evaluated'] ?? false;

        if (! $brandColorsAvailable) {
            return DimensionResult::missingReference(
                AlignmentDimension::COLOR,
                'No brand palette configured in brand DNA',
                ['Define brand colors in your brand model to enable palette comparison'],
            );
        }

        if (! $assetColorsAvailable) {
            $evidence[] = EvidenceItem::readiness(
                EvidenceSource::CONFIGURATION_ONLY,
                'Brand palette exists but no dominant colors extracted from asset',
            );

            return DimensionResult::notEvaluable(
                AlignmentDimension::COLOR,
                'Dominant colors were not extracted from this asset',
                ['Dominant color extraction required for palette comparison'],
                EvidenceSource::CONFIGURATION_ONLY,
                $evidence,
            );
        }

        if (! $evaluated) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::COLOR,
                'Palette evaluation could not run despite available data',
                [],
                EvidenceSource::PALETTE_EXTRACTION,
                $evidence,
            );
        }

        $aligned = (bool) ($detail['aligned'] ?? false);
        $oppositePalette = (bool) ($detail['opposite_palette'] ?? false);
        $meanDeltaE = $detail['mean_min_delta_e'] ?? null;
        $source = $detail['color_signal_source'] ?? 'global';

        $deltaLabel = $meanDeltaE !== null ? sprintf(' (mean delta-E: %.0f)', $meanDeltaE) : '';

        if ($aligned) {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::PALETTE_EXTRACTION,
                'Extracted colors align with brand palette' . $deltaLabel,
            );

            $score = 0.85;
            $confidence = 0.80;
            if ($meanDeltaE !== null && $meanDeltaE <= 18.0) {
                $score = 0.95;
                $confidence = 0.90;
            }

            return new DimensionResult(
                dimension: AlignmentDimension::COLOR,
                status: DimensionStatus::ALIGNED,
                score: $score,
                confidence: $confidence,
                primaryEvidenceSource: EvidenceSource::PALETTE_EXTRACTION,
                evidence: $evidence,
                blockers: $blockers,
                evaluable: true,
                statusReason: 'Extracted colors align with brand palette' . $deltaLabel,
            );
        }

        if ($oppositePalette) {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::PALETTE_EXTRACTION,
                'Asset uses opposing chromatic palette' . $deltaLabel,
            );

            return new DimensionResult(
                dimension: AlignmentDimension::COLOR,
                status: DimensionStatus::FAIL,
                score: 0.1,
                confidence: 0.75,
                primaryEvidenceSource: EvidenceSource::PALETTE_EXTRACTION,
                evidence: $evidence,
                blockers: ['Verify creative uses approved brand colors'],
                evaluable: true,
                statusReason: 'Asset colors oppose brand palette' . $deltaLabel,
            );
        }

        $evidence[] = EvidenceItem::hard(
            EvidenceSource::PALETTE_EXTRACTION,
            'Extracted colors diverge from brand palette' . $deltaLabel,
        );

        $score = 0.25;
        if ($meanDeltaE !== null && $meanDeltaE <= 42.0) {
            $score = 0.4;
        }

        return new DimensionResult(
            dimension: AlignmentDimension::COLOR,
            status: $score >= 0.35 ? DimensionStatus::WEAK : DimensionStatus::FAIL,
            score: $score,
            confidence: 0.70,
            primaryEvidenceSource: EvidenceSource::PALETTE_EXTRACTION,
            evidence: $evidence,
            blockers: ['Extracted colors diverge from brand palette. Verify creative uses approved colors'],
            evaluable: true,
            statusReason: 'Extracted colors diverge from brand palette' . $deltaLabel,
        );
    }
}
