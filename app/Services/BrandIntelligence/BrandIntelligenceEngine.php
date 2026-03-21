<?php

namespace App\Services\BrandIntelligence;

use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\BrandIntelligenceFeedback;
use App\Models\BrandVisualReference;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AiMetadataGenerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BrandIntelligenceEngine
{
    /**
     * Bump when scoring semantics change; allows parallel history rows per asset and idempotent skips.
     */
    public const ENGINE_VERSION = 'v1_generative_validation';

    public const AI_USAGE_TYPE = 'brand_intelligence_ai';

    /** Single-asset EBI path (default today). */
    public const SCORING_BASIS_SINGLE_ASSET = 'single_asset';

    /** Future execution / multi-asset rollup. */
    public const SCORING_BASIS_MULTI_ASSET = 'multi_asset';

    public function __construct(
        protected AIProviderInterface $aiProvider,
        protected AiMetadataGenerationService $aiMetadataGenerationService,
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

        $signals = $this->detectAssetSignals($asset);
        $perAssetSignals = [[
            'asset_id' => $asset->id,
            'signals' => $signals,
            'tone_applicable' => $signals['has_text'],
            'typography_applicable' => $signals['has_typography'],
        ]];

        $refBlock = $this->buildReferenceSimilarityBreakdown($asset, $brand);
        $rs = $refBlock['reference_similarity'];
        $score = 50;
        if (! empty($rs['used']) && isset($rs['score']) && is_numeric($rs['score'])) {
            $score = (int) $rs['score'];
        }

        $normalizedSim = $refBlock['normalized_similarity'];
        $refConfidence = $rs['confidence'] ?? 0.0;
        if ($normalizedSim !== null && $refConfidence > 0.5) {
            if ($normalizedSim > 0.8) {
                $score += 5;
            } elseif ($normalizedSim < 0.4) {
                $score -= 5;
            }
            $score = max(0, min(100, $score));
        }

        $confidence = $this->confidenceForSignals($signals);

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

        if ($domainRelevance < 0.3) {
            $score -= 15;
        } elseif ($domainRelevance < 0.5) {
            $score -= 8;
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
                if ($aiScore < 0.4) {
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
        $level = $this->mapScoreToLevel($score, $signalStrength, $confidence, $domainRelevance);
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

        $recs = $this->generateRecommendations($withRefs);
        $withRefs['recommendations'] = $recs['recommendations'];

        $withRefs['_gate_confidence'] = $confidence;
        $withRefs['_gate_level'] = $level;
        $aiInsightPayload = $this->generateAIInsight($asset, $withRefs, $dryRun);
        unset($withRefs['_gate_confidence'], $withRefs['_gate_level']);

        $aiUsed = ($generativeValidationPayload['used'] ?? false) === true;
        if ($aiInsightPayload !== null && isset($aiInsightPayload['ai_insight']['text'])) {
            $withRefs['ai_insight'] = $aiInsightPayload['ai_insight'];
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
     * Compare asset embedding to brand visual reference embeddings (same brand, refs with embeddings).
     * Top-5 mean of cosine similarities (noise filtered), variance for stability. Does not call compliance.
     *
     * @return array{
     *     reference_similarity: array{
     *         score: int|null,
     *         confidence: float,
     *         reference_count: int,
     *         normalized: float|null,
     *         used: bool,
     *         weighted: bool,
     *         top_match_ids: list<int|string>,
     *         variance: float|null
     *     },
     *     normalized_similarity: float|null,
     *     reference_quality: array{has_primary: bool, reference_count: int, mean: float|null, variance: float|null},
     *     reference_stability: array{consistent: bool},
     *     reference_cluster: array{spread: float|null, tight_cluster: bool}
     * }
     */
    protected function buildReferenceSimilarityBreakdown(Asset $asset, Brand $brand): array
    {
        $query = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector');

        if (Schema::hasColumn('brand_visual_references', 'is_primary')) {
            $query->orderByDesc('is_primary');
        }
        if (Schema::hasColumn('brand_visual_references', 'weight')) {
            $query->orderByDesc('weight');
        }

        $refs = $query->limit(30)->get();

        $referenceCount = $refs->count();

        $hasPrimaryColumn = Schema::hasColumn('brand_visual_references', 'is_primary');
        $hasPrimary = $hasPrimaryColumn && $refs->contains(fn ($r) => (bool) ($r->is_primary ?? false));

        $referenceQuality = [
            'has_primary' => $hasPrimary,
            'reference_count' => $referenceCount,
            'mean' => null,
            'variance' => null,
        ];

        $assetEmbedding = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        $assetVec = array_values($assetEmbedding?->embedding_vector ?? []);

        $emptySimilarity = [
            'score' => null,
            'confidence' => 0.0,
            'reference_count' => $referenceCount,
            'normalized' => null,
            'used' => false,
            'weighted' => false,
            'top_match_ids' => [],
            'variance' => null,
        ];

        $empty = [
            'reference_similarity' => $emptySimilarity,
            'normalized_similarity' => null,
            'reference_quality' => $referenceQuality,
            'reference_stability' => [
                'consistent' => false,
            ],
            'reference_cluster' => [
                'spread' => null,
                'tight_cluster' => false,
            ],
        ];

        if (empty($assetVec) || $referenceCount === 0) {
            return $empty;
        }

        $pairScores = [];
        foreach ($refs as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if ($refVec === [] || ! $this->isSameVectorLength($assetVec, $refVec)) {
                continue;
            }
            $sim = $this->cosineSimilarity($assetVec, $refVec);
            $sim = max(0.0, min(1.0, $sim));
            $pairScores[] = [
                'id' => $ref->id,
                'similarity' => $sim,
            ];
        }

        $pairScores = array_values(array_filter(
            $pairScores,
            fn (array $m) => $m['similarity'] > 0.35
        ));

        if ($pairScores === []) {
            return $empty;
        }

        usort($pairScores, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $topMatches = array_slice($pairScores, 0, 5);
        $top = array_column($topMatches, 'similarity');

        $aggregate = array_sum($top) / count($top);

        $normalized = max(0.0, min(1.0, $aggregate));
        $scoreInt = (int) round($normalized * 100);

        $variance = $this->computeVariance($top);

        $clusterSpread = max($top) - min($top);

        $validRefCount = count($pairScores);
        $confidence = round(min(1.0, 0.35 + 0.15 * min($validRefCount, 4)), 2);

        if (count($top) < 3) {
            $confidence = 0.3;
        }

        $confidence = max(0.0, min(1.0, round($confidence, 2)));

        $topMatchIds = array_map(fn ($m) => $m['id'], $topMatches);

        $referenceQuality['mean'] = round($aggregate, 2);
        $referenceQuality['variance'] = round($variance, 3);

        return [
            'reference_similarity' => [
                'score' => $scoreInt,
                'confidence' => $confidence,
                'reference_count' => $referenceCount,
                'normalized' => round($normalized, 2),
                'used' => true,
                'weighted' => false,
                'top_match_ids' => $topMatchIds,
                'variance' => round($variance, 3),
            ],
            'normalized_similarity' => $normalized,
            'reference_quality' => $referenceQuality,
            'reference_stability' => [
                'consistent' => $variance < 0.01,
            ],
            'reference_cluster' => [
                'spread' => round($clusterSpread, 3),
                'tight_cluster' => $clusterSpread < 0.15,
            ],
        ];
    }

    /**
     * Population variance of numeric values (0 if fewer than 2 values).
     *
     * @param  list<float>  $values
     */
    private function computeVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return $sum / count($values);
    }

    /**
     * Actionable suggestions from EBI breakdown (reference similarity + applicability).
     * Priority: reference → typography → tone. At most two strings.
     *
     * @return array{recommendations: list<string>}
     */
    public function generateRecommendations(array $breakdown): array
    {
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
        $refScore = isset($ref['score']) && is_numeric($ref['score']) ? (int) $ref['score'] : null;

        $referenceMessage = null;
        if ($used && $refScore !== null) {
            if ($refScore < 50) {
                $referenceMessage = 'Visual style differs from brand — consider aligning with approved imagery';
            } elseif ($refScore <= 75) {
                $referenceMessage = 'Visual style partially aligns — refine to better match brand look';
            }
        } else {
            $referenceMessage = 'Add brand reference images to improve alignment';
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
     * Mean of top-5 cosine similarities vs brand visual reference embeddings (0–1 per pair, aligned with reference scoring).
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

        $refs = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->limit(25)
            ->get();

        if ($refs->isEmpty()) {
            return null;
        }

        $scores = [];

        foreach ($refs as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if (! $this->isSameVectorLength($assetVec, $refVec)) {
                continue;
            }

            $sim = $this->cosineSimilarity($assetVec, $refVec);
            $scores[] = max(0.0, min(1.0, $sim));
        }

        if ($scores === []) {
            return null;
        }

        rsort($scores, SORT_NUMERIC);

        $top = array_slice($scores, 0, 5);

        return round(array_sum($top) / count($top), 2);
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

        $refs = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->whereIn('type', BrandVisualReference::IMAGERY_TYPES)
            ->get();

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
}
