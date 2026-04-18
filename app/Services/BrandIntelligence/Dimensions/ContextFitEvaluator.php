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
use App\Models\CampaignVisualReference;
use App\Models\CollectionCampaignIdentity;
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

    /**
     * Pass A -- campaign-context overlay.
     *
     * When the asset lives in a collection whose {@see CollectionCampaignIdentity} is scorable,
     * blend the live campaign DNA (goal, description, tone, required motifs, exemplar refs)
     * with the VLM/heuristic context signals. This is additive on top of
     * {@see self::applyCreativeSignals()} and {@see self::applyPeerCohortFallback()}.
     *
     * Rules:
     *  - Never downgrades a result to non-evaluable.
     *  - Score adjustment capped at +0.20 on match, -0.15 on mismatch.
     *  - Confidence is lifted up to 0.65 when both textual (goal/description) and visual
     *    (exemplar references) campaign evidence is present. Without VLM signals, score
     *    moves are half-weighted (read: "we believe the campaign is configured, but we
     *    can't see the pixels" stays humble).
     *  - Emits CONFIGURATION_ONLY + VISUAL_SIMILARITY evidence so the SignalFamily
     *    diversity tracker sees two families, reducing dampening for this dimension.
     *
     * @param  array<string, mixed>|null  $creativeSignals  breakdown.creative_signals
     */
    public function applyCampaignContext(
        DimensionResult $base,
        Asset $asset,
        CollectionCampaignIdentity $campaignIdentity,
        ?array $creativeSignals,
    ): DimensionResult {
        $payload = is_array($campaignIdentity->identity_payload) ? $campaignIdentity->identity_payload : [];

        $goal = trim((string) ($campaignIdentity->campaign_goal ?? ''));
        $description = trim((string) ($campaignIdentity->campaign_description ?? ''));
        $tone = trim((string) (data_get($payload, 'messaging.tone') ?? ''));
        $styleDescription = trim((string) (data_get($payload, 'visual.style_description') ?? ''));
        $requiredMotifs = $this->asStringList(data_get($payload, 'rules.required_motifs'));
        $categoryNotes = trim((string) (data_get($payload, 'rules.category_notes') ?? ''));

        $hasTextualDna = $goal !== ''
            || $description !== ''
            || $tone !== ''
            || $styleDescription !== ''
            || $categoryNotes !== ''
            || $requiredMotifs !== [];

        $exemplarCosine = $this->bestExemplarCosine($asset, $campaignIdentity);
        $hasVisualDna = $exemplarCosine !== null;

        if (! $hasTextualDna && ! $hasVisualDna) {
            return $base;
        }

        $vlmContext = $this->pickVlmContextText($creativeSignals);

        $alignmentDelta = 0.0;
        $matchHits = [];
        $mismatchHits = [];

        if ($hasTextualDna && $vlmContext !== '') {
            $campaignKeywords = $this->campaignKeywords(
                $goal,
                $description,
                $tone,
                $styleDescription,
                $categoryNotes,
                $requiredMotifs,
            );

            foreach ($campaignKeywords as $phrase) {
                if ($phrase === '') {
                    continue;
                }
                if (mb_stripos($vlmContext, $phrase) !== false) {
                    $matchHits[] = $phrase;
                    if (count($matchHits) >= 4) {
                        break;
                    }
                }
            }

            $mismatchHits = $this->campaignMismatches($payload, $vlmContext);
        }

        if ($matchHits !== []) {
            $alignmentDelta += min(0.20, 0.06 * count($matchHits));
        }
        if ($mismatchHits !== []) {
            $alignmentDelta -= min(0.15, 0.06 * count($mismatchHits));
        }

        if ($hasVisualDna) {
            // $exemplarCosine lives in [0, 1]. Map [0.5, 0.85] -> [-0.08, +0.12] so a weak
            // similarity mildly penalizes and a strong one moderately lifts the score. Clamp
            // so the total exemplar effect fits within the overall per-overlay cap.
            $exemplarAdj = max(-0.08, min(0.12, (($exemplarCosine - 0.65) / 0.20) * 0.10));
            $alignmentDelta += $exemplarAdj;
        }

        if ($vlmContext === '' && $hasTextualDna) {
            // No VLM to anchor on -- only lift by half to avoid rewarding a configuration
            // that we cannot visually verify.
            $alignmentDelta *= 0.5;
        }

        $alignmentDelta = max(-0.15, min(0.20, $alignmentDelta));

        $evidence = $base->evidence;

        if ($hasTextualDna) {
            $descriptor = $goal !== '' ? $goal : ($description !== '' ? $description : ($styleDescription !== '' ? $styleDescription : 'campaign DNA'));
            $evidence[] = EvidenceItem::readiness(
                EvidenceSource::CONFIGURATION_ONLY,
                sprintf('Campaign context "%s"', \Illuminate\Support\Str::limit($descriptor, 80)),
            );

            if ($matchHits !== []) {
                $evidence[] = EvidenceItem::soft(
                    EvidenceSource::AI_ANALYSIS,
                    sprintf(
                        'Asset description aligns with campaign keywords: %s',
                        implode(', ', array_slice($matchHits, 0, 3)),
                    ),
                );
            }

            if ($mismatchHits !== []) {
                $evidence[] = EvidenceItem::soft(
                    EvidenceSource::AI_ANALYSIS,
                    sprintf(
                        'Asset description contradicts campaign direction: %s',
                        implode(', ', array_slice($mismatchHits, 0, 3)),
                    ),
                );
            }
        }

        if ($hasVisualDna) {
            $exemplarStrength = $exemplarCosine >= 0.75
                ? EvidenceItem::hard(
                    EvidenceSource::VISUAL_SIMILARITY,
                    sprintf('Campaign exemplar similarity %.0f%%', $exemplarCosine * 100),
                )
                : EvidenceItem::soft(
                    EvidenceSource::VISUAL_SIMILARITY,
                    sprintf('Campaign exemplar similarity %.0f%% (below strong-match threshold)', $exemplarCosine * 100),
                );
            $evidence[] = $exemplarStrength;
        }

        $hasMatch = $matchHits !== [] || ($hasVisualDna && $exemplarCosine >= 0.70);
        $hasMismatch = $mismatchHits !== [] || ($hasVisualDna && $exemplarCosine < 0.45);

        if ($vlmContext === '' && ! $hasVisualDna) {
            $reasonCode = DimensionReasonCode::CONTEXT_CAMPAIGN_NO_VLM;
        } elseif ($hasMatch && ! $hasMismatch) {
            $reasonCode = DimensionReasonCode::CONTEXT_CAMPAIGN_ALIGNED;
        } elseif ($hasMismatch && ! $hasMatch) {
            $reasonCode = DimensionReasonCode::CONTEXT_CAMPAIGN_MISALIGNED;
        } else {
            // Neutral or mixed overlay -- keep the base reason code so downstream UX doesn't
            // claim alignment we haven't established.
            $reasonCode = $base->reasonCode ?? DimensionReasonCode::CONTEXT_EVALUATED;
        }

        $score = max(0.0, min(1.0, $base->score + $alignmentDelta));
        $confidenceBoost = 0.0;
        if ($hasTextualDna && $hasVisualDna) {
            $confidenceBoost = 0.20;
        } elseif ($hasTextualDna || $hasVisualDna) {
            $confidenceBoost = 0.10;
        }
        $confidence = max($base->confidence, min(0.65, $base->confidence + $confidenceBoost));

        if (! $base->evaluable && ($hasMatch || $hasVisualDna)) {
            $status = $score >= 0.6 ? DimensionStatus::PARTIAL : DimensionStatus::WEAK;
            $evaluable = true;
        } else {
            $status = $base->status;
            $evaluable = $base->evaluable;
        }

        if ($evaluable && $status === DimensionStatus::NOT_EVALUABLE) {
            $status = DimensionStatus::WEAK;
        }

        if ($evaluable && $hasMatch && $score >= 0.65 && $confidence >= 0.5) {
            $status = DimensionStatus::ALIGNED;
        }

        $statusReason = match ($reasonCode) {
            DimensionReasonCode::CONTEXT_CAMPAIGN_ALIGNED => sprintf(
                'Context fits the active campaign (%s)',
                \Illuminate\Support\Str::limit($campaignIdentity->campaign_name ?? 'campaign', 40),
            ),
            DimensionReasonCode::CONTEXT_CAMPAIGN_MISALIGNED => sprintf(
                'Context diverges from the active campaign (%s)',
                \Illuminate\Support\Str::limit($campaignIdentity->campaign_name ?? 'campaign', 40),
            ),
            DimensionReasonCode::CONTEXT_CAMPAIGN_NO_VLM => 'Campaign context configured but no VLM read from the asset',
            default => $base->statusReason,
        };

        return new DimensionResult(
            dimension: AlignmentDimension::CONTEXT_FIT,
            status: $status,
            score: $score,
            confidence: $confidence,
            primaryEvidenceSource: $hasVisualDna
                ? EvidenceSource::VISUAL_SIMILARITY
                : ($hasMatch || $hasMismatch ? EvidenceSource::AI_ANALYSIS : $base->primaryEvidenceSource),
            evidence: $evidence,
            blockers: $base->blockers,
            evaluable: $evaluable,
            statusReason: $statusReason,
            reasonCode: $reasonCode,
        );
    }

    /**
     * Pull any VLM-derived contextual text from the creative_signals payload so we can
     * keyword-match against campaign DNA.
     *
     * @param  array<string, mixed>|null  $signals
     */
    private function pickVlmContextText(?array $signals): string
    {
        if ($signals === null) {
            return '';
        }

        $parts = [];
        foreach (['context_type', 'scene_type', 'mood'] as $key) {
            $val = $signals[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                $parts[] = mb_strtolower($val);
            }
        }

        foreach (['visual_style', 'tags', 'motifs'] as $key) {
            $val = $signals[$key] ?? null;
            if (is_array($val)) {
                foreach ($val as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        $parts[] = mb_strtolower($entry);
                    }
                }
            }
        }

        // Video insights override/extension lives under `video_context.*`; it's already string-y.
        $videoCtx = $signals['video_context'] ?? null;
        if (is_array($videoCtx)) {
            foreach (['summary', 'scene', 'activity', 'setting'] as $k) {
                $val = $videoCtx[$k] ?? null;
                if (is_string($val) && trim($val) !== '') {
                    $parts[] = mb_strtolower($val);
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Build a keyword list from campaign DNA for simple substring matching against the VLM text.
     *
     * @param  list<string>  $requiredMotifs
     * @return list<string>
     */
    private function campaignKeywords(
        string $goal,
        string $description,
        string $tone,
        string $styleDescription,
        string $categoryNotes,
        array $requiredMotifs,
    ): array {
        $keywords = [];

        foreach ([$goal, $description, $styleDescription, $categoryNotes] as $sentence) {
            if ($sentence === '') {
                continue;
            }
            // Pull distinctive words >= 4 chars so "a", "the" aren't treated as matches.
            foreach (preg_split('/[\s,;:.\\/\\-]+/u', mb_strtolower($sentence)) ?: [] as $token) {
                $token = trim($token);
                if ($token === '' || mb_strlen($token) < 4) {
                    continue;
                }
                if (in_array($token, ['with', 'from', 'that', 'this', 'they', 'them', 'their', 'have', 'about', 'your', 'will'], true)) {
                    continue;
                }
                $keywords[$token] = $token;
            }
        }

        if ($tone !== '') {
            $keywords[mb_strtolower($tone)] = mb_strtolower($tone);
        }

        foreach ($requiredMotifs as $motif) {
            $keyword = mb_strtolower(trim($motif));
            if ($keyword !== '' && mb_strlen($keyword) >= 3) {
                $keywords[$keyword] = $keyword;
            }
        }

        return array_values($keywords);
    }

    /**
     * Detect simple contradictions: discouraged phrases in the campaign messaging or
     * explicitly forbidden motifs landing in the VLM description.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function campaignMismatches(array $payload, string $vlmContext): array
    {
        $discouraged = array_merge(
            $this->asStringList(data_get($payload, 'messaging.discouraged_phrases')),
            $this->asStringList(data_get($payload, 'rules.discouraged_phrases')),
        );

        $hits = [];
        foreach ($discouraged as $phrase) {
            $needle = mb_strtolower(trim($phrase));
            if ($needle !== '' && mb_stripos($vlmContext, $needle) !== false) {
                $hits[] = $phrase;
                if (count($hits) >= 3) {
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * Best cosine between the asset's stored embedding and any campaign exemplar / mood /
     * motif reference that carries an embedding vector. Strict style and identity refs are
     * intentionally excluded -- those belong to the Identity / Visual Style pillars.
     *
     * Returns null when either side is missing or the dimensions are incompatible.
     */
    private function bestExemplarCosine(Asset $asset, CollectionCampaignIdentity $identity): ?float
    {
        $exemplars = $identity->campaignVisualReferences()
            ->whereIn('reference_type', [
                CampaignVisualReference::TYPE_EXEMPLAR,
                CampaignVisualReference::TYPE_MOOD,
                CampaignVisualReference::TYPE_MOTIF,
            ])
            ->whereNotNull('embedding_vector')
            ->get();

        if ($exemplars->isEmpty()) {
            return null;
        }

        $assetRow = \App\Models\AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        $assetVector = ($assetRow && ! empty($assetRow->embedding_vector))
            ? array_values(array_map('floatval', $assetRow->embedding_vector))
            : [];

        if ($assetVector === []) {
            return null;
        }

        $best = null;
        foreach ($exemplars as $ref) {
            $refVec = array_values(array_map('floatval', $ref->embedding_vector ?? []));
            if ($refVec === [] || count($refVec) !== count($assetVector)) {
                continue;
            }
            $cosine = $this->cosine($assetVector, $refVec);
            if ($best === null || $cosine > $best) {
                $best = $cosine;
            }
        }

        return $best;
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $v) {
            $w = $b[$i] ?? 0.0;
            $dot += $v * $w;
            $na += $v * $v;
            $nb += $w * $w;
        }
        $denom = sqrt($na) * sqrt($nb);

        return $denom < 1e-10 ? 0.0 : $dot / $denom;
    }

    /**
     * @return list<string>
     */
    private function asStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);
            }
        }

        return $out;
    }
}
