<?php

namespace App\Services\BrandIntelligence;

use App\Enums\AssetContextType;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AiMetadataGenerationService;
use Illuminate\Support\Facades\Log;

/**
 * Parallel AI pass: structured visual + copy extraction vs Brand DNA, separate from embedding/reference scoring.
 */
final class CreativeIntelligenceAnalyzer
{
    public const AI_USAGE_TYPE = 'brand_intelligence_creative';

    public function __construct(
        protected AIProviderInterface $aiProvider,
        protected AiMetadataGenerationService $aiMetadataGenerationService,
    ) {}

    /**
     * @return array{
     *   creative_analysis: array|null,
     *   copy_alignment: array,
     *   context_analysis: array,
     *   visual_alignment_ai: array|null,
     *   overall_summary: ?string,
     *   brand_copy_conflict: bool,
     *   ebi_ai_trace: array
     * }
     */
    public function analyze(Asset $asset, Brand $brand, AssetContextType $heuristicContext, bool $dryRun): array
    {
        $trace = [
            'creative_ai_ran' => false,
            'copy_extracted' => false,
            'copy_alignment_scored' => false,
            'skipped' => true,
            'skip_reason' => null,
        ];

        if ($dryRun) {
            $trace['skip_reason'] = 'dry_run';

            return $this->emptyPayload($trace);
        }

        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if ($mime === '' || ! str_starts_with($mime, 'image/')) {
            $trace['skip_reason'] = 'not_image';

            return $this->emptyPayload($trace);
        }

        if ($heuristicContext === AssetContextType::LOGO_ONLY) {
            $trace['skip_reason'] = 'logo_only_context';

            return $this->emptyPayload($trace);
        }

        $imageDataUrl = $this->aiMetadataGenerationService->fetchThumbnailForVisionAnalysis($asset);
        if ($imageDataUrl === null || $imageDataUrl === '') {
            $trace['skip_reason'] = 'no_thumbnail_for_vision';

            return $this->emptyPayload($trace);
        }

        $dna = $this->extractBrandDnaForCopyAlignment($brand);
        $modelKey = 'gpt-4o-mini';
        $modelName = config("ai.models.{$modelKey}.model_name", 'gpt-4o-mini');

        $prompt = $this->buildVisionPrompt($heuristicContext, $dna);

        try {
            $response = $this->aiProvider->analyzeImage($imageDataUrl, $prompt, [
                'model' => $modelName,
                'max_tokens' => 1800,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[EBI Creative] Vision analysis failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $trace['skip_reason'] = 'ai_error: '.$e->getMessage();

            return $this->emptyPayload($trace);
        }

        $parsed = $this->parseCreativeJson($response['text'] ?? '');
        if ($parsed === null) {
            $trace['skip_reason'] = 'parse_failed';

            return $this->emptyPayload($trace);
        }

        $trace['creative_ai_ran'] = true;
        $trace['skipped'] = false;
        $trace['skip_reason'] = null;

        $creative = $parsed['creative_analysis'] ?? $parsed;
        if (! is_array($creative)) {
            $creative = [];
        }

        $hasText = $this->detectCopyExtracted($creative);
        $trace['copy_extracted'] = $hasText;

        $copyAlignment = $parsed['copy_alignment'] ?? null;
        if (! is_array($copyAlignment)) {
            $copyAlignment = [
                'score' => null,
                'alignment_state' => 'not_applicable',
                'confidence' => 0.0,
                'reasons' => ['Copy alignment block missing from model output.'],
            ];
        } else {
            $trace['copy_alignment_scored'] = ($copyAlignment['alignment_state'] ?? '') !== 'not_applicable'
                && isset($copyAlignment['score']) && is_numeric($copyAlignment['score']);
        }

        $ctxAnalysis = [
            'context_type_heuristic' => $heuristicContext->value,
            'context_type_ai' => is_string($creative['context_type'] ?? null) ? $creative['context_type'] : null,
            'scene_type' => $creative['scene_type'] ?? null,
            'lighting_type' => $creative['lighting_type'] ?? null,
            'mood' => $creative['mood'] ?? null,
        ];

        $visualAi = $parsed['visual_alignment'] ?? null;
        if (! is_array($visualAi)) {
            $visualAi = null;
        }

        $summary = is_string($parsed['overall_summary'] ?? null) ? trim((string) $parsed['overall_summary']) : null;
        $summary = $summary === '' ? null : $summary;
        $conflict = filter_var($parsed['brand_copy_conflict'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'creative_analysis' => $this->normalizeCreativeAnalysis($creative),
            'copy_alignment' => $this->normalizeCopyAlignment($copyAlignment, $hasText),
            'context_analysis' => $ctxAnalysis,
            'visual_alignment_ai' => $visualAi,
            'overall_summary' => $summary,
            'brand_copy_conflict' => $conflict,
            'ebi_ai_trace' => $trace,
        ];
    }

    /**
     * @return array{voice: ?string, tone: ?string, personality: ?string, positioning: ?string, promise: ?string, messaging: ?string}
     */
    public function extractBrandDnaForCopyAlignment(Brand $brand): array
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $personality = is_array($payload['personality'] ?? null) ? $payload['personality'] : [];
        $positioning = is_array($payload['positioning'] ?? null) ? $payload['positioning'] : [];
        $messaging = is_array($payload['messaging'] ?? null) ? $payload['messaging'] : [];
        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];

        $toneKeywords = $rules['tone_keywords'] ?? null;
        $toneStr = null;
        if (is_array($toneKeywords)) {
            $flat = [];
            foreach ($toneKeywords as $item) {
                if (is_string($item)) {
                    $flat[] = $item;
                } elseif (is_array($item)) {
                    $flat[] = (string) ($item['label'] ?? $item['value'] ?? $item['text'] ?? '');
                }
            }
            $flat = array_filter(array_map('trim', $flat));
            $toneStr = $flat !== [] ? implode(', ', $flat) : null;
        }

        return [
            'voice' => $this->str($personality['voice'] ?? $personality['brand_voice'] ?? null),
            'tone' => $this->str($personality['tone'] ?? null),
            'personality' => $this->str($personality['personality'] ?? $personality['brand_personality'] ?? null),
            'positioning' => $this->str($positioning['statement'] ?? $positioning['value_prop'] ?? $positioning['positioning'] ?? null),
            'promise' => $this->str($positioning['promise'] ?? null),
            'messaging' => $this->str(is_string($messaging['guidance'] ?? null) ? $messaging['guidance'] : (is_array($messaging['pillars'] ?? null) ? implode('; ', array_filter($messaging['pillars'])) : null)),
            'tone_keywords' => $toneStr,
        ];
    }

    protected function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array{creative_analysis: null, copy_alignment: array, context_analysis: array, visual_alignment_ai: null, ebi_ai_trace: array}
     */
    protected function emptyPayload(array $trace): array
    {
        return [
            'creative_analysis' => null,
            'copy_alignment' => [
                'score' => null,
                'alignment_state' => 'not_applicable',
                'confidence' => 0.0,
                'reasons' => [],
            ],
            'context_analysis' => [
                'context_type_heuristic' => null,
                'context_type_ai' => null,
                'scene_type' => null,
                'lighting_type' => null,
                'mood' => null,
            ],
            'visual_alignment_ai' => null,
            'overall_summary' => null,
            'brand_copy_conflict' => false,
            'ebi_ai_trace' => $trace,
        ];
    }

    protected function buildVisionPrompt(AssetContextType $heuristicContext, array $dna): string
    {
        $dnaJson = json_encode($dna, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return <<<PROMPT
You are a brand creative director. Analyze this image for Brand Intelligence: separate VISUAL traits from COPY (text in the image).

Brand DNA (evaluate copy against these when text exists):
{$dnaJson}

Heuristic context hint from filename/metadata: {$heuristicContext->value}

Return JSON only with this shape:
{
  "creative_analysis": {
    "context_type": "<one of: product_hero, lifestyle, digital_ad, social_post, logo_only, other>",
    "scene_type": "<short string>",
    "lighting_type": "<short string>",
    "mood": "<short string>",
    "detected_text": "<all readable text, space-separated or empty>",
    "headline_text": "<primary headline or empty>",
    "supporting_text": "<subcopy or empty>",
    "cta_text": "<CTA/button text or empty>",
    "voice_traits_detected": ["<trait>", "..."],
    "visual_traits_detected": ["<trait>", "..."]
  },
  "visual_alignment": {
    "summary": "<one sentence how visuals fit a premium brand look>",
    "fit_score": <0-100 integer estimate of visual brand fit from the image alone>,
    "confidence": <0-1>
  },
  "copy_alignment": {
    "score": <0-100 or null if no meaningful copy in image>,
    "alignment_state": "<aligned|partial|off_brand|not_applicable|insufficient>",
    "confidence": <0-1>,
    "reasons": ["<short bullet>", "..."]
  },
  "overall_summary": "<2-3 sentences: combine visual + copy; if copy is missing or illegible, do NOT penalize visual assessment>",
  "brand_copy_conflict": <true only if on-image copy clearly contradicts Brand DNA; otherwise false>
}

Rules:
- If there is no text or only trivial text (logos, watermarks), set copy_alignment.alignment_state to not_applicable or insufficient and copy_alignment.score null.
- voice_traits_detected / visual_traits_detected: short phrases.
- Do not invent long passages of text; detected_text should reflect what you actually see.
PROMPT;
    }

    protected function parseCreativeJson(string $text): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/m', $raw, $m)) {
            $raw = $m[1];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $creative
     */
    protected function detectCopyExtracted(array $creative): bool
    {
        foreach (['detected_text', 'headline_text', 'supporting_text', 'cta_text'] as $k) {
            $v = $creative[$k] ?? '';
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    protected function normalizeCreativeAnalysis(array $c): array
    {
        return [
            'context_type' => is_string($c['context_type'] ?? null) ? $c['context_type'] : null,
            'scene_type' => is_string($c['scene_type'] ?? null) ? $c['scene_type'] : null,
            'lighting_type' => is_string($c['lighting_type'] ?? null) ? $c['lighting_type'] : null,
            'mood' => is_string($c['mood'] ?? null) ? $c['mood'] : null,
            'detected_text' => is_string($c['detected_text'] ?? null) ? $c['detected_text'] : null,
            'headline_text' => is_string($c['headline_text'] ?? null) ? $c['headline_text'] : null,
            'supporting_text' => is_string($c['supporting_text'] ?? null) ? $c['supporting_text'] : null,
            'cta_text' => is_string($c['cta_text'] ?? null) ? $c['cta_text'] : null,
            'voice_traits_detected' => $this->stringList($c['voice_traits_detected'] ?? []),
            'visual_traits_detected' => $this->stringList($c['visual_traits_detected'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $ca
     */
    protected function normalizeCopyAlignment(array $ca, bool $hasText): array
    {
        $score = $ca['score'] ?? null;
        if ($score !== null && is_numeric($score)) {
            $score = (int) round(max(0, min(100, (float) $score)));
        } else {
            $score = null;
        }

        $state = is_string($ca['alignment_state'] ?? null) ? $ca['alignment_state'] : 'not_applicable';
        $conf = $ca['confidence'] ?? 0.0;
        $conf = is_numeric($conf) ? round(max(0.0, min(1.0, (float) $conf)), 2) : 0.0;

        $reasons = [];
        if (isset($ca['reasons']) && is_array($ca['reasons'])) {
            foreach ($ca['reasons'] as $r) {
                if (is_string($r) && trim($r) !== '') {
                    $reasons[] = trim($r);
                }
            }
        }

        if (! $hasText && ($state === 'aligned' || $state === 'partial' || $state === 'off_brand')) {
            $state = 'not_applicable';
            $score = null;
            $reasons[] = 'No extractable marketing copy in image.';
        }

        return [
            'score' => $score,
            'alignment_state' => $state,
            'confidence' => $conf,
            'reasons' => array_slice($reasons, 0, 8),
        ];
    }

    protected function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_slice($out, 0, 24);
    }
}
