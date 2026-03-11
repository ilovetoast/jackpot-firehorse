<?php

namespace App\Services\BrandDNA;

use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Classifies rendered PDF pages by page type.
 * Returns page type, confidence, title, signals present, and extraction priority.
 */
class PdfPageClassificationService
{
    protected const PAGE_TYPES = [
        'cover', 'table_of_contents', 'brand_story', 'strategy', 'archetype',
        'purpose', 'promise', 'positioning', 'beliefs', 'values', 'brand_voice',
        'brand_style', 'visual_identity', 'typography', 'color_palette',
        'logo_usage', 'photography', 'example_gallery', 'product_examples',
        'contact', 'appendix', 'unknown',
    ];

    protected const CLASSIFICATION_PROMPT = <<<'PROMPT'
Analyze this brand guidelines document page image.

Classify the page into exactly ONE primary type from this list:
cover, table_of_contents, brand_story, strategy, archetype, purpose, promise, positioning,
beliefs, values, brand_voice, brand_style, visual_identity, typography, color_palette,
logo_usage, photography, example_gallery, product_examples, contact, appendix, unknown

Return JSON only. No markdown. No explanation.
{
  "page_type": "unknown",
  "confidence": 0.0,
  "title": null,
  "signals_present": [],
  "extraction_priority": "low"
}

Rules:
- page_type: must be one of the list above
- confidence: 0-1 based on how clearly the page matches the type
- title: page heading/title if visible, else null
- signals_present: array of brand signals likely on this page (e.g. ["archetype", "tone_of_voice"] for archetype page)
- extraction_priority: "high" for strategy/archetype/colors/typography, "medium" for brand_story/values, "low" for cover/gallery/contact/appendix
PROMPT;

    public function __construct(
        protected ?AIProviderInterface $provider = null
    ) {
        $this->provider = $provider ?? app(AIProviderInterface::class);
    }

    /**
     * Classify a single page image.
     *
     * @return array{page: int, page_type: string, confidence: float, title: ?string, signals_present: array, extraction_priority: string}
     */
    public function classifyPage(string $imagePath, int $pageNumber): array
    {
        if (! file_exists($imagePath) || ! is_readable($imagePath)) {
            return $this->fallbackClassification($pageNumber);
        }

        $mime = mime_content_type($imagePath) ?: 'image/png';
        $data = base64_encode((string) file_get_contents($imagePath));
        $dataUrl = 'data:' . $mime . ';base64,' . $data;

        try {
            $response = $this->provider->analyzeImage($dataUrl, self::CLASSIFICATION_PROMPT, [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 300,
                'response_format' => ['type' => 'json_object'],
            ]);

            $text = $response['text'] ?? '';
            $parsed = json_decode($text, true);
            if (! is_array($parsed)) {
                Log::warning('[PdfPageClassificationService] Invalid JSON', ['preview' => mb_substr($text, 0, 150)]);
                return $this->fallbackClassification($pageNumber);
            }

            $pageType = $parsed['page_type'] ?? 'unknown';
            if (! in_array($pageType, self::PAGE_TYPES, true)) {
                $pageType = 'unknown';
            }

            $priority = $parsed['extraction_priority'] ?? 'low';
            if (! in_array($priority, ['high', 'medium', 'low'], true)) {
                $priority = 'low';
            }

            return [
                'page' => $pageNumber,
                'page_type' => $pageType,
                'confidence' => (float) ($parsed['confidence'] ?? 0.5),
                'title' => $parsed['title'] ?? null,
                'signals_present' => $this->ensureStringArray($parsed['signals_present'] ?? []),
                'extraction_priority' => $priority,
            ];
        } catch (\Throwable $e) {
            Log::warning('[PdfPageClassificationService] Classification failed', ['error' => $e->getMessage()]);
            return $this->fallbackClassification($pageNumber);
        }
    }

    /**
     * Classify multiple pages. Returns array of classification results.
     *
     * @param array<int, string> $pagePaths Map of page number => image path
     * @return array<int, array>
     */
    public function classifyPages(array $pagePaths): array
    {
        $results = [];
        foreach ($pagePaths as $pageNum => $path) {
            $results[] = $this->classifyPage($path, $pageNum);
        }
        return $results;
    }

    protected function fallbackClassification(int $pageNumber): array
    {
        return [
            'page' => $pageNumber,
            'page_type' => 'unknown',
            'confidence' => 0.0,
            'title' => null,
            'signals_present' => [],
            'extraction_priority' => 'low',
        ];
    }

    protected function ensureStringArray(mixed $v): array
    {
        if (! is_array($v)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $v), fn ($x) => $x !== ''));
    }
}
