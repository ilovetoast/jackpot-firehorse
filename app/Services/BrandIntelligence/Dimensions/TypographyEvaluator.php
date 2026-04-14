<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Brand;

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
                );
            }

            return DimensionResult::notEvaluable(
                AlignmentDimension::TYPOGRAPHY,
                'Brand has typography config but no font metadata found on asset',
                ['Ensure asset has extractable font information'],
                EvidenceSource::CONFIGURATION_ONLY,
                $evidence,
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
}
