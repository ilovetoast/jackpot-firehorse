<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionReasonCode;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Brand;
use Illuminate\Support\Str;

/**
 * Typography is intentionally conservative.
 *
 * Font extraction from rendered pixels is unreliable for most asset types.
 * configuration_only evidence (brand has font config) cannot produce aligned/partial status.
 * not_evaluable is preferred over fake certainty.
 * Typography must not penalize the score when not evaluable -- its weight is redistributed.
 */
final class TypographyEvaluator implements DimensionEvaluatorInterface
{
    public function evaluate(Asset $asset, Brand $brand, EvaluationContext $context): DimensionResult
    {
        $evidence = [];
        $blockers = [];

        $brandHasTypo = $this->brandHasTypographyConfig($brand);
        $assetHasTypoMetadata = $this->assetHasTypographyMetadata($asset);

        if (! $brandHasTypo && ! $assetHasTypoMetadata) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::TYPOGRAPHY,
                'No typography configuration in brand DNA and no font metadata on asset',
                ['Add typography settings to your brand guidelines'],
                reasonCode: DimensionReasonCode::TYPOGRAPHY_NO_BRAND_NO_ASSET,
            );
        }

        if ($brandHasTypo && ! $assetHasTypoMetadata) {
            $evidence[] = EvidenceItem::readiness(
                EvidenceSource::CONFIGURATION_ONLY,
                'Brand has typography configuration but no font data extractable from asset',
            );

            $canExtract = in_array($context->mediaType, [MediaType::PDF], true);

            if (! $canExtract) {
                return DimensionResult::notEvaluable(
                    AlignmentDimension::TYPOGRAPHY,
                    'Font extraction not available for this asset type',
                    ['Typography could not be reliably evaluated from this asset type'],
                    EvidenceSource::CONFIGURATION_ONLY,
                    $evidence,
                    reasonCode: DimensionReasonCode::TYPOGRAPHY_NO_ASSET_FONTS,
                );
            }

            return DimensionResult::notEvaluable(
                AlignmentDimension::TYPOGRAPHY,
                'Brand has typography config but no font metadata found on asset',
                ['Ensure asset has extractable font information'],
                EvidenceSource::CONFIGURATION_ONLY,
                $evidence,
                reasonCode: DimensionReasonCode::TYPOGRAPHY_PDF_FONTS_UNAVAILABLE,
            );
        }

        if ($assetHasTypoMetadata && ! $brandHasTypo) {
            $evidence[] = EvidenceItem::readiness(
                EvidenceSource::METADATA_HINT,
                'Asset has font metadata but no brand typography config to compare against',
            );

            return DimensionResult::missingReference(
                AlignmentDimension::TYPOGRAPHY,
                'Asset has font metadata but brand has no typography configuration',
                ['Add typography settings to your brand guidelines to enable comparison'],
                $evidence,
                reasonCode: DimensionReasonCode::TYPOGRAPHY_MISSING_BRAND_CONFIG,
            );
        }

        // Both exist -- we can attempt evaluation
        $evidence[] = EvidenceItem::soft(
            EvidenceSource::AI_ANALYSIS,
            'Typography configuration and asset metadata both present; comparison is approximate',
        );

        return new DimensionResult(
            dimension: AlignmentDimension::TYPOGRAPHY,
            status: DimensionStatus::PARTIAL,
            score: 0.5,
            confidence: 0.35,
            primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: true,
            statusReason: 'Typography comparison based on available metadata; confidence is limited',
            reasonCode: DimensionReasonCode::TYPOGRAPHY_EVALUATED,
        );
    }

    private function brandHasTypographyConfig(Brand $brand): bool
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $typo = is_array($payload['typography'] ?? null) ? $payload['typography'] : [];
        if (! empty($typo['primary_font']) || ! empty($typo['secondary_font'])) {
            return true;
        }
        $fonts = $typo['fonts'] ?? [];
        if (is_array($fonts) && count(array_filter($fonts)) > 0) {
            return true;
        }
        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];

        return ! empty($rules['typography_keywords']);
    }

    private function assetHasTypographyMetadata(Asset $asset): bool
    {
        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];
        $fields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];

        foreach (['font_family', 'fonts', 'typography', 'detected_fonts'] as $k) {
            if (! empty($meta[$k]) || ! empty($fields[$k])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upgrade a not_evaluable typography result using VLM type_classification
     * when the brand has font config but the asset has no readable fonts.
     *
     * Strictly capped (see VlmSignalCaps) and status is at most PARTIAL.
     *
     * @param  array<string, mixed>|null  $signals  creative_signals.type_classification
     */
    public function applyCreativeSignals(DimensionResult $base, ?array $signals, Brand $brand): DimensionResult
    {
        if ($base->evaluable || $signals === null) {
            return $base;
        }

        $relevantReasons = [
            DimensionReasonCode::TYPOGRAPHY_NO_ASSET_FONTS,
            DimensionReasonCode::TYPOGRAPHY_PDF_FONTS_UNAVAILABLE,
        ];
        if (! in_array($base->reasonCode, $relevantReasons, true)) {
            return $base;
        }

        $type = is_array($signals['type_classification'] ?? null) ? $signals['type_classification'] : null;
        if ($type === null) {
            return $base;
        }

        $category = is_string($type['primary_category'] ?? null) ? $type['primary_category'] : null;
        $confidence = is_numeric($type['confidence'] ?? null) ? (float) $type['confidence'] : 0.0;
        if ($category === null || $category === 'none' || $confidence < 0.5) {
            return $base;
        }

        $weight = is_string($type['weight_hint'] ?? null) ? $type['weight_hint'] : null;
        $allCaps = (bool) ($type['all_caps_detected'] ?? false);

        $brandTypo = $this->brandTypographyPayload($brand);
        $match = $this->compareTypeClassificationToBrand($category, $weight, $allCaps, $brandTypo);

        $score = min(VlmSignalCaps::TYPE_CLASSIFICATION_MAX_SCORE, $match['score']);
        $conf = min(VlmSignalCaps::TYPE_CLASSIFICATION_MAX_CONFIDENCE, $match['confidence']);

        if ($score < 0.25) {
            return $base;
        }

        $detail = sprintf(
            'VLM type classification: %s%s%s -- %s',
            $category,
            $weight ? '/' . $weight : '',
            $allCaps ? ', all-caps' : '',
            $match['reason'],
        );

        $evidence = $base->evidence;
        $evidence[] = EvidenceItem::soft(EvidenceSource::AI_ANALYSIS, $detail);

        return new DimensionResult(
            dimension: AlignmentDimension::TYPOGRAPHY,
            status: DimensionStatus::PARTIAL,
            score: $score,
            confidence: $conf,
            primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
            evidence: $evidence,
            blockers: $base->blockers,
            evaluable: true,
            statusReason: 'Typography estimated from visual font classification (no embedded fonts)',
            reasonCode: DimensionReasonCode::TYPOGRAPHY_EVALUATED,
        );
    }

    /**
     * @return array{fonts: list<string>, keywords: list<string>}
     */
    private function brandTypographyPayload(Brand $brand): array
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $typo = is_array($payload['typography'] ?? null) ? $payload['typography'] : [];

        $fonts = [];
        foreach (['primary_font', 'secondary_font'] as $k) {
            if (! empty($typo[$k]) && is_string($typo[$k])) {
                $fonts[] = Str::lower(trim($typo[$k]));
            }
        }
        if (is_array($typo['fonts'] ?? null)) {
            foreach ($typo['fonts'] as $f) {
                if (is_string($f) && $f !== '') {
                    $fonts[] = Str::lower(trim($f));
                }
            }
        }

        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];
        $keywords = [];
        if (is_array($rules['typography_keywords'] ?? null)) {
            foreach ($rules['typography_keywords'] as $k) {
                if (is_string($k) && $k !== '') {
                    $keywords[] = Str::lower(trim($k));
                }
            }
        }

        return ['fonts' => array_values(array_unique($fonts)), 'keywords' => array_values(array_unique($keywords))];
    }

    /**
     * @param  array{fonts: list<string>, keywords: list<string>}  $brandTypo
     * @return array{score: float, confidence: float, reason: string}
     */
    private function compareTypeClassificationToBrand(string $category, ?string $weight, bool $allCaps, array $brandTypo): array
    {
        $haystack = strtolower(implode(' ', array_merge($brandTypo['fonts'], $brandTypo['keywords'])));
        if ($haystack === '') {
            return ['score' => 0.40, 'confidence' => 0.30, 'reason' => 'no brand type keywords to match against'];
        }

        $categorySynonyms = match ($category) {
            'sans_serif' => ['sans', 'sans-serif', 'sans serif', 'grotesk', 'grotesque', 'gothic', 'neue', 'helvetica', 'inter', 'arial'],
            'serif' => ['serif', 'roman', 'times', 'garamond', 'baskerville', 'caslon'],
            'display' => ['display', 'headline', 'poster', 'title'],
            'monospace' => ['mono', 'monospace', 'courier', 'code'],
            'script' => ['script', 'handwritten', 'cursive'],
            'mixed' => [],
            default => [],
        };

        $categoryMatch = false;
        foreach ($categorySynonyms as $syn) {
            if (str_contains($haystack, $syn)) {
                $categoryMatch = true;
                break;
            }
        }

        $weightBonus = 0.0;
        if ($weight !== null && $weight !== 'unknown' && str_contains($haystack, $weight)) {
            $weightBonus = 0.10;
        }

        $capsBonus = 0.0;
        if ($allCaps && (str_contains($haystack, 'uppercase') || str_contains($haystack, 'all caps') || str_contains($haystack, 'all-caps'))) {
            $capsBonus = 0.05;
        }

        if ($categoryMatch) {
            return [
                'score' => 0.50 + $weightBonus + $capsBonus,
                'confidence' => 0.40,
                'reason' => 'matches brand typography family',
            ];
        }

        return [
            'score' => 0.30 + $weightBonus + $capsBonus,
            'confidence' => 0.30,
            'reason' => 'type category does not clearly match brand typography',
        ];
    }
}
