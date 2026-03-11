<?php

namespace App\Services\BrandDNA;

use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Performs targeted visual extraction on classified PDF pages.
 * Uses page-type-specific prompts and allowed-fields policy.
 */
class PdfPageVisualExtractionService
{
    protected const SKIP_EXTRACTION_TYPES = ['table_of_contents', 'cover', 'contact', 'appendix'];

    public function __construct(
        protected PageTypeExtractionPromptBuilder $promptBuilder,
        protected ?AIProviderInterface $provider = null
    ) {
        $this->provider = $provider ?? app(AIProviderInterface::class);
    }

    /**
     * Extract brand signals from a classified page.
     *
     * @param array{page: int, page_type: string, confidence: float, title: ?string, signals_present: array, extraction_priority: string} $classification
     * @return array{page: int, page_type: string, extractions: array}
     */
    public function extractFromPage(string $imagePath, array $classification, ?string $ocrText = null): array
    {
        $pageNum = $classification['page'] ?? 0;
        $pageType = $classification['page_type'] ?? 'unknown';

        if (in_array($pageType, self::SKIP_EXTRACTION_TYPES, true)) {
            return [
                'page' => $pageNum,
                'page_type' => $pageType,
                'extractions' => [],
            ];
        }

        if (! file_exists($imagePath) || ! is_readable($imagePath)) {
            return ['page' => $pageNum, 'page_type' => $pageType, 'extractions' => []];
        }

        $allowedFields = $this->getAllowedFieldsForPageType($pageType);
        $allowedFields = $this->widenEligibleFieldsFromTitleOrOcr($allowedFields, $pageType, $classification['title'] ?? null, $ocrText);
        if (empty($allowedFields)) {
            return ['page' => $pageNum, 'page_type' => $pageType, 'extractions' => []];
        }

        $explicitArchetype = null;
        if ($pageType === 'archetype') {
            $explicitDetector = app(ArchetypeExplicitDetectionService::class);
            $explicitResult = $explicitDetector->detect(
                $ocrText,
                $classification['title'] ?? null,
                $classification
            );
            if ($explicitResult['matched'] && ! empty($explicitResult['value'])) {
                $explicitArchetype = [
                    'path' => 'personality.primary_archetype',
                    'value' => $explicitResult['value'],
                    'confidence' => $explicitResult['confidence'] ?? 0.98,
                    'evidence' => implode('; ', $explicitResult['evidence'] ?? []),
                    'page' => $pageNum,
                    'page_type' => $pageType,
                    'source' => ['pdf_visual', 'explicit_detection'],
                    '_explicit_detection' => true,
                ];
            }
        }

        $promptTargets = $this->allowedFieldsToPromptTargets($allowedFields);
        $prompt = $this->promptBuilder->buildPrompt($pageType, $ocrText, $promptTargets);

        $mime = mime_content_type($imagePath) ?: 'image/png';
        $data = base64_encode((string) file_get_contents($imagePath));
        $dataUrl = 'data:' . $mime . ';base64,' . $data;

        try {
            $response = $this->provider->analyzeImage($dataUrl, $prompt, [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 600,
                'response_format' => ['type' => 'json_object'],
            ]);

            $text = $response['text'] ?? '';
            $parsed = json_decode($text, true);

            if (! is_array($parsed)) {
                $extractions = $this->tryParseAsArray($text);
            } else {
                $extractions = $this->normalizeExtractions($parsed, $pageNum, $pageType, $allowedFields);
            }

            if ($explicitArchetype !== null) {
                $extractions = $this->mergeExplicitArchetype($extractions, $explicitArchetype);
            }

            return [
                'page' => $pageNum,
                'page_type' => $pageType,
                'extractions' => $extractions,
            ];
        } catch (\Throwable $e) {
            Log::warning('[PdfPageVisualExtractionService] Extraction failed', [
                'page' => $pageNum,
                'page_type' => $pageType,
                'error' => $e->getMessage(),
            ]);
            return ['page' => $pageNum, 'page_type' => $pageType, 'extractions' => []];
        }
    }

