<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionReasonCode;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Enums\EvidenceWeight;
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
                reasonCode: DimensionReasonCode::COLOR_MISSING_BRAND_PALETTE,
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
                reasonCode: DimensionReasonCode::COLOR_MISSING_ASSET_PALETTE,
            );
        }

        if (! $evaluated) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::COLOR,
                'Palette evaluation could not run despite available data',
                [],
                EvidenceSource::PALETTE_EXTRACTION,
                $evidence,
                reasonCode: DimensionReasonCode::COLOR_EVALUATION_FAILED,
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
            if ($meanDeltaE !== null && $meanDeltaE <= 20.0) {
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
                reasonCode: DimensionReasonCode::COLOR_EVALUATED,
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
                reasonCode: DimensionReasonCode::COLOR_EVALUATED,
            );
        }

        if ($meanDeltaE !== null && $meanDeltaE <= 52.0) {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::PALETTE_EXTRACTION,
                'Extracted colors partially match brand palette' . $deltaLabel,
            );

            $score = $meanDeltaE <= 45.0 ? 0.55 : 0.40;
            return new DimensionResult(
                dimension: AlignmentDimension::COLOR,
                status: DimensionStatus::PARTIAL,
                score: $score,
                confidence: 0.70,
                primaryEvidenceSource: EvidenceSource::PALETTE_EXTRACTION,
                evidence: $evidence,
                blockers: ['Colors are close but not a strong palette match — verify intentional use'],
                evaluable: true,
                statusReason: 'Colors partially match brand palette' . $deltaLabel,
                reasonCode: DimensionReasonCode::COLOR_EVALUATED,
            );
        }

        $evidence[] = EvidenceItem::hard(
            EvidenceSource::PALETTE_EXTRACTION,
            'Extracted colors diverge from brand palette' . $deltaLabel,
        );

        return new DimensionResult(
            dimension: AlignmentDimension::COLOR,
            status: DimensionStatus::FAIL,
            score: 0.15,
            confidence: 0.70,
            primaryEvidenceSource: EvidenceSource::PALETTE_EXTRACTION,
            evidence: $evidence,
            blockers: ['Extracted colors diverge from brand palette. Verify creative uses approved colors'],
            evaluable: true,
            statusReason: 'Extracted colors diverge from brand palette' . $deltaLabel,
            reasonCode: DimensionReasonCode::COLOR_EVALUATED,
        );
    }

    /**
     * Fallback / augmentation using VLM `dominant_colors_visible`.
     *
     * Two modes:
     *  - Fallback: extractor had no asset colors → try VLM hexes. Capped strongly.
     *  - Augment: extractor ran fine → if VLM confirms brand primary/secondary is present,
     *    add a small soft bonus.
     *
     * @param  array<string, mixed>|null  $signals  creative_signals
     */
    public function applyCreativeSignals(DimensionResult $base, ?array $signals, Asset $asset, Brand $brand): DimensionResult
    {
        if ($signals === null) {
            return $base;
        }
        $rows = is_array($signals['dominant_colors_visible'] ?? null) ? $signals['dominant_colors_visible'] : [];
        if ($rows === []) {
            return $base;
        }

        if (! $base->evaluable && $base->reasonCode === DimensionReasonCode::COLOR_MISSING_ASSET_PALETTE) {
            return $this->fallbackFromVlm($rows, $brand, $base);
        }

        if ($base->evaluable && $base->reasonCode === DimensionReasonCode::COLOR_EVALUATED) {
            return $this->augmentWithVlm($rows, $brand, $base);
        }

        return $base;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fallbackFromVlm(array $rows, Brand $brand, DimensionResult $base): DimensionResult
    {
        $detail = $this->paletteEvaluator->evaluateWithProvidedAssetColors($brand, $rows);
        if (! ($detail['evaluated'] ?? false)) {
            return $base;
        }

        $aligned = (bool) ($detail['aligned'] ?? false);
        $opposite = (bool) ($detail['opposite_palette'] ?? false);
        $meanDeltaE = $detail['mean_min_delta_e'] ?? null;
        $deltaLabel = $meanDeltaE !== null ? sprintf(' (mean delta-E: %.0f)', $meanDeltaE) : '';

        $evidence = $base->evidence;

        if ($opposite) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::AI_ANALYSIS,
                'VLM-reported colors suggest opposing palette' . $deltaLabel,
            );

            return new DimensionResult(
                dimension: AlignmentDimension::COLOR,
                status: DimensionStatus::FAIL,
                score: 0.15,
                confidence: min(VlmSignalCaps::COLOR_VLM_FALLBACK_MAX_CONFIDENCE, 0.45),
                primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
                evidence: $evidence,
                blockers: ['Verify creative uses approved brand colors (VLM-inferred palette diverges)'],
                evaluable: true,
                statusReason: 'VLM-inferred colors diverge from brand palette' . $deltaLabel,
                reasonCode: DimensionReasonCode::COLOR_EVALUATED,
            );
        }

        if ($aligned) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::AI_ANALYSIS,
                'VLM-reported colors align with brand palette' . $deltaLabel,
            );

            $score = min(VlmSignalCaps::COLOR_VLM_FALLBACK_MAX_SCORE, $meanDeltaE !== null && $meanDeltaE <= 20.0 ? 0.75 : 0.65);
            $conf = min(VlmSignalCaps::COLOR_VLM_FALLBACK_MAX_CONFIDENCE, $meanDeltaE !== null && $meanDeltaE <= 20.0 ? 0.60 : 0.50);

            return new DimensionResult(
                dimension: AlignmentDimension::COLOR,
                status: DimensionStatus::PARTIAL,
                score: $score,
                confidence: $conf,
                primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
                evidence: $evidence,
                blockers: $base->blockers,
                evaluable: true,
                statusReason: 'VLM-inferred colors align with brand palette (fallback)' . $deltaLabel,
                reasonCode: DimensionReasonCode::COLOR_EVALUATED,
            );
        }

        if ($meanDeltaE !== null && $meanDeltaE <= 52.0) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::AI_ANALYSIS,
                'VLM-reported colors partially match brand palette' . $deltaLabel,
            );

            return new DimensionResult(
                dimension: AlignmentDimension::COLOR,
                status: DimensionStatus::PARTIAL,
                score: min(VlmSignalCaps::COLOR_VLM_FALLBACK_MAX_SCORE, 0.45),
                confidence: min(VlmSignalCaps::COLOR_VLM_FALLBACK_MAX_CONFIDENCE, 0.40),
                primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
                evidence: $evidence,
                blockers: ['Colors are close but not a strong match -- verify via higher-resolution preview'],
                evaluable: true,
                statusReason: 'VLM-inferred colors partially match brand palette' . $deltaLabel,
                reasonCode: DimensionReasonCode::COLOR_EVALUATED,
            );
        }

        return $base;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function augmentWithVlm(array $rows, Brand $brand, DimensionResult $base): DimensionResult
    {
        $branded = array_values(array_filter(
            $rows,
            static fn ($r) => is_array($r)
                && isset($r['role'])
                && in_array($r['role'], ['primary', 'secondary'], true),
        ));
        if ($branded === []) {
            return $base;
        }

        $matchedRoles = $this->paletteEvaluator->vlmBrandedRolesMatched($brand, $branded);
        if ($matchedRoles === []) {
            return $base;
        }

        $evidence = $base->evidence;
        $evidence[] = EvidenceItem::soft(
            EvidenceSource::AI_ANALYSIS,
            sprintf('VLM confirms brand %s color present on asset', implode(' & ', $matchedRoles)),
        );

        $newScore = min(
            VlmSignalCaps::COLOR_VLM_AUGMENT_FINAL_SCORE_CEILING,
            $base->score + VlmSignalCaps::COLOR_VLM_AUGMENT_SCORE_BONUS,
        );
        $newConf = min(
            VlmSignalCaps::COLOR_VLM_AUGMENT_FINAL_CONFIDENCE_CEILING,
            $base->confidence + VlmSignalCaps::COLOR_VLM_AUGMENT_CONFIDENCE_BONUS,
        );

        return new DimensionResult(
            dimension: AlignmentDimension::COLOR,
            status: $base->status,
            score: $newScore,
            confidence: $newConf,
            primaryEvidenceSource: $base->primaryEvidenceSource,
            evidence: $evidence,
            blockers: $base->blockers,
            evaluable: true,
            statusReason: $base->statusReason,
            reasonCode: DimensionReasonCode::COLOR_EVALUATED,
        );
    }
}
