<?php

namespace App\Services\BrandDNA\Extraction;

use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Extracts structured brand signals from PDF page images using vision AI.
 * Returns strict JSON matching BrandExtractionSchema fields.
 */
class VisionExtractionService
{
    protected AIProviderInterface $provider;
    protected const PROMPT = <<<'PROMPT'
You are extracting structured brand identity signals from a brand guidelines document page.

Return JSON only with these keys. Use null if not present. Do not invent information. No explanation. No markdown.

{
  "mission": null,
  "vision": null,
  "positioning": null,
  "primary_archetype": null,
  "tone_keywords": [],
  "traits": [],
  "primary_colors": [],
  "fonts": [],
  "visual_style_keywords": [],
  "confidence": 0.0
}

primary_colors: hex codes only (e.g. ["#003388", "#FF6600"])
confidence: 0-1 based on how clearly the information appears
PROMPT;

    public function __construct(?AIProviderInterface $provider = null)
    {
        $this->provider = $provider ?? app(AIProviderInterface::class);
    }

    /**
     * Analyze a single page image and return structured extraction.
     *
     * @param string $imagePath Local path to PNG/WebP image
     * @return array Normalized to BrandExtractionSchema-compatible structure
     */
    public function extractFromImage(string $imagePath): array
    {
        if (! file_exists($imagePath) || ! is_readable($imagePath)) {
            throw new \RuntimeException('Image file does not exist or is not readable.');
        }

        $mime = mime_content_type($imagePath) ?: 'image/png';
        $data = base64_encode((string) file_get_contents($imagePath));
        $dataUrl = 'data:' . $mime . ';base64,' . $data;

        $response = $this->provider->analyzeImage($dataUrl, self::PROMPT, [
            'model' => 'gpt-4o-mini',
            'max_tokens' => 800,
            'response_format' => ['type' => 'json_object'],
        ]);

        $text = $response['text'] ?? '';
        $parsed = json_decode($text, true);
        if (! is_array($parsed)) {
            Log::warning('[VisionExtractionService] Invalid JSON response', [
                'response_preview' => mb_substr($text, 0, 200),
            ]);
            return $this->emptySchema();
        }

        return $this->normalizeToSchema($parsed);
    }

    protected function normalizeToSchema(array $parsed): array
    {
        $schema = BrandExtractionSchema::empty();

        $schema['identity']['mission'] = $parsed['mission'] ?? null;
        $schema['identity']['vision'] = $parsed['vision'] ?? null;
        $schema['identity']['positioning'] = $parsed['positioning'] ?? null;

        $archetype = $parsed['primary_archetype'] ?? null;
        $schema['personality']['primary_archetype'] = $archetype;
        $schema['personality']['tone_keywords'] = $this->ensureArray($parsed['tone_keywords'] ?? []);
        $schema['personality']['traits'] = $this->ensureArray($parsed['traits'] ?? []);

        $colors = $this->ensureArray($parsed['primary_colors'] ?? []);
        $schema['visual']['primary_colors'] = array_values(array_filter($colors, fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $c)));
        $schema['visual']['fonts'] = $this->ensureArray($parsed['fonts'] ?? []);

        $schema['explicit_signals'] = [
            'archetype_declared' => $archetype !== null,
            'mission_declared' => ($parsed['mission'] ?? null) !== null,
            'positioning_declared' => ($parsed['positioning'] ?? null) !== null,
        ];

        $schema['sources']['pdf'] = ['extracted' => true, 'source' => 'vision'];
        $schema['confidence'] = (float) ($parsed['confidence'] ?? 0.5);

        return $schema;
    }

    protected function ensureArray(mixed $v): array
    {
        if (is_array($v)) {
            return array_values(array_filter($v, fn ($x) => $x !== null && $x !== ''));
        }
        if (is_string($v) && $v !== '') {
            return [$v];
        }
        return [];
    }

    protected function emptySchema(): array
    {
        return BrandExtractionSchema::empty();
    }
}