    protected function getAllowedFieldsForPageType(string $pageType): array
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        return $config[$pageType] ?? [];
    }

    /**
     * When page type is strategy/unknown but title or OCR contains explicit strategy labels,
     * widen eligible fields so narrative extraction is still attempted.
     */
    protected function widenEligibleFieldsFromTitleOrOcr(array $allowedFields, string $pageType, ?string $pageTitle, $ocrText): array
    {
        if (! in_array($pageType, ['strategy', 'unknown'], true)) {
            return $allowedFields;
        }

        $titleUpper = $pageTitle ? strtoupper($pageTitle) : '';
        $ocrUpper = is_string($ocrText) ? strtoupper(mb_substr(trim($ocrText), 0, 800)) : '';

        $fallbackFields = [];
        $strategyCues = [
            'PURPOSE' => ['identity.mission', 'identity.vision'],
            'PROMISE' => ['identity.positioning'],
            'POSITIONING' => ['identity.positioning', 'identity.industry', 'identity.tagline'],
            'BRAND VOICE' => ['personality.tone_keywords', 'personality.traits'],
            'VALUES' => ['identity.values'],
            'BELIEFS' => ['identity.beliefs'],
            'MISSION' => ['identity.mission'],
            'STRATEGY' => ['identity.mission', 'identity.positioning', 'identity.industry', 'identity.tagline'],
        ];

        foreach ($strategyCues as $cue => $fields) {
            if (str_contains($titleUpper, $cue) || str_contains($ocrUpper, $cue)) {
                $fallbackFields = array_merge($fallbackFields, $fields);
            }
        }

        if (empty($fallbackFields)) {
            return $allowedFields;
        }

        return array_values(array_unique(array_merge($allowedFields, $fallbackFields)));
    }

    /**
     * Map allowed field paths to prompt target keywords for the extraction prompt.
     */
    protected function allowedFieldsToPromptTargets(array $allowedFields): array
    {
        $map = [
            'identity.mission' => ['mission', 'purpose', 'why we exist'],
            'identity.vision' => ['vision', 'purpose'],
            'identity.positioning' => ['positioning', 'promise', 'what we deliver'],
            'identity.industry' => ['industry', 'market category'],
            'identity.tagline' => ['tagline'],
            'identity.beliefs' => ['beliefs', 'core beliefs'],
            'identity.values' => ['values', 'core values'],
            'personality.primary_archetype' => ['archetype', 'personality'],
            'personality.tone_keywords' => ['tone_of_voice', 'tone_keywords', 'brand voice'],
            'personality.traits' => ['personality traits', 'traits'],
        ];
        $targets = [];
        foreach ($allowedFields as $path) {
            if (isset($map[$path])) {
                $targets = array_merge($targets, $map[$path]);
            }
        }
        return array_values(array_unique($targets));
    }

    protected function tryParseAsArray(string $text): array
    {
        $trimmed = trim($text);
        if (preg_match('/\[[\s\S]*\]/', $trimmed, $m)) {
            $arr = json_decode($m[0], true);
            if (is_array($arr)) {
                return $arr;
            }
        }
        Log::warning('[PdfPageVisualExtractionService] Could not parse JSON array', [
            'preview' => mb_substr($trimmed, 0, 200),
        ]);
        return [];
    }

    /**
     * @param array|array{extractions?: array, field?: string, value?: mixed, confidence?: float, evidence?: string} $parsed
     * @return array<int, array{path: string, value: mixed, confidence: float, evidence: string, page: int, page_type: string, source: array}>
     */
    protected function normalizeExtractions(array $parsed, int $pageNum, string $pageType, array $allowedFields): array
    {
        $items = $parsed['extractions'] ?? $parsed;
        if (isset($parsed['field'], $parsed['value'])) {
            $items = [$parsed];
        }
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $path = $item['path'] ?? $item['field'] ?? null;
            if ($path === null || $path === '') {
                continue;
            }
            $path = $this->normalizePath($path);
            if (! $this->isPathAllowed($path, $allowedFields)) {
                continue;
            }
            $value = $item['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $confidence = (float) ($item['confidence'] ?? 0.5);
            $evidence = (string) ($item['evidence'] ?? '');

            $out[] = [
                'path' => $path,
                'value' => $value,
                'confidence' => $confidence,
                'evidence' => $evidence,
                'page' => $pageNum,
                'page_type' => $pageType,
                'source' => ['pdf_visual'],
            ];
        }
        return $out;
    }

    protected function normalizePath(string $path): string
    {
        $path = trim(str_replace(' ', '_', $path));
        $map = [
            'primary_font' => 'typography.primary_font',
            'secondary_font' => 'typography.secondary_font',
            'heading_style' => 'typography.heading_style',
            'body_style' => 'typography.body_style',
            'primary_colors' => 'visual.primary_colors',
            'secondary_colors' => 'visual.secondary_colors',
            'allowed_color_palette' => 'scoring_rules.allowed_color_palette',
            'primary_archetype' => 'personality.primary_archetype',
            'traits' => 'personality.traits',
            'tone_keywords' => 'personality.tone_keywords',
            'mission' => 'identity.mission',
            'positioning' => 'identity.positioning',
            'beliefs' => 'identity.beliefs',
            'values' => 'identity.values',
            'industry' => 'identity.industry',
            'tagline' => 'identity.tagline',
            'logo_detected' => 'visual.logo_detected',
            'photography_style' => 'visual.photography_style',
            'visual_style' => 'visual.visual_style',
            'design_cues' => 'visual.design_cues',
            'fonts' => 'visual.fonts',
        ];
        $base = $path;
        if (str_contains($path, '.')) {
            $parts = explode('.', $path, 2);
            $base = $parts[1] ?? $path;
        }
        return $map[$base] ?? ($map[$path] ?? 'visual.' . $path);
    }

    /**
     * Merge explicit archetype into extractions. Explicit always wins over inferred on same page.
     */
    protected function mergeExplicitArchetype(array $extractions, array $explicitArchetype): array
    {
        $filtered = array_filter($extractions, fn ($e) => ($e['path'] ?? '') !== 'personality.primary_archetype');
        $filtered[] = $explicitArchetype;

        return array_values($filtered);
    }

    protected function isPathAllowed(string $path, array $allowedFields): bool
    {
        if (in_array($path, $allowedFields, true)) {
            return true;
        }
        foreach ($allowedFields as $allowed) {
            if (str_contains($path, $allowed) || str_contains($allowed, $path)) {
                return true;
            }
        }
        return false;
    }
}
