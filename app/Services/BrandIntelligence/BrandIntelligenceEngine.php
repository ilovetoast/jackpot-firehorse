<?php

namespace App\Services\BrandIntelligence;

use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
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
    public const ENGINE_VERSION = 'v1_reference_embedding_v5_ai_insight_refine';

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

        $baseBreakdown = $this->mergeSignalBreakdown([
            'source' => 'ebi_asset_score',
            'scoring_basis' => self::SCORING_BASIS_SINGLE_ASSET,
            'source_asset_id' => $asset->id,
            'asset_ids_considered' => [$asset->id],
        ], $perAssetSignals);

        $withRefs = $this->applySignalInterpretationToBreakdown($baseBreakdown, $signals);
        $withRefs['reference_similarity'] = $refBlock['reference_similarity'];
        $level = $this->mapScoreToLevel($score);
        $withRefs['level'] = $level;

        $recs = $this->generateRecommendations($withRefs);
        $withRefs['recommendations'] = $recs['recommendations'];

        $withRefs['_gate_confidence'] = $confidence;
        $withRefs['_gate_level'] = $level;
        $aiInsightPayload = $this->generateAIInsight($asset, $withRefs, $dryRun);
        unset($withRefs['_gate_confidence'], $withRefs['_gate_level']);

        $aiUsed = false;
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
     * Uses mean of top-3 cosine similarities, then maps to 0–100. Does not call compliance.
     *
     * @return array{
     *     reference_similarity: array{
     *         score: int|null,
     *         confidence: float,
     *         reference_count: int,
     *         normalized: float|null,
     *         used: bool
     *     },
     *     normalized_similarity: float|null
     * }
     */
    protected function buildReferenceSimilarityBreakdown(Asset $asset, Brand $brand): array
    {
        $refs = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->whereIn('type', BrandVisualReference::IMAGERY_TYPES)
            ->get();

        $referenceCount = $refs->count();

        $assetEmbedding = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        $assetVec = array_values($assetEmbedding?->embedding_vector ?? []);

        $empty = [
            'reference_similarity' => [
                'score' => null,
                'confidence' => 0.0,
                'reference_count' => $referenceCount,
                'normalized' => null,
                'used' => false,
            ],
            'normalized_similarity' => null,
        ];

        if (empty($assetVec) || $referenceCount === 0) {
            return $empty;
        }

        $similarities = [];
        foreach ($refs as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if (empty($refVec) || count($refVec) !== count($assetVec)) {
                continue;
            }
            $similarities[] = $this->cosineSimilarity($assetVec, $refVec);
        }

        if ($similarities === []) {
            return $empty;
        }

        rsort($similarities, SORT_NUMERIC);
        $top = array_slice($similarities, 0, 3);
        $aggregate = array_sum($top) / count($top);

        $normalized = max(0.0, min(1.0, $aggregate));
        $scoreInt = (int) round($normalized * 100);

        $validRefCount = count($similarities);
        $confidence = round(min(1.0, 0.35 + 0.15 * min($validRefCount, 4)), 2);

        return [
            'reference_similarity' => [
                'score' => $scoreInt,
                'confidence' => $confidence,
                'reference_count' => $referenceCount,
                'normalized' => round($normalized, 2),
                'used' => true,
            ],
            'normalized_similarity' => $normalized,
        ];
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

    protected function mapScoreToLevel(int $score): string
    {
        if ($score < 50) {
            return 'low';
        }
        if ($score <= 75) {
            return 'medium';
        }

        return 'high';
    }
}
