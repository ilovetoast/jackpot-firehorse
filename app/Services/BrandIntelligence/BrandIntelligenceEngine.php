<?php

namespace App\Services\BrandIntelligence;

use App\Enums\AssetContextType;
use App\Enums\BrandAlignmentState;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\BrandIntelligenceFeedback;
use App\Models\BrandReferenceAsset;
use App\Models\BrandVisualReference;
use App\Models\PdfTextExtraction;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AiMetadataGenerationService;
use Illuminate\Support\Collection;
use App\Services\ImageEmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BrandIntelligenceEngine
{
    /**
     * Bump when scoring semantics change; allows parallel history rows per asset and idempotent skips.
     */
    public const ENGINE_VERSION = 'v7_creative_copy_parallel';

    /** Cosine similarity vs brand logo reference embeddings above this counts as logo present. */
    public const LOGO_EMBEDDING_SIMILARITY_THRESHOLD = 0.72;

    /** Populated during {@see computeEbiSignalBreakdown()} for logging and API breakdown. */
    protected ?array $lastLogoDetectionDetail = null;

    public const AI_USAGE_TYPE = 'brand_intelligence_ai';

    /** Single-asset EBI path (default today). */
    public const SCORING_BASIS_SINGLE_ASSET = 'single_asset';

    /** Future execution / multi-asset rollup. */
    public const SCORING_BASIS_MULTI_ASSET = 'multi_asset';

    public function __construct(
        protected AIProviderInterface $aiProvider,
        protected AiMetadataGenerationService $aiMetadataGenerationService,
        protected AssetEmbeddingEnsureService $assetEmbeddingEnsureService,
        protected BrandColorPaletteAlignmentEvaluator $brandColorPaletteAlignmentEvaluator,
        protected AssetContextClassifier $assetContextClassifier,
        protected CreativeIntelligenceAnalyzer $creativeIntelligenceAnalyzer,
    ) {}

    /**
     * Score a single asset for Brand Intelligence (primary path; deliverables are assets).
     *
     * @return array{
     *     overall_score: int,
     *     confidence: float,
     *     level: string,
     *     breakdown_json: array,
     *     ai_used: bool,
     *     engine_version: string,
     *     asset_id: string
     * }|null
     */
    public function scoreAsset(Asset $asset, bool $dryRun = false): ?array
    {
        $asset->loadMissing('brand');

        $brand = $asset->brand;
        if (! $brand) {
            return null;
        }

        $signalBreakdown = $this->computeEbiSignalBreakdown($asset, $brand);
        $signalCount = $this->countTruthySignals($signalBreakdown);
        $assetContextType = $this->assetContextClassifier->classify($asset);
        if ($signalCount < 2) {
            return $this->scoreAssetInsufficientEvidence($asset, $brand, $signalBreakdown, $signalCount, $dryRun, $assetContextType);
        }

        $embeddedRow = $this->assetEmbeddingEnsureService->ensure($asset);
        $assetVec = ($embeddedRow && ! empty($embeddedRow->embedding_vector))
            ? array_values($embeddedRow->embedding_vector)
            : [];

        if ($this->shouldAbortScoringNoEmbeddingFallback($asset, $assetVec, $signalBreakdown)) {
            Log::warning('[EBI] Scoring aborted: image asset has no embedding and identity fallback unavailable', [
                'asset_id' => $asset->id,
                'brand_id' => $brand->id,
            ]);

            return null;
        }

        $signals = $this->detectAssetSignals($asset);
        $perAssetSignals = [[
            'asset_id' => $asset->id,
            'signals' => $signals,
            'tone_applicable' => $signals['has_text'],
            'typography_applicable' => $signals['has_typography'],
        ]];

        $refBlock = $this->buildReferenceSimilarityBreakdown($asset, $brand, $assetVec, $signalBreakdown, $assetContextType);
        $rs = $refBlock['reference_similarity'];
        $score = $this->initialOverallScoreFromReferenceSimilarity($rs);

        $styleRefCount = (int) ($refBlock['reference_quality']['reference_count'] ?? 0);
        $suppressStyleMismatchPenalty = $styleRefCount < ReferenceSimilarityCalculator::MIN_STYLE_REFERENCES_FOR_EMBEDDING;

        $normalizedSim = $refBlock['normalized_similarity'];
        $refConfidence = $rs['confidence'] ?? 0.0;
        if ($normalizedSim !== null && $refConfidence > 0.5) {
            if ($normalizedSim > 0.8) {
                $score += 5;
            } elseif ($normalizedSim < 0.4 && ! $suppressStyleMismatchPenalty) {
                $score -= 5;
            }
            $score = max(0, min(100, $score));
        }

        $confidence = ReferenceSimilarityCalculator::bandToNumericConfidence(
            $refBlock['confidence_band'] ?? 'low'
        );

        $feedback = BrandIntelligenceFeedback::query()
            ->where('asset_id', $asset->id)
            ->latest()
            ->limit(5)
            ->get();

        $positive = $feedback->where('rating', 'up')->count();
        $negative = $feedback->where('rating', 'down')->count();
        $feedbackScore = $positive - $negative;

        if ($feedbackScore > 1) {
            $confidence += 0.1;
        }
        if ($feedbackScore < -1) {
            $confidence -= 0.15;
        }
        $confidence = max(0.0, min(1.0, $confidence));
        $confidence = round($confidence, 2);

        $clusterSpread = $refBlock['reference_cluster']['spread'] ?? null;
        if ($clusterSpread !== null && $clusterSpread > 0.4) {
            $confidence -= 0.1;
            $confidence = max(0.0, min(1.0, round($confidence, 2)));
        }

        $signalStrength = $this->computeSignalStrength($signals, $refBlock['reference_similarity']);

        $keywordRelevance = $this->computeDomainRelevance($asset, $brand);
        $embeddingRelevance = $this->computeEmbeddingDomainRelevance($asset, $brand);

        $domainRelevance = $keywordRelevance;

        if ($embeddingRelevance !== null) {
            $domainRelevance = round(
                ($keywordRelevance * 0.4) + ($embeddingRelevance * 0.6),
                2
            );
        }

        if ($embeddingRelevance !== null && $embeddingRelevance < 0.35) {
            $domainRelevance = min($domainRelevance, 0.35);
        }

        if ($domainRelevance < 0.3) {
            $confidence -= 0.2;
        } elseif ($domainRelevance < 0.5) {
            $confidence -= 0.1;
        } elseif ($domainRelevance > 0.7) {
            $confidence += 0.05;
        }
        $confidence = max(0.0, min(1.0, round($confidence, 2)));

        if (! $suppressStyleMismatchPenalty) {
            if ($domainRelevance < 0.3) {
                $score -= 15;
            } elseif ($domainRelevance < 0.5) {
                $score -= 8;
            }
        }
        $score = max(0, min(100, $score));

        $score = $this->applyConfidenceAndSignalAdjustments(
            $score,
            $confidence,
            $signalStrength,
            $refBlock['reference_similarity']
        );

        $preBreakdown = [
            'reference_similarity' => $refBlock['reference_similarity'],
            'confidence' => $confidence,
        ];
        $generativeValidationPayload = [
            'used' => false,
            'score' => null,
            'confidence' => 0,
        ];
        if ($this->shouldRunGenerativeValidation($asset, $preBreakdown)) {
            $gv = $this->runGenerativeValidation($asset, $brand, $dryRun);
            if ($gv !== null) {
                $aiScore = $gv['score'] / 100.0;
                if ($aiScore < 0.4 && ! $suppressStyleMismatchPenalty) {
                    $score -= 10;
                }
                $score = max(0, min(100, $score));
                $confidence = max($confidence, $gv['confidence']);
                $confidence = max(0.0, min(1.0, round($confidence, 2)));
                $generativeValidationPayload = [
                    'used' => true,
                    'score' => $gv['score'],
                    'confidence' => $gv['confidence'],
                ];
                if (! $dryRun) {
                    $this->logBrandIntelligenceAiUsage($asset);
                }
            }
        }

        $baseBreakdown = $this->mergeSignalBreakdown([
            'source' => 'ebi_asset_score',
            'scoring_basis' => self::SCORING_BASIS_SINGLE_ASSET,
            'source_asset_id' => $asset->id,
            'asset_ids_considered' => [$asset->id],
        ], $perAssetSignals);

        $withRefs = $this->applySignalInterpretationToBreakdown($baseBreakdown, $signals);
        $withRefs['reference_similarity'] = $refBlock['reference_similarity'];
        $withRefs['identity_style_blend'] = $refBlock['identity_style_blend'] ?? null;
        $withRefs['confidence_band'] = $refBlock['confidence_band'] ?? 'low';
        $withRefs['fallback_used'] = ! empty($refBlock['reference_similarity']['fallback_used']);
        $withRefs['style_mismatch_penalty_suppressed'] = $suppressStyleMismatchPenalty;

        $consumerSignals = $this->buildConsumerSignalBreakdown($asset, $brand, $signalBreakdown);

        $alignmentNumeric = $this->alignmentNumericFromScoreAndReferences($score, $refBlock);
        $alignmentState = BrandAlignmentState::fromNormalizedScore($alignmentNumeric);
        $styleDeviationReason = null;
        if ($alignmentState === BrandAlignmentState::OFF_BRAND
            && $this->shouldPreferPartialOverOffBrand($signalBreakdown, $refBlock)) {
            $alignmentState = BrandAlignmentState::PARTIAL_ALIGNMENT;
            $styleDeviationReason = 'Visual style differs from references while brand identity signals (logo, colors, typography) remain strong.';
            $alignmentNumeric = max($alignmentNumeric, 0.41);
        }
        if ($alignmentState === BrandAlignmentState::OFF_BRAND
            && $this->triStrongIdentitySignals($consumerSignals['signals'])) {
            Log::info('[EBI] Tri-signal override: OFF_BRAND → PARTIAL_ALIGNMENT (logo+colors+typography)', [
                'asset_id' => $asset->id,
                'brand_id' => $brand->id,
            ]);
            $alignmentState = BrandAlignmentState::PARTIAL_ALIGNMENT;
            $alignmentNumeric = max($alignmentNumeric, 0.41);
            $styleDeviationReason = $styleDeviationReason
                ?? 'Core brand elements (logo, colors, typography) agree; visual tone may differ from references.';
        }
        $level = $alignmentState->toLegacyLevel();
        $withRefs['alignment_state'] = $alignmentState->value;
        $withRefs['alignment_score_normalized'] = round($alignmentNumeric, 4);
        $withRefs['signal_count'] = $signalCount;
        $withRefs['signal_breakdown'] = $signalBreakdown;
        $withRefs['consumer_signal_breakdown'] = $consumerSignals['signals'];
        $withRefs['color_alignment_detail'] = $consumerSignals['color_alignment_detail'];
        $withRefs['reference_tier_usage'] = $refBlock['reference_tier_usage'] ?? [
            'system' => 0,
            'promoted' => 0,
            'guideline' => 0,
        ];
        $withRefs['level'] = $level;
        $withRefs['signal_strength'] = $signalStrength;
        $withRefs['domain_relevance'] = [
            'score' => $domainRelevance,
            'keyword' => $keywordRelevance,
            'embedding' => $embeddingRelevance,
        ];
        $withRefs['insufficient_signal'] = $this->isInsufficientSignal($signalStrength, $confidence, $domainRelevance);
        $withRefs['confidence_reason'] = [
            'low_signal' => $signalStrength < 0.5,
            'no_references' => empty($refBlock['reference_similarity']['used']),
            'missing_typography' => ! $signals['has_typography'],
        ];
        $withRefs['reference_quality'] = $refBlock['reference_quality'];
        $withRefs['reference_stability'] = $refBlock['reference_stability'] ?? ['consistent' => false];
        $withRefs['reference_cluster'] = $refBlock['reference_cluster'] ?? [
            'spread' => null,
            'tight_cluster' => false,
        ];
        $withRefs['feedback'] = [
            'count' => $feedback->count(),
            'score' => $feedbackScore,
        ];

        $withRefs['generative_validation'] = $generativeValidationPayload;
        $withRefs['confidence'] = $confidence;
        $withRefs['context_type'] = $assetContextType->value;
        $withRefs['style_deviation_reason'] = $styleDeviationReason;
        $withRefs['logo_detection'] = $this->lastLogoDetectionDetail;

        $this->mergeCreativeIntelligenceLayer($asset, $brand, $withRefs, $assetContextType, $dryRun, $score);

        $recs = $this->generateRecommendations($withRefs);
        $withRefs['recommendations'] = $recs['recommendations'];

        $withRefs['debug'] = $this->buildBrandIntelligenceDebugPayload($asset, $brand, $withRefs);

        $withRefs['_gate_confidence'] = $confidence;
        $withRefs['_gate_level'] = $level;
        $aiInsightPayload = $this->generateAIInsight($asset, $withRefs, $dryRun);
        unset($withRefs['_gate_confidence'], $withRefs['_gate_level']);

        $aiUsed = ($generativeValidationPayload['used'] ?? false) === true;
        if ($aiInsightPayload !== null && isset($aiInsightPayload['ai_insight']['text'])) {
            $withRefs['ai_insight'] = $aiInsightPayload['ai_insight'];
            $aiUsed = true;
        }
        if (($withRefs['ebi_ai_trace']['creative_ai_ran'] ?? false) === true) {
            $aiUsed = true;
        }
        $withRefs['ai_used'] = $aiUsed;

        return [
            'overall_score' => $score,
            'confidence' => $confidence,
            'level' => $level,
            'breakdown_json' => $withRefs,
            'ai_used' => $aiUsed,
            'engine_version' => self::ENGINE_VERSION,
            'asset_id' => $asset->id,
        ];
    }

    /**
     * When fewer than two EBI gate signals are present, do not infer misalignment — guidance only.
     *
     * @param  array<string, bool>  $signalBreakdown
     */
    protected function scoreAssetInsufficientEvidence(
        Asset $asset,
        Brand $brand,
        array $signalBreakdown,
        int $signalCount,
        bool $dryRun,
        ?AssetContextType $assetContextType = null,
    ): array {
        $assetContextType ??= $this->assetContextClassifier->classify($asset);
        $signals = $this->detectAssetSignals($asset);
        $perAssetSignals = [[
            'asset_id' => $asset->id,
            'signals' => $signals,
            'tone_applicable' => $signals['has_text'],
            'typography_applicable' => $signals['has_typography'],
        ]];

        $baseBreakdown = $this->mergeSignalBreakdown([
            'source' => 'ebi_asset_score',
            'scoring_basis' => self::SCORING_BASIS_SINGLE_ASSET,
            'source_asset_id' => $asset->id,
            'asset_ids_considered' => [$asset->id],
        ], $perAssetSignals);

        $withRefs = $this->applySignalInterpretationToBreakdown($baseBreakdown, $signals);
        $confidence = 0.45;
        $level = BrandAlignmentState::INSUFFICIENT_EVIDENCE->toLegacyLevel();

        $withRefs['alignment_state'] = BrandAlignmentState::INSUFFICIENT_EVIDENCE->value;
        $withRefs['alignment_score_normalized'] = null;
        $withRefs['signal_count'] = $signalCount;
        $withRefs['signal_breakdown'] = $signalBreakdown;
        $consumerSignals = $this->buildConsumerSignalBreakdown($asset, $brand, $signalBreakdown);
        $withRefs['consumer_signal_breakdown'] = $consumerSignals['signals'];
        $withRefs['color_alignment_detail'] = $consumerSignals['color_alignment_detail'];
        $withRefs['reference_tier_usage'] = $this->referenceTierCountsForBrand($brand);
        $withRefs['reference_similarity'] = [
            'score' => null,
            'score_percent' => null,
            'fallback_used' => false,
            'confidence' => 0.0,
            'reference_count' => 0,
            'normalized' => null,
            'used' => false,
            'weighted' => true,
            'top_match_ids' => [],
            'variance' => null,
            'style_only' => true,
        ];
        $withRefs['confidence_band'] = 'low';
        $withRefs['fallback_used'] = false;
        $withRefs['level'] = $level;
        $withRefs['signal_strength'] = 0.0;
        $withRefs['domain_relevance'] = ['score' => null, 'keyword' => null, 'embedding' => null];
        $withRefs['insufficient_signal'] = true;
        $withRefs['confidence_reason'] = [
            'ebi_gate' => 'Fewer than 2 of: logo, brand colors, typography, reference similarity readiness',
        ];
        $withRefs['confidence'] = $confidence;
        $withRefs['recommendations'] = $this->generateInsufficientEvidenceRecommendations($signalBreakdown);
        $withRefs['generative_validation'] = ['used' => false, 'score' => null, 'confidence' => 0];
        $withRefs['ai_used'] = false;
        $withRefs['context_type'] = $assetContextType->value;
        $withRefs['style_deviation_reason'] = null;
        $withRefs['logo_detection'] = $this->lastLogoDetectionDetail;

        $insufficientScore = 50;
        $this->mergeCreativeIntelligenceLayer($asset, $brand, $withRefs, $assetContextType, $dryRun, $insufficientScore);

        $withRefs['debug'] = $this->buildBrandIntelligenceDebugPayload($asset, $brand, $withRefs);
        $withRefs['_gate_confidence'] = $confidence;
        $withRefs['_gate_level'] = $level;

        $aiInsightPayload = $this->generateAIInsight($asset, $withRefs, $dryRun);
        unset($withRefs['_gate_confidence'], $withRefs['_gate_level']);

        if ($aiInsightPayload !== null && isset($aiInsightPayload['ai_insight']['text'])) {
            $withRefs['ai_insight'] = $aiInsightPayload['ai_insight'];
        }

        $aiUsedInsufficient = ! empty($withRefs['ai_insight'])
            || (($withRefs['ebi_ai_trace']['creative_ai_ran'] ?? false) === true);

        return [
            'overall_score' => 50,
            'confidence' => $confidence,
            'level' => $level,
            'breakdown_json' => $withRefs,
            'ai_used' => $aiUsedInsufficient,
            'engine_version' => self::ENGINE_VERSION,
            'asset_id' => $asset->id,
        ];
    }

    /**
     * Parallel creative vision + copy layer (additive; optional small penalty only on explicit DNA conflict).
     *
     * @param  array<string, mixed>  $withRefs
     */
    protected function mergeCreativeIntelligenceLayer(
        Asset $asset,
        Brand $brand,
        array &$withRefs,
        AssetContextType $assetContextType,
        bool $dryRun,
        int &$score,
    ): void {
        $layer = $this->creativeIntelligenceAnalyzer->analyze($asset, $brand, $assetContextType, $dryRun);

        $withRefs['creative_analysis'] = $layer['creative_analysis'];
        $withRefs['copy_alignment'] = $layer['copy_alignment'];
        $withRefs['context_analysis'] = array_merge(
            [
                'context_type_heuristic' => $assetContextType->value,
                'context_type_ai' => null,
                'scene_type' => null,
                'lighting_type' => null,
                'mood' => null,
            ],
            is_array($layer['context_analysis'] ?? null) ? $layer['context_analysis'] : []
        );
        $withRefs['visual_alignment_ai'] = $layer['visual_alignment_ai'];
        $withRefs['overall_summary'] = $layer['overall_summary'] ?? null;
        $withRefs['brand_copy_conflict'] = (bool) ($layer['brand_copy_conflict'] ?? false);
        $withRefs['ebi_ai_trace'] = is_array($layer['ebi_ai_trace'] ?? null) ? $layer['ebi_ai_trace'] : [];

        $withRefs['visual_alignment'] = [
            'alignment_state' => $withRefs['alignment_state'] ?? null,
            'alignment_score_normalized' => $withRefs['alignment_score_normalized'] ?? null,
            'level' => $withRefs['level'] ?? null,
            'label' => 'Visual (references & identity)',
        ];

        $withRefs['dimension_weights'] = $this->computeCreativeDimensionWeights($assetContextType, $layer);

        if (($layer['ebi_ai_trace']['creative_ai_ran'] ?? false) === true && ! $dryRun) {
            $this->logBrandIntelligenceAiUsage($asset);
        }

        if (
            ($layer['brand_copy_conflict'] ?? false) === true
            && ($withRefs['copy_alignment']['alignment_state'] ?? '') === 'off_brand'
        ) {
            $score = max(0, $score - 5);
        }
    }

    /**
     * Relative emphasis for UI / narrative (does not drive embedding score directly).
     *
     * @param  array<string, mixed>  $layer
     * @return array{visual: float, copy: float, context: float}
     */
    protected function computeCreativeDimensionWeights(AssetContextType $context, array $layer): array
    {
        $copyExtracted = (bool) ($layer['ebi_ai_trace']['copy_extracted'] ?? false);

        $visual = 0.55;
        $copy = 0.25;
        $ctx = 0.2;

        if (in_array($context, [AssetContextType::DIGITAL_AD, AssetContextType::SOCIAL_POST], true)) {
            $visual = 0.4;
            $copy = 0.45;
            $ctx = 0.15;
        } elseif ($context === AssetContextType::LIFESTYLE) {
            $visual = 0.7;
            $copy = 0.1;
            $ctx = 0.2;
        } elseif ($context === AssetContextType::PRODUCT_HERO) {
            $visual = 0.6;
            $copy = 0.2;
            $ctx = 0.2;
        }

        if (! $copyExtracted) {
            $visual += $copy * 0.7;
            $ctx += $copy * 0.3;
            $copy = 0.0;
        }

        $sum = $visual + $copy + $ctx;
        if ($sum <= 0) {
            return ['visual' => 1.0, 'copy' => 0.0, 'context' => 0.0];
        }

        return [
            'visual' => round($visual / $sum, 3),
            'copy' => round($copy / $sum, 3),
            'context' => round($ctx / $sum, 3),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function referenceTierCountsForBrand(Brand $brand): array
    {
        $base = fn () => BrandVisualReference::query()->where('brand_id', $brand->id);
        if (Schema::hasColumn('brand_visual_references', 'reference_tier')) {
            $counts = [
                'system' => $base()->where('reference_tier', BrandVisualReference::TIER_SYSTEM)->count(),
                'promoted' => $base()->where('reference_tier', BrandVisualReference::TIER_PROMOTED)->count(),
                'guideline' => $base()->where('reference_tier', BrandVisualReference::TIER_GUIDELINE)->count(),
            ];
        } else {
            $n = $base()->count();
            $counts = ['system' => 0, 'promoted' => 0, 'guideline' => $n];
        }

        if (Schema::hasTable('brand_reference_assets')) {
            foreach (BrandReferenceAsset::query()->where('brand_id', $brand->id)->get() as $bra) {
                $t = (int) $bra->tier;
                if ($t === BrandReferenceAsset::TIER_REFERENCE) {
                    $counts['promoted']++;
                } elseif ($t === BrandReferenceAsset::TIER_GUIDELINE) {
                    $counts['guideline']++;
                } else {
                    $counts['system']++;
                }
            }
        }

        return $counts;
    }

    /**
     * Image assets without a stored embedding cannot use the similarity path; abort only when
     * identity fallback (logo / colors / typography signals) cannot run either.
     *
     * @param  array<string, bool>  $signalBreakdown
     */
    protected function shouldAbortScoringNoEmbeddingFallback(Asset $asset, array $assetVec, array $signalBreakdown): bool
    {
        if (! ImageEmbeddingService::isImageMimeType((string) ($asset->mime_type ?? ''), $asset->original_filename)) {
            return false;
        }
        if ($assetVec !== []) {
            return false;
        }

        return ! $this->identityFallbackAvailable($signalBreakdown);
    }

    /**
     * @param  array<string, bool>  $signalBreakdown
     */
    protected function identityFallbackAvailable(array $signalBreakdown): bool
    {
        return ($signalBreakdown['has_logo'] ?? false)
            || ($signalBreakdown['has_brand_colors'] ?? false)
            || ($signalBreakdown['has_typography'] ?? false);
    }

    protected function initialOverallScoreFromReferenceSimilarity(array $rs): int
    {
        if (isset($rs['score_percent']) && is_numeric($rs['score_percent'])) {
            return (int) $rs['score_percent'];
        }
        if (isset($rs['score']) && is_numeric($rs['score'])) {
            $s = (float) $rs['score'];
            if ($s >= 0.0 && $s <= 1.0) {
                return (int) round($s * 100);
            }

            return (int) round($s);
        }

        return 50;
    }

    /**
     * Style references with embeddings (identity / logo rows excluded).
     */
    protected function queryStyleReferencesWithEmbeddings(Brand $brand): Collection
    {
        $q = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector');

        if (Schema::hasColumn('brand_visual_references', 'reference_type')) {
            $q->where(function ($sub) {
                $sub->where('reference_type', BrandVisualReference::REFERENCE_TYPE_STYLE)
                    ->orWhere(function ($sub2) {
                        $sub2->whereNull('reference_type')
                            ->where('type', '!=', BrandVisualReference::TYPE_LOGO);
                    });
            });
        } else {
            $q->where('type', '!=', BrandVisualReference::TYPE_LOGO);
        }

        return $q->orderByDesc('id')->limit(120)->get()
            ->filter(fn (BrandVisualReference $r) => $r->isStyleReferenceForSimilarity())
            ->values();
    }

    /**
     * @param  Collection<int, BrandVisualReference>  $refs
     * @return array{system: int, promoted: int, guideline: int}
     */
    protected function aggregateTierUsage(Collection $refs): array
    {
        $tierUsage = ['system' => 0, 'promoted' => 0, 'guideline' => 0];
        foreach ($refs as $r) {
            $t = $r->reference_tier;
            if ($t === BrandVisualReference::TIER_SYSTEM) {
                $tierUsage['system']++;
            } elseif ($t === BrandVisualReference::TIER_PROMOTED) {
                $tierUsage['promoted']++;
            } else {
                $tierUsage['guideline']++;
            }
        }

        return $tierUsage;
    }

    /**
     * @return array{has_logo: bool, has_brand_colors: bool, has_typography: bool, has_reference_similarity: bool}
     */
    protected function computeEbiSignalBreakdown(Asset $asset, Brand $brand): array
    {
        $this->lastLogoDetectionDetail = $this->buildLogoDetectionDetail($asset, $brand);

        return [
            'has_logo' => $this->lastLogoDetectionDetail['has_logo'],
            'has_brand_colors' => $this->signalBrandHasColors($brand),
            'has_typography' => $this->signalBrandHasTypography($asset, $brand),
            'has_reference_similarity' => $this->signalReferenceSimilarityReady($asset, $brand),
        ];
    }

    /**
     * Drawer-facing signals: same keys as {@see computeEbiSignalBreakdown} but
     * {@see has_brand_colors} uses tolerant ΔE vs dominant colors when data exists.
     *
     * @param  array<string, bool>  $signalBreakdown
     * @return array{signals: array<string, bool>, color_alignment_detail: array<string, mixed>}
     */
    protected function buildConsumerSignalBreakdown(Asset $asset, Brand $brand, array $signalBreakdown): array
    {
        $detail = $this->brandColorPaletteAlignmentEvaluator->evaluate($asset, $brand);
        $consumer = $signalBreakdown;
        if (($detail['evaluated'] ?? false) === true && array_key_exists('aligned', $detail) && $detail['aligned'] !== null) {
            $consumer['has_brand_colors'] = (bool) $detail['aligned'];
        }

        return [
            'signals' => $consumer,
            'color_alignment_detail' => $detail,
        ];
    }

    /**
     * @param  array<string, bool>  $breakdown
     */
    protected function countTruthySignals(array $breakdown): int
    {
        return count(array_filter($breakdown, fn ($v) => $v === true));
    }

    /**
     * Asset-level logo signal: brand name in OCR/text OR cosine similarity to a stored logo reference embedding.
     *
     * @return array{
     *     has_logo: bool,
     *     ocr_matched: bool,
     *     ocr_token: string|null,
     *     embedding_similarity: float|null,
     *     logo_reference_id: int|string|null
     * }
     */
    protected function buildLogoDetectionDetail(Asset $asset, Brand $brand): array
    {
        $ocr = $this->brandNameFoundInAssetText($asset, $brand);
        $emb = $this->maxCosineToBrandLogoReferences($asset, $brand);

        $hasLogo = $ocr['matched'] === true
            || (($emb['similarity'] ?? null) !== null && (float) $emb['similarity'] >= self::LOGO_EMBEDDING_SIMILARITY_THRESHOLD);

        if ($ocr['matched'] === true) {
            Log::debug('[EBI] Logo signal via OCR/text match', [
                'asset_id' => $asset->id,
                'brand_id' => $brand->id,
                'token' => $ocr['token'] ?? null,
            ]);
        }
        if (($emb['similarity'] ?? null) !== null && (float) $emb['similarity'] >= self::LOGO_EMBEDDING_SIMILARITY_THRESHOLD) {
            Log::debug('[EBI] Logo signal via embedding vs logo reference', [
                'asset_id' => $asset->id,
                'brand_id' => $brand->id,
                'similarity' => $emb['similarity'],
                'logo_reference_id' => $emb['reference_id'] ?? null,
            ]);
        }

        return [
            'has_logo' => $hasLogo,
            'ocr_matched' => $ocr['matched'] === true,
            'ocr_token' => $ocr['token'] ?? null,
            'embedding_similarity' => $emb['similarity'],
            'logo_reference_id' => $emb['reference_id'] ?? null,
        ];
    }

    /**
     * @return array{matched: bool, token: string|null}
     */
    protected function brandNameFoundInAssetText(Asset $asset, Brand $brand): array
    {
        $haystack = $this->collectAssetTextHaystack($asset);
        if ($haystack === '') {
            return ['matched' => false, 'token' => null];
        }

        foreach ($this->brandNameSearchTokens($brand) as $token) {
            if ($token === '') {
                continue;
            }
            if (mb_stripos($haystack, $token, 0, 'UTF-8') !== false) {
                return ['matched' => true, 'token' => $token];
            }
        }

        return ['matched' => false, 'token' => null];
    }

    /**
     * @return list<string>
     */
    protected function brandNameSearchTokens(Brand $brand): array
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $short = is_array($payload['brand'] ?? null) ? ($payload['brand']['short_name'] ?? null) : null;

        $raw = array_filter([
            trim((string) $brand->name),
            trim((string) $brand->slug),
            is_string($short) ? trim($short) : null,
        ]);

        $tokens = [];
        foreach ($raw as $r) {
            if ($r === '') {
                continue;
            }
            $tokens[] = mb_strtolower($r, 'UTF-8');
            if (str_contains($r, '-') || str_contains($r, '_')) {
                $tokens[] = mb_strtolower(str_replace(['-', '_'], ' ', $r), 'UTF-8');
            }
        }

        $tokens = array_values(array_unique(array_filter($tokens, fn ($t) => mb_strlen($t, 'UTF-8') >= 2)));

        return $tokens;
    }

    protected function collectAssetTextHaystack(Asset $asset): string
    {
        $parts = [
            (string) ($asset->title ?? ''),
            (string) ($asset->original_filename ?? ''),
        ];
        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];
        foreach (['extracted_text', 'ocr_text', 'vision_ocr', 'detected_text'] as $k) {
            if (! empty($meta[$k]) && is_string($meta[$k])) {
                $parts[] = $meta[$k];
            }
        }
        if (Schema::hasTable('pdf_text_extractions')) {
            $ext = PdfTextExtraction::query()
                ->where('asset_id', $asset->id)
                ->orderByDesc('id')
                ->first();
            if ($ext && is_string($ext->extracted_text ?? null) && trim($ext->extracted_text) !== '') {
                $parts[] = $ext->extracted_text;
            }
        }

        return mb_strtolower(trim(implode("\n", array_filter($parts))), 'UTF-8');
    }

    /**
     * @return array{similarity: float|null, reference_id: int|string|null}
     */
    protected function maxCosineToBrandLogoReferences(Asset $asset, Brand $brand): array
    {
        $row = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        if (! $row || empty($row->embedding_vector)) {
            return ['similarity' => null, 'reference_id' => null];
        }
        $vec = array_values($row->embedding_vector);

        $q = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->where('type', BrandVisualReference::TYPE_LOGO)
            ->whereNotNull('embedding_vector');

        $best = null;
        $bestId = null;
        foreach ($q->cursor() as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if ($refVec === [] || count($refVec) !== count($vec)) {
                continue;
            }
            $c = $this->cosineSimilarity($vec, $refVec);
            if ($best === null || $c > $best) {
                $best = $c;
                $bestId = $ref->id;
            }
        }

        return [
            'similarity' => $best !== null ? round((float) $best, 4) : null,
            'reference_id' => $bestId,
        ];
    }

    /**
     * @param  array<string, bool>  $signals  Consumer-facing (post color evaluation).
     */
    protected function triStrongIdentitySignals(array $signals): bool
    {
        return ($signals['has_logo'] ?? false) === true
            && ($signals['has_brand_colors'] ?? false) === true
            && ($signals['has_typography'] ?? false) === true;
    }

    protected function signalBrandHasColors(Brand $brand): bool
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $visual = is_array($payload['visual'] ?? null) ? $payload['visual'] : [];
        $colors = $visual['colors'] ?? $visual['palette'] ?? $visual['brand_colors'] ?? [];
        if (! is_array($colors)) {
            return false;
        }
        foreach ($colors as $c) {
            if (is_string($c) && trim($c) !== '') {
                return true;
            }
            if (is_array($c) && (! empty($c['hex']) || ! empty($c['value']) || ! empty($c['name']))) {
                return true;
            }
        }

        return false;
    }

    protected function signalBrandHasTypography(Asset $asset, Brand $brand): bool
    {
        if ($this->assetHasTypographyMetadata($asset)) {
            return true;
        }

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

    protected function signalReferenceSimilarityReady(Asset $asset, Brand $brand): bool
    {
        $assetEmb = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        if (! $assetEmb || empty($assetEmb->embedding_vector)) {
            return false;
        }

        $q = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector');

        $candidates = $q->get()->filter(fn (BrandVisualReference $r) => $r->isStyleReferenceForSimilarity());

        if ($candidates->isNotEmpty()) {
            return true;
        }

        return count($this->collectPromotedBrandReferenceAssetEntries($brand)) > 0;
    }

    /**
     * User-promoted style references: vectors live on asset_embeddings.
     *
     * @return list<array{pool: string, id: string, numeric_id: int, asset_id: string, vector: list<float>, weight: float, reference_tier: string}>
     */
    protected function collectPromotedBrandReferenceAssetEntries(Brand $brand): array
    {
        if (! Schema::hasTable('brand_reference_assets')) {
            return [];
        }

        $rows = BrandReferenceAsset::query()
            ->where('brand_id', $brand->id)
            ->where('reference_type', BrandReferenceAsset::REFERENCE_TYPE_STYLE)
            ->get();

        $out = [];
        foreach ($rows as $bra) {
            $emb = AssetEmbedding::query()->where('asset_id', $bra->asset_id)->first();
            if (! $emb || empty($emb->embedding_vector)) {
                continue;
            }

            $out[] = [
                'pool' => 'brand_reference_asset',
                'id' => 'bra:'.$bra->id,
                'numeric_id' => $bra->id,
                'asset_id' => (string) $bra->asset_id,
                'vector' => array_values($emb->embedding_vector),
                'weight' => max(0.0, (float) $bra->weight),
                'context_type' => Schema::hasColumn('brand_reference_assets', 'context_type')
                    ? ($bra->context_type ?? null)
                    : null,
                'reference_tier' => match ((int) $bra->tier) {
                    BrandReferenceAsset::TIER_REFERENCE => BrandVisualReference::TIER_PROMOTED,
                    BrandReferenceAsset::TIER_GUIDELINE => BrandVisualReference::TIER_GUIDELINE,
                    default => BrandVisualReference::TIER_SYSTEM,
                },
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{reference_tier: string}>  $promotedEntries
     * @param  array{system: int, promoted: int, guideline: int}  $tierUsage
     * @return array{system: int, promoted: int, guideline: int}
     */
    protected function mergePromotedTierUsage(array $tierUsage, array $promotedEntries): array
    {
        foreach ($promotedEntries as $p) {
            $t = $p['reference_tier'];
            if ($t === BrandVisualReference::TIER_SYSTEM) {
                $tierUsage['system']++;
            } elseif ($t === BrandVisualReference::TIER_PROMOTED) {
                $tierUsage['promoted']++;
            } else {
                $tierUsage['guideline']++;
            }
        }

        return $tierUsage;
    }

    /**
     * Prefer weighted style-reference similarity when available; else fall back to normalized overall score.
     */
    protected function alignmentNumericFromScoreAndReferences(int $score, array $refBlock): float
    {
        $norm = $refBlock['normalized_similarity'] ?? null;
        if ($norm !== null && is_numeric($norm)) {
            return max(0.0, min(1.0, (float) $norm));
        }

        return max(0.0, min(1.0, $score / 100.0));
    }

    /**
     * @param  array<string, bool>  $signalBreakdown
     * @return list<string>
     */
    protected function generateInsufficientEvidenceRecommendations(array $signalBreakdown): array
    {
        $hints = [];
        if (! ($signalBreakdown['has_reference_similarity'] ?? false)) {
            $hints[] = 'Add approved style reference images and ensure embeddings are generated so visual similarity can be measured.';
        }
        if (! ($signalBreakdown['has_brand_colors'] ?? false)) {
            $hints[] = 'Define brand colors in your brand model to strengthen alignment signals.';
        }
        if (! ($signalBreakdown['has_typography'] ?? false)) {
            $hints[] = 'Add typography to your brand guidelines or approve font metadata on assets when available.';
        }
        if (! ($signalBreakdown['has_logo'] ?? false)) {
            $hints[] = 'Upload a logo reference to anchor identity checks.';
        }

        return array_slice($hints, 0, 2);
    }

    /**
     * Optional vision-backed suggestion when deterministic signals are weak (gated; does not change score).
     *
     * @return array{ai_insight: array{text: string, confidence: float}}|null
     */
    public function generateAIInsight(Asset $asset, array $breakdown, bool $dryRun = false): ?array
    {
        $gateConfidence = $breakdown['_gate_confidence'] ?? null;
        if (! is_float($gateConfidence) && ! is_int($gateConfidence)) {
            return null;
        }
        $gateConfidence = (float) $gateConfidence;

        $gateLevel = $breakdown['_gate_level'] ?? null;
        if ($gateLevel === 'high' && $gateConfidence >= 0.8) {
            return null;
        }

        if (count($breakdown['recommendations'] ?? []) >= 2) {
            return null;
        }

        $ref = $breakdown['reference_similarity'] ?? [];
        $refsUsed = ! empty($ref['used']);
        if ($refsUsed && $gateConfidence >= 0.7) {
            return null;
        }

        if (! $this->assetHasVisual($asset)) {
            return null;
        }

        $asset->loadMissing('brand');
        $brand = $asset->brand;
        if (! $brand) {
            return null;
        }

        $imageDataUrl = $this->aiMetadataGenerationService->fetchThumbnailForVisionAnalysis($asset);
        if ($imageDataUrl === null || $imageDataUrl === '') {
            Log::info('[EBI] AI insight skipped: no thumbnail for vision', ['asset_id' => $asset->id]);

            return null;
        }

        $modelKey = 'gpt-4o-mini';
        $modelName = config("ai.models.{$modelKey}.model_name", 'gpt-4o-mini');

        $ctx = $this->extractBrandContextForInsight($brand);
        $prompt = "You are evaluating how well an image aligns with a brand.\n\n"
            ."Focus on:\n"
            ."- visual composition\n"
            ."- color usage\n"
            ."- typography (if present)\n\n"
            ."Give ONE specific, actionable suggestion to improve alignment.\n\n"
            ."Do NOT describe the image.\n"
            ."Do NOT be generic.\n"
            ."Keep it under 20 words.\n\n"
            ."Brand context (may be incomplete):\n"
            .'- Brand tone: '.($ctx['tone'] ?? 'Not specified')."\n"
            .'- Visual style: '.($ctx['visual_style'] ?? 'Not specified')."\n\n"
            ."The attached image is the asset to evaluate.\n"
            ."Respond with JSON only: {\"suggestion\":\"...\",\"confidence\":0.72}\n"
            .'The "confidence" field must be a number between 0.6 and 0.8 (your confidence in this suggestion).';

        try {
            $response = $this->aiProvider->analyzeImage($imageDataUrl, $prompt, [
                'model' => $modelName,
                'max_tokens' => 200,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[EBI] AI insight failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $parsed = $this->parseAiInsightResponse($response['text'] ?? '');
        if ($parsed === null) {
            return null;
        }

        if (! $dryRun) {
            $this->logBrandIntelligenceAiUsage($asset);
        }

        return [
            'ai_insight' => [
                'text' => $parsed['text'],
                'confidence' => $parsed['confidence'],
            ],
        ];
    }

    /**
     * @return array{tone: ?string, visual_style: ?string}
     */
    protected function extractBrandContextForInsight(Brand $brand): array
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $personality = is_array($payload['personality'] ?? null) ? $payload['personality'] : [];
        $visual = is_array($payload['visual'] ?? null) ? $payload['visual'] : [];
        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];

        $toneKeywords = $rules['tone_keywords'] ?? null;
        $toneKeywordStr = null;
        if (is_array($toneKeywords)) {
            $flat = [];
            foreach ($toneKeywords as $item) {
                if (is_string($item)) {
                    $flat[] = $item;
                } elseif (is_array($item)) {
                    $flat[] = $item['label'] ?? $item['value'] ?? $item['text'] ?? '';
                }
            }
            $flat = array_filter(array_map('trim', $flat));
            $toneKeywordStr = $flat !== [] ? implode(', ', $flat) : null;
        }

        $toneParts = array_filter([
            $personality['tone'] ?? null,
            $personality['voice'] ?? null,
            $personality['voice_description'] ?? null,
            $personality['brand_voice'] ?? null,
            $toneKeywordStr,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        $tone = $toneParts !== [] ? implode('; ', $toneParts) : null;

        $visualParts = array_filter([
            $visual['style'] ?? null,
            $visual['brand_look'] ?? null,
            $visual['photography_style'] ?? null,
            $personality['brand_look'] ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        $visualStyle = $visualParts !== [] ? implode('; ', $visualParts) : null;

        return [
            'tone' => $tone,
            'visual_style' => $visualStyle,
        ];
    }

    /**
     * @return array{text: string, confidence: float}|null
     */
    protected function parseAiInsightResponse(string $text): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/m', $raw, $m)) {
            $raw = $m[1];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $suggestion = $decoded['suggestion'] ?? $decoded['text'] ?? null;
        if (! is_string($suggestion) || trim($suggestion) === '') {
            return null;
        }

        $suggestion = $this->truncateInsightText($suggestion);
        $suggestion = $this->limitInsightWordCount($suggestion, 20);

        $conf = $decoded['confidence'] ?? 0.7;
        if (! is_numeric($conf)) {
            $conf = 0.7;
        }
        $conf = (float) $conf;
        $conf = max(0.6, min(0.8, $conf));

        return [
            'text' => $suggestion,
            'confidence' => round($conf, 2),
        ];
    }

    protected function truncateInsightText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return Str::limit($text, 400, '…');
    }

    protected function limitInsightWordCount(string $text, int $maxWords): string
    {
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) <= $maxWords) {
            return $text;
        }

        return implode(' ', array_slice($parts, 0, $maxWords));
    }

    protected function logBrandIntelligenceAiUsage(Asset $asset): void
    {
        try {
            if (! Schema::hasTable('ai_usage_logs')) {
                return;
            }
            $row = [
                'tenant_id' => $asset->tenant_id,
                'type' => self::AI_USAGE_TYPE,
                'created_at' => now(),
            ];
            if (Schema::hasColumn('ai_usage_logs', 'brand_id')) {
                $row['brand_id'] = $asset->brand_id;
            }
            DB::table('ai_usage_logs')->insert($row);
        } catch (\Throwable $e) {
            Log::warning('[EBI] ai_usage_logs insert failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{has_text: bool, has_typography: bool, has_visual: bool}
     */
    protected function detectAssetSignals(Asset $asset): array
    {
        return [
            'has_text' => $this->assetHasText($asset),
            'has_typography' => $this->assetHasTypographyMetadata($asset),
            'has_visual' => $this->assetHasVisual($asset),
        ];
    }

    /**
     * Title or approved text/textarea/richtext metadata (aligned with BrandComplianceService tone input).
     */
    protected function assetHasText(Asset $asset): bool
    {
        if (is_string($asset->title) && trim($asset->title) !== '') {
            return true;
        }

        $rows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->whereIn('metadata_fields.type', ['text', 'textarea', 'richtext'])
            ->select('asset_metadata.value_json')
            ->get();

        foreach ($rows as $row) {
            $str = $this->extractStringFromValueJson($row->value_json);
            if ($str !== null && trim($str) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Font-related metadata (aligned with BrandComplianceService typography input).
     */
    protected function assetHasTypographyMetadata(Asset $asset): bool
    {
        $rows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->where(function ($q) {
                $q->where('metadata_fields.key', 'like', '%font%')
                    ->orWhere('metadata_fields.key', 'like', '%typography%');
            })
            ->select('asset_metadata.value_json')
            ->limit(1)
            ->get();

        foreach ($rows as $row) {
            $str = $this->extractStringFromValueJson($row->value_json);
            if ($str !== null && trim($str) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function assetHasVisual(Asset $asset): bool
    {
        $mime = $asset->mime_type ?? '';

        return is_string($mime) && str_starts_with($mime, 'image/');
    }

    /**
     * Mirrors BrandComplianceService::extractStringFromValueJson for value_json reads.
     */
    protected function extractStringFromValueJson(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            $decoded = json_decode($v, true);

            return is_string($decoded) ? $decoded : (is_array($decoded) ? ($decoded['value'] ?? $decoded['text'] ?? null) : null);
        }
        if (is_array($v)) {
            return $v['value'] ?? $v['text'] ?? null;
        }

        return null;
    }

    /**
     * @param  list<array{asset_id: string, signals: array{has_text: bool, has_typography: bool, has_visual: bool}, tone_applicable: bool, typography_applicable: bool}>  $perAssetSignals
     */
    protected function mergeSignalBreakdown(array $base, array $perAssetSignals): array
    {
        $aggregate = [
            'has_text' => false,
            'has_typography' => false,
            'has_visual' => false,
        ];
        foreach ($perAssetSignals as $row) {
            $s = $row['signals'];
            $aggregate['has_text'] = $aggregate['has_text'] || $s['has_text'];
            $aggregate['has_typography'] = $aggregate['has_typography'] || $s['has_typography'];
            $aggregate['has_visual'] = $aggregate['has_visual'] || $s['has_visual'];
        }

        $base['signals'] = $aggregate;
        $base['per_asset'] = $perAssetSignals;

        return $base;
    }

    /**
     * Signal-aware confidence: does not change the compliance score, only how much we trust the EBI rollup.
     * Base 0.6; slight reductions when text/typography signals are absent; stricter ceiling when few signals are present.
     *
     * @param  array{has_text: bool, has_typography: bool, has_visual: bool}  $signals
     */
    protected function confidenceForSignals(array $signals): float
    {
        $hasText = $signals['has_text'];
        $hasTypography = $signals['has_typography'];
        $hasVisual = $signals['has_visual'];

        $signalCount = (int) $hasText + (int) $hasTypography + (int) $hasVisual;

        $confidence = 0.6;
        if (! $hasText) {
            $confidence -= 0.05;
        }
        if (! $hasTypography) {
            $confidence -= 0.05;
        }

        $maxForSignalCount = $signalCount >= 2 ? 0.9 : 0.7;

        return round(max(0.0, min($confidence, $maxForSignalCount)), 2);
    }

    /**
     * Annotate breakdown with applicability (no score penalty — compliance score unchanged).
     *
     * @param  array{has_text: bool, has_typography: bool, has_visual: bool}  $signals
     */
    protected function applySignalInterpretationToBreakdown(array $breakdown, array $signals): array
    {
        $toneApplicable = $signals['has_text'];
        $typographyApplicable = $signals['has_typography'];

        $breakdown['applicability'] = [
            'tone' => $toneApplicable,
            'typography' => $typographyApplicable,
        ];

        $breakdown['interpretation'] = [
            'tone' => $toneApplicable ? 'applicable' : 'not_applicable',
            'typography' => $typographyApplicable ? 'applicable' : 'not_applicable',
        ];

        return $breakdown;
    }

    /**
     * Cosine similarity in [-1, 1]; same formula as BrandComplianceService (vectors need not be pre-normalized).
     *
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b) || count($a) !== count($b)) {
            return 0.0;
        }
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $v) {
            $w = $b[$i] ?? 0;
            $dot += $v * $w;
            $normA += $v * $v;
            $normB += $w * $w;
        }
        $denom = sqrt($normA) * sqrt($normB);
        if ($denom < 1e-10) {
            return 0.0;
        }

        return (float) ($dot / $denom);
    }

    /**
     * Style-reference embeddings only; tier-weighted top-5; noise floor; variance + stability; identity/style blend.
     *
     * @param  array<string, bool>  $signalBreakdown
     * @return array<string, mixed>
     */
    protected function buildReferenceSimilarityBreakdown(
        Asset $asset,
        Brand $brand,
        array $assetVec,
        array $signalBreakdown,
        AssetContextType $contextType
    ): array {
        $refs = $this->queryStyleReferencesWithEmbeddings($brand);
        $promotedEntries = $this->collectPromotedBrandReferenceAssetEntries($brand);
        $braTotal = Schema::hasTable('brand_reference_assets')
            ? BrandReferenceAsset::query()->where('brand_id', $brand->id)->count()
            : 0;
        $referenceCount = $refs->count() + $braTotal;
        $tierUsage = $this->mergePromotedTierUsage($this->aggregateTierUsage($refs), $promotedEntries);
        $poolEligible = $refs->count() + count($promotedEntries);

        $hasPrimaryColumn = Schema::hasColumn('brand_visual_references', 'is_primary');
        $hasPrimary = $hasPrimaryColumn && $refs->contains(fn ($r) => (bool) ($r->is_primary ?? false));

        $referenceQuality = [
            'has_primary' => $hasPrimary,
            'reference_count' => $referenceCount,
            'mean' => null,
            'variance' => null,
        ];

        $minRefs = ReferenceSimilarityCalculator::MIN_STYLE_REFERENCES_FOR_EMBEDDING;

        if ($poolEligible < $minRefs) {
            Log::info('[EBI] Fallback: insufficient style references for embedding path', [
                'brand_id' => $brand->id,
                'reference_count' => $referenceCount,
                'pool_eligible' => $poolEligible,
                'min_required' => $minRefs,
            ]);

            return $this->buildIdentityFallbackReferenceBlock(
                $referenceCount,
                $tierUsage,
                $signalBreakdown,
                $referenceQuality,
                'reference_count_below_threshold',
                $contextType
            );
        }

        if ($assetVec === []) {
            Log::info('[EBI] Fallback: missing asset embedding vector', [
                'asset_id' => $asset->id,
            ]);

            return $this->buildIdentityFallbackReferenceBlock(
                $referenceCount,
                $tierUsage,
                $signalBreakdown,
                $referenceQuality,
                'missing_asset_embedding',
                $contextType
            );
        }

        $matchesContext = static function (?string $ctx) use ($contextType): bool {
            if ($ctx === null || $ctx === '') {
                return true;
            }

            return $ctx === $contextType->value;
        };

        $hasCtxCol = Schema::hasColumn('brand_visual_references', 'context_type');
        $filteredRefs = $refs->filter(function (BrandVisualReference $r) use ($matchesContext, $hasCtxCol) {
            $ctx = $hasCtxCol ? ($r->context_type ?? null) : null;

            return $matchesContext($ctx);
        });
        $filteredPromoted = array_values(array_filter($promotedEntries, fn ($p) => $matchesContext($p['context_type'] ?? null)));

        $matchedCount = $filteredRefs->count() + count($filteredPromoted);
        $contextFilterFallback = $matchedCount < ReferenceSimilarityCalculator::MIN_CONTEXT_MATCHED_REFS;
        $poolRefs = $contextFilterFallback ? $refs : $filteredRefs;
        $poolPromoted = $contextFilterFallback ? $promotedEntries : $filteredPromoted;

        $styleWeight = $contextFilterFallback
            ? ReferenceSimilarityCalculator::LOW_REF_STYLE_WEIGHT
            : ReferenceSimilarityCalculator::DEFAULT_STYLE_WEIGHT;

        $identityScore = ReferenceSimilarityCalculator::identityFallbackScore($signalBreakdown);

        $pairScores = [];
        foreach ($poolRefs as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if ($refVec === [] || ! $this->isSameVectorLength($assetVec, $refVec)) {
                continue;
            }
            $sim = $this->cosineSimilarity($assetVec, $refVec);
            $sim = max(0.0, min(1.0, $sim));
            if ($sim <= ReferenceSimilarityCalculator::NOISE_SIMILARITY_FLOOR) {
                continue;
            }
            $pairScores[] = [
                'id' => 'bvr:'.$ref->id,
                'similarity' => $sim,
                'weight' => $ref->effectiveWeight(),
            ];
        }
        foreach ($poolPromoted as $p) {
            $refVec = $p['vector'];
            if ($refVec === [] || ! $this->isSameVectorLength($assetVec, $refVec)) {
                continue;
            }
            $sim = $this->cosineSimilarity($assetVec, $refVec);
            $sim = max(0.0, min(1.0, $sim));
            if ($sim <= ReferenceSimilarityCalculator::NOISE_SIMILARITY_FLOOR) {
                continue;
            }
            $pairScores[] = [
                'id' => $p['id'],
                'similarity' => $sim,
                'weight' => $p['weight'],
            ];
        }

        if ($pairScores === []) {
            Log::info('[EBI] Fallback: no valid reference pairs after noise filter or dimension mismatch', [
                'asset_id' => $asset->id,
                'brand_id' => $brand->id,
            ]);

            return $this->buildIdentityFallbackReferenceBlock(
                $referenceCount,
                $tierUsage,
                $signalBreakdown,
                $referenceQuality,
                'no_valid_pairs',
                $contextType
            );
        }

        usort($pairScores, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $topMatches = array_slice($pairScores, 0, ReferenceSimilarityCalculator::TOP_N);
        $weights = array_column($topMatches, 'weight');
        $sims = array_column($topMatches, 'similarity');
        $weightSum = array_sum($weights);
        if ($weightSum < 1e-10) {
            return $this->buildIdentityFallbackReferenceBlock(
                $referenceCount,
                $tierUsage,
                $signalBreakdown,
                $referenceQuality,
                'zero_weight_sum',
                $contextType
            );
        }

        $aggregate = ReferenceSimilarityCalculator::weightedMean($sims, $weights);
        $aggregate = max(0.0, min(1.0, $aggregate));
        $variance = ReferenceSimilarityCalculator::populationVariance($sims);
        $styleBoost = ReferenceSimilarityCalculator::varianceStyleBoost($variance);
        $styleAdjusted = max(0.0, min(1.0, $aggregate + $styleBoost));
        $combined = ReferenceSimilarityCalculator::blendIdentityAndStyle($identityScore, $styleAdjusted, $styleWeight);

        $stabilityLabel = ReferenceSimilarityCalculator::stabilityLabel($variance);
        $band = ReferenceSimilarityCalculator::confidenceBand(true, false, $variance);

        $clusterSpread = count($sims) > 0 ? max($sims) - min($sims) : 0.0;
        $topMatchIds = array_map(fn ($m) => $m['id'], $topMatches);

        $referenceQuality['mean'] = round($combined, 2);
        $referenceQuality['variance'] = round($variance, 3);

        return [
            'reference_similarity' => [
                'used' => true,
                'fallback_used' => false,
                'score' => round($combined, 4),
                'score_percent' => (int) round($combined * 100),
                'normalized' => round($combined, 4),
                'style_similarity_mean' => round($aggregate, 4),
                'confidence' => ReferenceSimilarityCalculator::bandToNumericConfidence($band),
                'reference_count' => $referenceCount,
                'weighted' => true,
                'top_match_ids' => $topMatchIds,
                'variance' => round($variance, 4),
                'stability' => $stabilityLabel,
                'style_only' => true,
                'context_type' => $contextType->value,
                'context_matched_count' => $matchedCount,
                'context_filter_fallback' => $contextFilterFallback,
                'style_weight' => $styleWeight,
            ],
            'identity_style_blend' => [
                'identity' => round($identityScore, 4),
                'style' => round($aggregate, 4),
                'style_variance_boost' => round($styleBoost, 4),
                'style_adjusted' => round($styleAdjusted, 4),
                'combined' => round($combined, 4),
                'style_weight' => $styleWeight,
            ],
            'normalized_similarity' => $combined,
            'reference_quality' => $referenceQuality,
            'reference_stability' => [
                'consistent' => $variance < ReferenceSimilarityCalculator::VARIANCE_STABILITY_THRESHOLD,
                'stability' => $stabilityLabel,
                'variance' => round($variance, 4),
            ],
            'reference_cluster' => [
                'spread' => round($clusterSpread, 3),
                'tight_cluster' => $clusterSpread < 0.15,
            ],
            'reference_tier_usage' => $tierUsage,
            'confidence_band' => $band,
        ];
    }

    /**
     * @param  array{has_primary: bool, reference_count: int, mean: float|null, variance: float|null}  $referenceQuality
     */
    protected function buildIdentityFallbackReferenceBlock(
        int $referenceCount,
        array $tierUsage,
        array $signalBreakdown,
        array $referenceQuality,
        string $reason,
        AssetContextType $contextType,
    ): array {
        $fb = ReferenceSimilarityCalculator::identityFallbackScore($signalBreakdown);

        Log::info('[EBI] Identity fallback scoring', [
            'reason' => $reason,
            'fallback_score' => $fb,
            'reference_count' => $referenceCount,
        ]);

        $referenceQuality['mean'] = round($fb, 2);
        $referenceQuality['variance'] = null;

        return [
            'reference_similarity' => [
                'used' => false,
                'fallback_used' => true,
                'score' => round($fb, 4),
                'score_percent' => (int) round($fb * 100),
                'normalized' => round($fb, 4),
                'style_similarity_mean' => null,
                'confidence' => ReferenceSimilarityCalculator::bandToNumericConfidence('low'),
                'reference_count' => $referenceCount,
                'weighted' => false,
                'top_match_ids' => [],
                'variance' => null,
                'stability' => null,
                'style_only' => true,
                'context_type' => $contextType->value,
                'context_matched_count' => null,
                'context_filter_fallback' => true,
                'style_weight' => null,
            ],
            'identity_style_blend' => [
                'identity' => round($fb, 4),
                'style' => null,
                'style_variance_boost' => null,
                'style_adjusted' => null,
                'combined' => round($fb, 4),
                'style_weight' => null,
            ],
            'normalized_similarity' => $fb,
            'reference_quality' => $referenceQuality,
            'reference_stability' => [
                'consistent' => false,
                'stability' => 'diverse',
                'variance' => null,
            ],
            'reference_cluster' => [
                'spread' => null,
                'tight_cluster' => false,
            ],
            'reference_tier_usage' => $tierUsage,
            'confidence_band' => 'low',
        ];
    }

    /**
     * When embedding style is low but logo/color signals are strong, avoid a harsh "off brand" label.
     *
     * @param  array<string, bool>  $signalBreakdown
     */
    protected function shouldPreferPartialOverOffBrand(array $signalBreakdown, array $refBlock): bool
    {
        $identity = ReferenceSimilarityCalculator::identityFallbackScore($signalBreakdown);
        if ($identity < 0.65) {
            return false;
        }

        $blend = $refBlock['identity_style_blend'] ?? null;
        if (! is_array($blend) || ! isset($blend['style'])) {
            return false;
        }

        $style = (float) $blend['style'];
        if ($style >= 0.45) {
            return false;
        }

        if (empty($refBlock['reference_similarity']['used'])) {
            return false;
        }

        $hasLogo = ($signalBreakdown['has_logo'] ?? false) === true;
        $hasColors = ($signalBreakdown['has_brand_colors'] ?? false) === true;

        return $hasLogo && $hasColors;
    }

    /**
     * Actionable suggestions from EBI breakdown (reference similarity + applicability).
     * Priority: reference → typography → tone. At most two strings.
     *
     * @return array{recommendations: list<string>}
     */
    public function generateRecommendations(array $breakdown): array
    {
        if (($breakdown['alignment_state'] ?? null) === BrandAlignmentState::INSUFFICIENT_EVIDENCE->value) {
            return ['recommendations' => $breakdown['recommendations'] ?? []];
        }

        if (($breakdown['level'] ?? null) === 'high') {
            return ['recommendations' => []];
        }

        if (($breakdown['domain_relevance']['score'] ?? 1) < 0.3) {
            return [
                'recommendations' => [
                    'This asset may not match your brand\'s domain or subject matter',
                    'Consider using content more aligned with your brand\'s core themes',
                ],
            ];
        }

        if (($breakdown['insufficient_signal'] ?? false) === true) {
            return [
                'recommendations' => [
                    'Add reference images to establish brand alignment',
                    'Provide more brand context (typography, tone, or tags)',
                ],
            ];
        }

        $ref = $breakdown['reference_similarity'] ?? [];
        $used = ! empty($ref['used']);
        $refPercent = isset($ref['score_percent']) && is_numeric($ref['score_percent'])
            ? (int) $ref['score_percent']
            : (isset($ref['score']) && is_numeric($ref['score']) && (float) $ref['score'] <= 1.0
                ? (int) round((float) $ref['score'] * 100)
                : (isset($ref['score']) && is_numeric($ref['score']) ? (int) $ref['score'] : null));

        $referenceMessage = null;
        $align = $breakdown['alignment_state'] ?? null;
        if ($align === BrandAlignmentState::INSUFFICIENT_EVIDENCE->value) {
            $referenceMessage = null;
        } elseif ($used && $refPercent !== null) {
            if ($align === BrandAlignmentState::PARTIAL_ALIGNMENT->value && ! empty($breakdown['style_deviation_reason'])) {
                $referenceMessage = 'This asset aligns with core brand elements but differs in visual tone from current references.';
            } elseif ($align === BrandAlignmentState::OFF_BRAND->value || $refPercent < 40) {
                $referenceMessage = 'This asset aligns with core brand elements but differs in visual tone from current references.';
            } elseif ($align === BrandAlignmentState::PARTIAL_ALIGNMENT->value || $refPercent <= 70) {
                $referenceMessage = 'Visual style partially aligns — refine to better match brand look';
            }
        } else {
            $referenceMessage = 'Add brand style reference images to improve alignment measurement';
        }

        $app = $breakdown['applicability'] ?? [];
        $typographyMessage = ($app['typography'] ?? true) === false
            ? 'Add typography to better match brand voice'
            : null;
        $toneMessage = ($app['tone'] ?? true) === false
            ? 'Include text to evaluate tone alignment'
            : null;

        $ordered = [];
        if ($referenceMessage !== null) {
            $ordered[] = $referenceMessage;
        }
        if ($typographyMessage !== null) {
            $ordered[] = $typographyMessage;
        }
        if ($toneMessage !== null) {
            $ordered[] = $toneMessage;
        }

        return [
            'recommendations' => array_slice($ordered, 0, 2),
        ];
    }

    /**
     * Maps numeric score to alignment level; may return "unknown" when signal is too weak to interpret.
     */
    protected function mapScoreToLevel(int $score, float $signalStrength, float $confidence, float $domainRelevance = 1.0): string
    {
        if ($this->isInsufficientSignal($signalStrength, $confidence, $domainRelevance)) {
            return 'unknown';
        }
        if ($score < 50) {
            return 'low';
        }
        if ($score <= 75) {
            return 'medium';
        }

        return 'high';
    }

    /**
     * Weighted sum of available evaluation signals (0–1).
     *
     * TODO: Next phase — incorporate approved tags into signalStrength.
     */
    private function computeSignalStrength(array $signals, array $referenceSimilarity): float
    {
        $s = 0.0;

        if (! empty($signals['has_visual'])) {
            $s += 0.3;
        }

        if (! empty($signals['has_text'])) {
            $s += 0.3;
        }

        if (! empty($signals['has_typography'])) {
            $s += 0.2;
        }

        if (! empty($referenceSimilarity['used'])
            && (($referenceSimilarity['confidence'] ?? 0) > 0.5)) {
            $s += 0.2;
        }

        return round(min(1.0, $s), 2);
    }

    private function applyConfidenceAndSignalAdjustments(
        int $score,
        float $confidence,
        float $signalStrength,
        array $referenceSimilarity
    ): int {
        if ($signalStrength < 0.3) {
            $score = min($score, 40);
        } elseif ($signalStrength < 0.5) {
            $score = min($score, 55);
        }

        if (empty($referenceSimilarity['used'])) {
            $score = min($score, 65);
        }

        if ($confidence < 0.5) {
            $score -= 10;
        } elseif ($confidence < 0.6) {
            $score -= 5;
        }

        return max(0, min(100, $score));
    }

    private function isInsufficientSignal(float $signalStrength, float $confidence, float $domainRelevance = 1.0): bool
    {
        return $signalStrength < 0.4 || $confidence < 0.45 || $domainRelevance < 0.3;
    }

    /**
     * Tag / DNA keyword overlap → 0–1 (0.5 = neutral when tags or brand keywords are missing).
     *
     * TODO:
     * Add category-aware domain weighting
     * Add generative validation override
     */
    private function computeDomainRelevance(Asset $asset, Brand $brand): float
    {
        $tagQuery = DB::table('asset_tags')->where('asset_id', $asset->id);
        if (Schema::hasColumn('asset_tags', 'approved')) {
            $tagQuery->where('approved', true);
        } else {
            $tagQuery->whereIn('source', ['manual', 'user', 'manual_override']);
        }

        $tags = $tagQuery->pluck('tag')
            ->map(fn ($t) => strtolower(trim((string) $t)))
            ->filter()
            ->values()
            ->all();

        $keywords = $this->collectBrandDomainKeywordStrings($brand);

        if ($tags === [] || $keywords === []) {
            return 0.5;
        }

        $matches = 0;
        foreach ($tags as $tag) {
            foreach ($keywords as $keyword) {
                if ($keyword === '' || mb_strlen($keyword) < 2) {
                    continue;
                }
                if (str_contains($tag, $keyword) || str_contains($keyword, $tag)) {
                    $matches++;
                    break;
                }
            }
        }

        $ratio = $matches / max(count($tags), 1);

        return round(min(1.0, $ratio), 2);
    }

    /**
     * Style-reference embeddings only; top-5 weighted by reference tier weight.
     */
    private function computeEmbeddingDomainRelevance(Asset $asset, Brand $brand): ?float
    {
        $assetEmbedding = AssetEmbedding::query()
            ->where('asset_id', $asset->id)
            ->first();

        if (! $assetEmbedding || empty($assetEmbedding->embedding_vector)) {
            return null;
        }

        $assetVec = array_values($assetEmbedding->embedding_vector ?? []);

        $q = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector');

        if (Schema::hasColumn('brand_visual_references', 'reference_type')) {
            $q->where(function ($sub) {
                $sub->where('reference_type', BrandVisualReference::REFERENCE_TYPE_STYLE)
                    ->orWhere(function ($sub2) {
                        $sub2->whereNull('reference_type')
                            ->where('type', '!=', BrandVisualReference::TYPE_LOGO);
                    });
            });
        } else {
            $q->where('type', '!=', BrandVisualReference::TYPE_LOGO);
        }

        $refs = $q->limit(30)->get()->filter(fn (BrandVisualReference $r) => $r->isStyleReferenceForSimilarity());

        $promotedEntries = $this->collectPromotedBrandReferenceAssetEntries($brand);

        $pairs = [];
        foreach ($refs as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if (! $this->isSameVectorLength($assetVec, $refVec)) {
                continue;
            }

            $sim = max(0.0, min(1.0, $this->cosineSimilarity($assetVec, $refVec)));
            $pairs[] = ['sim' => $sim, 'w' => $ref->effectiveWeight()];
        }
        foreach ($promotedEntries as $p) {
            $refVec = $p['vector'];
            if (! $this->isSameVectorLength($assetVec, $refVec)) {
                continue;
            }
            $sim = max(0.0, min(1.0, $this->cosineSimilarity($assetVec, $refVec)));
            $pairs[] = ['sim' => $sim, 'w' => $p['weight']];
        }

        if ($pairs === []) {
            return null;
        }

        usort($pairs, fn ($a, $b) => $b['sim'] <=> $a['sim']);
        $top = array_slice($pairs, 0, 5);
        $wSum = array_sum(array_column($top, 'w'));
        if ($wSum < 1e-10) {
            return null;
        }
        $acc = 0.0;
        foreach ($top as $p) {
            $acc += $p['sim'] * $p['w'];
        }

        return round($acc / $wSum, 2);
    }

    /**
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     */
    private function isSameVectorLength(array $a, array $b): bool
    {
        return $a !== [] && $b !== [] && count($a) === count($b);
    }

    /**
     * Flatten brand DNA strings into searchable keyword tokens (aligned with extractBrandContextForInsight paths).
     *
     * @return list<string>
     */
    private function collectBrandDomainKeywordStrings(Brand $brand): array
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        if (! is_array($payload)) {
            return [];
        }

        $personality = is_array($payload['personality'] ?? null) ? $payload['personality'] : [];
        $visual = is_array($payload['visual'] ?? null) ? $payload['visual'] : [];
        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];

        $chunks = [];
        foreach ($personality as $v) {
            if (is_string($v) && trim($v) !== '') {
                $chunks[] = $v;
            }
        }
        foreach ($visual as $v) {
            if (is_string($v) && trim($v) !== '') {
                $chunks[] = $v;
            }
        }

        $toneKeywords = $rules['tone_keywords'] ?? null;
        if (is_array($toneKeywords)) {
            foreach ($toneKeywords as $item) {
                if (is_string($item)) {
                    $chunks[] = $item;
                } elseif (is_array($item)) {
                    $t = $item['label'] ?? $item['value'] ?? $item['text'] ?? '';
                    if (is_string($t) && trim($t) !== '') {
                        $chunks[] = $t;
                    }
                }
            }
        }

        $tokens = [];
        foreach ($chunks as $chunk) {
            foreach (preg_split('/[,;]+/u', $chunk) as $part) {
                $part = strtolower(trim($part));
                if ($part !== '') {
                    $tokens[] = $part;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Lightweight vision validation when references are absent and confidence is low.
     */
    private function shouldRunGenerativeValidation(Asset $asset, array $breakdown): bool
    {
        if (! $this->assetHasVisual($asset)) {
            return false;
        }

        return ($breakdown['reference_similarity']['used'] ?? false) === false
            && ($breakdown['confidence'] ?? 1) < 0.6;
    }

    /**
     * @return array{score: int, confidence: float}|null
     */
    private function runGenerativeValidation(Asset $asset, Brand $brand, bool $dryRun): ?array
    {
        if ($dryRun) {
            return null;
        }

        if (! $this->assetHasVisual($asset)) {
            return null;
        }

        $imageDataUrl = $this->aiMetadataGenerationService->fetchThumbnailForVisionAnalysis($asset);
        if ($imageDataUrl === null || $imageDataUrl === '') {
            Log::info('[EBI] Generative validation skipped: no thumbnail for vision', ['asset_id' => $asset->id]);

            return null;
        }

        $brand->loadMissing('brandModel');
        $ctx = $this->extractBrandContextForInsight($brand);
        $modelKey = 'gpt-4o-mini';
        $modelName = config("ai.models.{$modelKey}.model_name", 'gpt-4o-mini');

        $system = "You are evaluating if an image fits a brand's visual identity.";
        $prompt = $system."\n\n"
            .'Brand tone: '.($ctx['tone'] ?? 'Not specified')."\n"
            .'Brand style: '.($ctx['visual_style'] ?? 'Not specified')."\n\n"
            ."Respond with JSON only:\n"
            ."{\n"
            ."  \"score\": <integer 0-100>,\n"
            ."  \"confidence\": <number 0-1>\n"
            ."}\n"
            ."score = how well the image fits the brand's visual identity (0-100). "
            .'confidence = your confidence in that judgment (0-1).';

        try {
            $response = $this->aiProvider->analyzeImage($imageDataUrl, $prompt, [
                'model' => $modelName,
                'max_tokens' => 400,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[EBI] Generative validation failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->parseGenerativeValidationResponse($response['text'] ?? '');
    }

    /**
     * @return array{score: int, confidence: float}|null
     */
    private function parseGenerativeValidationResponse(string $text): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/m', $raw, $m)) {
            $raw = $m[1];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $score = $decoded['score'] ?? null;
        $conf = $decoded['confidence'] ?? null;
        if (! is_numeric($score) || ! is_numeric($conf)) {
            return null;
        }

        $score = (int) round(max(0.0, min(100.0, (float) $score)));
        $conf = (float) max(0.0, min(1.0, (float) $conf));

        return [
            'score' => $score,
            'confidence' => round($conf, 2),
        ];
    }

    /**
     * Top reference image matches by cosine similarity (admin thumbnails / tuning UI).
     *
     * @return list<array{reference_asset_id: string, cosine: float, score_int: int}>
     */
    public function topReferenceMatchesForAdmin(Asset $asset, int $limit = 3): array
    {
        $asset->loadMissing('brand');
        $brand = $asset->brand;
        if (! $brand) {
            return [];
        }

        $refs = $this->queryStyleReferencesWithEmbeddings($brand);
        $promotedEntries = $this->collectPromotedBrandReferenceAssetEntries($brand);

        $assetEmbedding = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        $assetVec = array_values($assetEmbedding?->embedding_vector ?? []);
        if ($assetVec === []) {
            return [];
        }

        $rows = [];
        foreach ($refs as $ref) {
            if ($ref->asset_id === null || $ref->asset_id === '') {
                continue;
            }
            $refVec = array_values($ref->embedding_vector ?? []);
            if ($refVec === [] || count($refVec) !== count($assetVec)) {
                continue;
            }
            $sim = $this->cosineSimilarity($assetVec, $refVec);
            $rows[] = [
                'reference_asset_id' => (string) $ref->asset_id,
                'cosine' => $sim,
                'score_int' => (int) round(max(0.0, min(1.0, $sim)) * 100),
            ];
        }
        foreach ($promotedEntries as $p) {
            $refVec = $p['vector'];
            if ($refVec === [] || count($refVec) !== count($assetVec)) {
                continue;
            }
            $sim = $this->cosineSimilarity($assetVec, $refVec);
            $rows[] = [
                'reference_asset_id' => (string) $p['asset_id'],
                'cosine' => $sim,
                'score_int' => (int) round(max(0.0, min(1.0, $sim)) * 100),
            ];
        }

        $dedup = [];
        foreach ($rows as $row) {
            $id = $row['reference_asset_id'];
            if (! isset($dedup[$id]) || $row['cosine'] > $dedup[$id]['cosine']) {
                $dedup[$id] = $row;
            }
        }
        $rows = array_values($dedup);
        usort($rows, fn ($a, $b) => $b['cosine'] <=> $a['cosine']);

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Visualization payload for the asset drawer debug overlay (coordinates normalized 0–1).
     *
     * @param  array<string, mixed>  $withRefs
     * @return array{
     *     color_regions: list<array<string, mixed>>,
     *     logo_detections: list<array<string, mixed>>,
     *     attention_map: string|null,
     *     top_references: list<array{id: string, similarity: float}>
     * }
     */
    protected function buildBrandIntelligenceDebugPayload(Asset $asset, Brand $brand, array $withRefs): array
    {
        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        $ebi = is_array($meta['ebi_debug'] ?? null) ? $meta['ebi_debug'] : [];

        $base = [
            'color_regions' => $this->heuristicColorRegionsFromAssetMetadata($asset),
            'logo_detections' => $this->heuristicLogoDetectionsFromDetail($withRefs['logo_detection'] ?? []),
            'attention_map' => null,
            'top_references' => $this->buildTopReferencesDebugList($asset, $brand),
        ];

        if (isset($ebi['color_regions']) && is_array($ebi['color_regions'])) {
            $base['color_regions'] = array_values($ebi['color_regions']);
        }
        if (isset($ebi['logo_detections']) && is_array($ebi['logo_detections'])) {
            $base['logo_detections'] = array_values($ebi['logo_detections']);
        }
        if (array_key_exists('attention_map', $ebi)) {
            $v = $ebi['attention_map'];
            $base['attention_map'] = is_string($v) && $v !== '' ? $v : null;
        }
        if (isset($ebi['top_references']) && is_array($ebi['top_references'])) {
            $base['top_references'] = array_values($ebi['top_references']);
        }

        return $base;
    }

    /**
     * Approximate dominant-color regions using known metadata bundles (center / subject / high_contrast).
     *
     * @return list<array{x: float, y: float, width: float, height: float, color: string, score: float}>
     */
    protected function heuristicColorRegionsFromAssetMetadata(Asset $asset): array
    {
        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        $boxes = [
            'center' => ['x' => 0.35, 'y' => 0.32, 'width' => 0.3, 'height' => 0.28],
            'subject' => ['x' => 0.18, 'y' => 0.12, 'width' => 0.64, 'height' => 0.56],
            'high_contrast' => ['x' => 0.08, 'y' => 0.06, 'width' => 0.84, 'height' => 0.38],
        ];
        $bundles = [
            'dominant_colors_center' => 'center',
            'dominant_colors_subject' => 'subject',
            'dominant_colors_high_contrast' => 'high_contrast',
        ];
        $out = [];
        foreach ($bundles as $key => $region) {
            $raw = $meta[$key] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : null;
            }
            if (! is_array($raw)) {
                continue;
            }
            $b = $boxes[$region] ?? $boxes['center'];
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $hexRaw = $row['hex'] ?? null;
                if (! is_string($hexRaw) || trim($hexRaw) === '') {
                    continue;
                }
                $digits = strtoupper(ltrim(trim($hexRaw), '#'));
                if (strlen($digits) !== 6 || ! ctype_xdigit($digits)) {
                    continue;
                }
                $cov = $row['coverage'] ?? null;
                $score = is_numeric($cov) ? (float) $cov : 0.55;
                $score = max(0.0, min(1.0, $score));
                $out[] = array_merge($b, [
                    'color' => '#'.$digits,
                    'score' => round($score, 4),
                ]);
            }
        }

        return array_slice($out, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $logoDetection
     * @return list<array{x: float, y: float, width: float, height: float, method: string, confidence: float}>
     */
    protected function heuristicLogoDetectionsFromDetail(array $logoDetection): array
    {
        $out = [];
        if (($logoDetection['ocr_matched'] ?? false) === true) {
            $out[] = [
                'x' => 0.03,
                'y' => 0.04,
                'width' => 0.55,
                'height' => 0.11,
                'method' => 'OCR',
                'confidence' => 0.82,
            ];
        }
        $emb = $logoDetection['embedding_similarity'] ?? null;
        if ($emb !== null && is_numeric($emb) && (float) $emb >= self::LOGO_EMBEDDING_SIMILARITY_THRESHOLD) {
            $c = max(0.0, min(1.0, (float) $emb));
            $out[] = [
                'x' => 0.42,
                'y' => 0.08,
                'width' => 0.52,
                'height' => 0.22,
                'method' => 'Embedding',
                'confidence' => round($c, 4),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, similarity: float}>
     */
    protected function buildTopReferencesDebugList(Asset $asset, Brand $brand): array
    {
        $rows = $this->topReferenceMatchesForAdmin($asset, 5);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (string) $r['reference_asset_id'],
                'similarity' => round((float) $r['cosine'], 4),
            ];
        }

        return $out;
    }
}
