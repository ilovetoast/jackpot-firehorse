<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\AssetContextType;
use App\Enums\DimensionReasonCode;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\BrandIntelligence\PeerCohortContextFitService;

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
            if ($context->mediaType === MediaType::PDF && $context->visualEvaluationRasterResolved) {
                $evidence = [
                    EvidenceItem::soft(
                        EvidenceSource::AI_ANALYSIS,
                        'Heuristic context is generic (other); PDF page render is available for approximate layout/format fit',
                    ),
                ];

                return new DimensionResult(
                    dimension: AlignmentDimension::CONTEXT_FIT,
                    status: DimensionStatus::WEAK,
                    score: 0.48,
                    confidence: 0.22,
                    primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
                    evidence: $evidence,
                    blockers: [],
                    evaluable: true,
                    statusReason: 'Unclear category context; PDF page preview enables a low-confidence placement/placement-style assessment',
                    reasonCode: DimensionReasonCode::CONTEXT_PDF_APPROXIMATE,
                );
            }

            return DimensionResult::notEvaluable(
                AlignmentDimension::CONTEXT_FIT,
                'Asset context could not be classified and no campaign context is configured',
                ['Context fit was not evaluated for this asset'],
                reasonCode: DimensionReasonCode::CONTEXT_UNCLASSIFIED,
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
            reasonCode: DimensionReasonCode::CONTEXT_EVALUATED,
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

        if ($aiContext === null && $mood === null && ! is_string($sceneType)) {
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
        if (is_string($sceneType) && trim($sceneType) !== '') {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::AI_ANALYSIS,
                sprintf('Detected scene / layout type: %s', trim($sceneType)),
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
            reasonCode: $base->reasonCode ?? DimensionReasonCode::CONTEXT_EVALUATED,
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

    /**
     * Upgrade an unclassified / approximate context result using VLM context_type.
     *
     * Strictly capped (see VlmSignalCaps). Does not fire for already-classified assets.
     *
     * @param  array<string, mixed>|null  $signals  creative_signals
     */
    public function applyCreativeSignals(DimensionResult $base, ?array $signals): DimensionResult
    {
        if ($signals === null) {
            return $base;
        }

        $eligibleReasons = [
            DimensionReasonCode::CONTEXT_UNCLASSIFIED,
            DimensionReasonCode::CONTEXT_PDF_APPROXIMATE,
        ];
        if (! in_array($base->reasonCode, $eligibleReasons, true)) {
            return $base;
        }

        $ctxType = is_string($signals['context_type'] ?? null) ? trim($signals['context_type']) : '';
        if ($ctxType === '') {
            return $base;
        }

        $resolved = AssetContextType::tryFrom($ctxType);
        if ($resolved === null || $resolved === AssetContextType::OTHER) {
            return $base;
        }

        $evidence = $base->evidence;
        $evidence[] = EvidenceItem::soft(
            EvidenceSource::AI_ANALYSIS,
            sprintf('VLM context classification: %s', $resolved->value),
        );

        $score = min(VlmSignalCaps::CONTEXT_VLM_MAX_SCORE, max($base->score, 0.55));
        $conf = min(VlmSignalCaps::CONTEXT_VLM_MAX_CONFIDENCE, max($base->confidence, 0.35));

        return new DimensionResult(
            dimension: AlignmentDimension::CONTEXT_FIT,
            status: DimensionStatus::PARTIAL,
            score: $score,
            confidence: $conf,
            primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
            evidence: $evidence,
            blockers: $base->blockers,
            evaluable: true,
            statusReason: sprintf('Context classified from VLM as %s (approximate)', $resolved->value),
            reasonCode: DimensionReasonCode::CONTEXT_EVALUATED,
        );
    }

    /**
     * Peer-cohort context fit fallback (Stage 8a).
     *
     * When the base result is still unclassified/approximate (no campaign override, VLM
     * didn't land a clean context_type), estimate fit by comparing this asset's embedding
     * against other assets in the same collection/category. This produces a single-family
     * REFERENCE_SIMILARITY signal that the AlignmentScoreDeriver will dampen appropriately
     * if it's the only evidence the dimension has.
     *
     * When the peer cohort can't be built (no category, too few peers, no signal above the
     * noise floor), emit a precise reason code so the UI can surface a targeted CTA
     * ("tag a category", "add more assets to this category") rather than generic "Not evaluated".
     */
    public function applyPeerCohortFallback(
        DimensionResult $base,
        Asset $asset,
        PeerCohortContextFitService $service,
    ): DimensionResult {
        // Only run when context is still unresolved — don't overwrite a real VLM or
        // campaign-override classification.
        $eligibleReasons = [
            DimensionReasonCode::CONTEXT_UNCLASSIFIED,
            DimensionReasonCode::CONTEXT_PDF_APPROXIMATE,
        ];
        if (! in_array($base->reasonCode, $eligibleReasons, true)) {
            return $base;
        }

        $result = $service->evaluate($asset);

        if (($result['ok'] ?? false) !== true) {
            return $this->applyPeerCohortFailure($base, $result);
        }

        $cohortSize = (int) ($result['cohort_size'] ?? 0);
        $median = (float) ($result['median_cosine'] ?? 0.0);
        $score = (float) ($result['score'] ?? 0.0);
        $confidence = (float) ($result['confidence'] ?? 0.0);
        $strength = (string) ($result['evidence_strength'] ?? 'soft');
        $cohortSource = (string) ($result['cohort_source'] ?? 'none');

        $detail = sprintf(
            'Peer-cohort fit: %.0f%% median cosine of top-%d (cohort=%d, source=%s)',
            $median * 100,
            min(PeerCohortContextFitService::TOP_K, count((array) ($result['top_k_cosines'] ?? []))),
            $cohortSize,
            $cohortSource,
        );

        $evidence = $base->evidence;
        $evidence[] = $strength === 'hard'
            ? EvidenceItem::hard(EvidenceSource::VISUAL_SIMILARITY, $detail)
            : EvidenceItem::soft(EvidenceSource::VISUAL_SIMILARITY, $detail);

        $status = match (true) {
            $score >= 0.6 && $strength === 'hard' => DimensionStatus::ALIGNED,
            $score >= 0.45 => DimensionStatus::PARTIAL,
            $score >= 0.25 => DimensionStatus::WEAK,
            default => DimensionStatus::FAIL,
        };

        return new DimensionResult(
            dimension: AlignmentDimension::CONTEXT_FIT,
            status: $status,
            score: $score,
            confidence: $confidence,
            primaryEvidenceSource: EvidenceSource::VISUAL_SIMILARITY,
            evidence: $evidence,
            blockers: $base->blockers,
            evaluable: true,
            statusReason: sprintf(
                'Peer-cohort fit from %d %s asset(s): %.0f%% similarity (approximate)',
                $cohortSize,
                $cohortSource === 'collection' ? 'execution' : ($cohortSource === 'both' ? 'execution+category' : 'category'),
                $median * 100,
            ),
            reasonCode: DimensionReasonCode::CONTEXT_PEER_COHORT_EVALUATED,
        );
    }

    /**
     * Map a peer-cohort failure to a precise DimensionResult with the reason code that best
     * describes what the user needs to do next.
     *
     * @param  array<string, mixed>  $result
     */
    private function applyPeerCohortFailure(DimensionResult $base, array $result): DimensionResult
    {
        $reason = (string) ($result['reason'] ?? '');
        $cohortSize = (int) ($result['cohort_size'] ?? 0);

        // missing_embedding: same problem as Style's STYLE_EMBEDDING_MISSING. Leave the base
        // CONTEXT_UNCLASSIFIED in place — the embedding-missing CTA will come through the
        // visual_style pillar anyway and we don't want to emit duplicate reason codes here.
        if ($reason === 'missing_embedding') {
            return $base;
        }

        if ($reason === 'no_category') {
            return DimensionResult::notEvaluable(
                AlignmentDimension::CONTEXT_FIT,
                'Asset is not tagged to a category — no peer cohort available for context scoring',
                ['Tag this asset with a category to enable peer-based context scoring'],
                reasonCode: DimensionReasonCode::CONTEXT_NO_CATEGORY,
            );
        }

        if ($reason === 'cohort_too_small') {
            return DimensionResult::notEvaluable(
                AlignmentDimension::CONTEXT_FIT,
                sprintf(
                    'Only %d peer asset(s) in this category have embeddings — need at least %d for a stable peer-cohort score',
                    $cohortSize,
                    PeerCohortContextFitService::MIN_PEER_COHORT,
                ),
                ['Add more assets to this category (or collection) to enable peer-based context scoring'],
                reasonCode: DimensionReasonCode::CONTEXT_COHORT_TOO_SMALL,
            );
        }

        if ($reason === 'cohort_no_signal') {
            return DimensionResult::notEvaluable(
                AlignmentDimension::CONTEXT_FIT,
                'This asset is visually unlike any scored peer in its category',
                ['Confirm the asset is in the right category, or add representative style references'],
                reasonCode: DimensionReasonCode::CONTEXT_COHORT_NO_SIGNAL,
            );
        }

        return $base;
    }
}
