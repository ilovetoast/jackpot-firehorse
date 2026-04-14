<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\AssetContextType;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Models\Asset;
use App\Models\Brand;

/**
 * Context Fit evaluates ONLY:
 *  - Is this asset appropriate for its expected category / use case?
 *  - Does it match the intended execution context?
 *  - Is the subject/format reasonable for the purpose?
 *
 * This must NOT become a catch-all for unexplained signals.
 * Every evidence item must have a specific, explainable source.
 */
final class ContextFitEvaluator implements DimensionEvaluatorInterface
{
    public function evaluate(Asset $asset, Brand $brand, EvaluationContext $context): DimensionResult
    {
        $evidence = [];
        $blockers = [];
        $contextType = $context->contextType;

        if ($contextType === AssetContextType::OTHER && ! $context->hasCampaignOverride) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::CONTEXT_FIT,
                'Asset context could not be classified and no campaign context is configured',
                ['Context fit was not evaluated for this asset'],
            );
        }

        $evidence[] = EvidenceItem::soft(
            EvidenceSource::AI_ANALYSIS,
            sprintf('Heuristic context classification: %s', $contextType->value),
        );

        if ($context->hasCampaignOverride) {
            $evidence[] = EvidenceItem::readiness(
                EvidenceSource::CONFIGURATION_ONLY,
                'Campaign context override is configured',
            );
        }

        $score = $this->scoreContextFit($contextType, $context);
        $confidence = $this->contextConfidence($contextType, $context);

        $hasAiEvidence = false;
        foreach ($evidence as $e) {
            if ($e->type === EvidenceSource::AI_ANALYSIS && $e->weight !== \App\Enums\EvidenceWeight::READINESS) {
                $hasAiEvidence = true;
            }
        }

        $status = $hasAiEvidence && $score >= 0.6 && $confidence >= 0.4
            ? DimensionStatus::PARTIAL
            : DimensionStatus::WEAK;

        return new DimensionResult(
            dimension: AlignmentDimension::CONTEXT_FIT,
            status: $status,
            score: $score,
            confidence: $confidence,
            primaryEvidenceSource: $hasAiEvidence ? EvidenceSource::AI_ANALYSIS : EvidenceSource::METADATA_HINT,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: true,
            statusReason: sprintf('Asset classified as %s; context assessment is approximate', $contextType->value),
        );
    }

    /**
     * Ingest creative intelligence context analysis from the parallel AI pass.
     */
    public function enrichWithCreativeIntelligence(DimensionResult $base, ?array $contextAnalysis): DimensionResult
    {
        if ($contextAnalysis === null) {
            return $base;
        }

        $aiContext = $contextAnalysis['context_type_ai'] ?? null;
        $mood = $contextAnalysis['mood'] ?? null;
        $sceneType = $contextAnalysis['scene_type'] ?? null;

        if ($aiContext === null && $mood === null) {
            return $base;
        }

        $evidence = $base->evidence;

        if (is_string($aiContext)) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::AI_ANALYSIS,
                sprintf('AI context classification: %s', $aiContext),
            );
        }
        if (is_string($mood)) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::AI_ANALYSIS,
                sprintf('Detected mood: %s', $mood),
            );
        }

        $confidence = min(1.0, $base->confidence + 0.15);

        return new DimensionResult(
            dimension: AlignmentDimension::CONTEXT_FIT,
            status: $base->status,
            score: $base->score,
            confidence: $confidence,
            primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
            evidence: $evidence,
            blockers: $base->blockers,
            evaluable: true,
            statusReason: $base->statusReason,
        );
    }

    private function scoreContextFit(AssetContextType $type, EvaluationContext $context): float
    {
        if ($type === AssetContextType::LOGO_ONLY) {
            return 0.5;
        }

        return 0.55;
    }

    private function contextConfidence(AssetContextType $type, EvaluationContext $context): float
    {
        if ($type === AssetContextType::OTHER) {
            return 0.2;
        }

        return $context->hasCampaignOverride ? 0.5 : 0.35;
    }
}
