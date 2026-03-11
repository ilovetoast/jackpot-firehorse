<?php

namespace App\Services\BrandDNA;

use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use App\Services\BrandDNA\Extraction\SignalWeights;

/**
 * Merges text extraction with page visual extraction.
 * - Prefer visual for colors, fonts, logo, photography_style
 * - Prefer text + visual combined for narrative fields
 * - Boost confidence when both agree
 * - Reduce confidence when they conflict
 */
class BrandExtractionFusionService
{
    protected const VISUAL_PREFERRED_FIELDS = [
        'visual.primary_colors',
        'visual.secondary_colors',
        'scoring_rules.allowed_color_palette',
        'visual.fonts',
        'typography.primary_font',
        'typography.secondary_font',
        'typography.heading_style',
        'typography.body_style',
        'visual.logo_detected',
        'visual.photography_style',
        'visual.visual_style',
        'visual.design_cues',
    ];

    protected const NARRATIVE_FIELDS = [
        'identity.mission',
        'identity.positioning',
        'identity.beliefs',
        'identity.values',
        'personality.primary_archetype',
        'personality.tone_keywords',
        'personality.traits',
    ];

    /**
     * Convert page extractions to BrandExtractionSchema format.
     *
     * @param array<int, array{page: int, page_type: string, extractions: array}> $pageExtractions
     */
    public function pageExtractionsToSchema(array $pageExtractions): array
    {
        $schema = BrandExtractionSchema::empty();
        $schema['sources']['pdf'] = ['extracted' => true, 'source' => 'pdf_visual'];

        foreach ($pageExtractions as $pageData) {
            $extractions = $pageData['extractions'] ?? [];
            foreach ($extractions as $ex) {
                $path = $ex['path'] ?? null;
                $value = $ex['value'] ?? null;
                $confidence = (float) ($ex['confidence'] ?? 0.5);
                if ($path === null || $value === null) {
                    continue;
                }
                $this->applyExtractionToSchema($schema, $path, $value, $confidence, $ex);
            }
        }

        return $schema;
    }

    /**
     * Fuse text extraction with visual extraction.
     * Applies field-specific rules.
     */
    public function fuse(array $textExtraction, array $visualExtraction): array
    {
        $textSchema = $this->ensureSchemaShape($textExtraction);
        $visualSchema = $this->ensureSchemaShape($visualExtraction);

        $merged = BrandExtractionSchema::merge($textSchema, $visualSchema);

        $this->applyFusionRules($merged, $textSchema, $visualSchema);

        return $merged;
    }

    /**
     * Merge multiple page extraction results into a single schema.
     *
     * @param array<int, array{page: int, page_type: string, extractions: array}> $pageExtractions
     */
    public function mergePageExtractions(array $pageExtractions): array
    {
        return $this->pageExtractionsToSchema($pageExtractions);
    }

    protected function applyExtractionToSchema(array &$schema, string $path, mixed $value, float $confidence, array $ex): void
    {
        $page = $ex['page'] ?? null;
        $pageType = $ex['page_type'] ?? null;
        $evidence = $ex['evidence'] ?? '';

        $wrapped = [
            'value' => $value,
            'source' => 'pdf_visual',
            'source_type' => 'visual',
            'weight' => $this->weightForField($path, $confidence),
            'evidence' => $evidence,
            'page' => $page,
            'page_type' => $pageType,
        ];

        if (str_starts_with($path, 'identity.')) {
            $key = substr($path, 9);
            if (in_array($key, ['mission', 'vision', 'positioning', 'industry', 'tagline'], true)) {
                $schema['identity'][$key] = $value;
            }
            if (in_array($key, ['beliefs', 'values'], true)) {
                $arr = is_array($value) ? $value : [$value];
                $schema['identity'][$key] = array_values(array_unique(array_merge(
                    $schema['identity'][$key] ?? [],
                    $arr
                )));
            }
        }
        if (str_starts_with($path, 'personality.')) {
            $key = substr($path, 12);
            if ($key === 'primary_archetype') {
                $existing = $schema['personality']['primary_archetype'] ?? null;
                $existingIsExplicit = is_array($existing)
                    && ($existing['source_type'] ?? '') === 'explicit';

                $isExplicit = isset($ex['_explicit_detection']) && $ex['_explicit_detection'];
                if ($isExplicit) {
                    $schema['personality']['primary_archetype'] = [
                        'value' => $value,
                        'source' => 'pdf_visual',
                        'source_type' => 'explicit',
                        'weight' => 0.98,
                        'evidence' => $evidence,
                        'page' => $page,
                        'page_type' => $pageType,
                    ];
                } elseif (! $existingIsExplicit) {
                    $schema['personality']['primary_archetype'] = $value;
                }
            }
            if (in_array($key, ['traits', 'tone_keywords'], true)) {
                $arr = is_array($value) ? $value : [$value];
                $schema['personality'][$key] = array_values(array_unique(array_merge(
                    $schema['personality'][$key] ?? [],
                    $arr
                )));
            }
        }
        if (str_starts_with($path, 'visual.')) {
            $key = substr($path, 7);
            if ($key === 'logo_detected') {
                $schema['visual']['logo_detected'] = $value;
            }
            if (in_array($key, ['primary_colors', 'secondary_colors', 'fonts', 'photography_style', 'visual_style', 'design_cues'], true)) {
                $arr = is_array($value) ? $value : [$value];
                $existing = $schema['visual'][$key] ?? [];
                if (! is_array($existing)) {
                    $existing = [];
                }
                $schema['visual'][$key] = array_values(array_unique(array_merge($existing, $arr)));
            }
        }
        if (str_starts_with($path, 'typography.')) {
            $key = substr($path, 11);
            if (in_array($key, ['primary_font', 'secondary_font', 'heading_style', 'body_style'], true)) {
                $schema['_typography'] = $schema['_typography'] ?? [];
                $schema['_typography'][$key] = $value;
            }
            if ($key === 'primary_font' || $key === 'secondary_font') {
                $schema['visual']['fonts'] = array_values(array_unique(array_merge(
                    $schema['visual']['fonts'] ?? [],
                    [is_string($value) ? $value : (string) $value]
                )));
            }
        }
        if (str_starts_with($path, 'scoring_rules.')) {
            if (str_contains($path, 'allowed_color_palette')) {
                $arr = is_array($value) ? $value : [$value];
                $schema['visual']['primary_colors'] = array_values(array_unique(array_merge(
                    $schema['visual']['primary_colors'] ?? [],
                    $arr
                )));
            }
        }
    }

    protected function weightForField(string $path, float $confidence): float
    {
        $aggressive = config('brand_dna_page_extraction.aggressive_auto_trust_fields', []);
        $conservative = config('brand_dna_page_extraction.conservative_suggestion_fields', []);

        if (in_array($path, $aggressive, true)) {
            return min(1.0, $confidence + 0.1);
        }
        if (in_array($path, $conservative, true)) {
            return $confidence * 0.9;
        }
        return $confidence;
    }

    protected function ensureSchemaShape(array $ext): array
    {
        $empty = BrandExtractionSchema::empty();
        foreach (['identity', 'personality', 'visual'] as $section) {
            if (! isset($ext[$section]) || ! is_array($ext[$section])) {
                $ext[$section] = $empty[$section] ?? [];
            }
        }
        return $ext;
    }

    protected function applyFusionRules(array &$merged, array $text, array $visual): void
    {
        foreach (self::VISUAL_PREFERRED_FIELDS as $path) {
            $this->preferVisualForField($merged, $text, $visual, $path);
        }
        foreach (self::NARRATIVE_FIELDS as $path) {
            $this->boostWhenAgree($merged, $text, $visual, $path);
        }
    }

    protected function preferVisualForField(array &$merged, array $text, array $visual, string $path): void
    {
        [$section, $key] = $this->parsePath($path);
        if ($section === null) {
            return;
        }
        $visualVal = $this->getNested($visual, $section, $key);
        if ($visualVal === null || $visualVal === '' || $visualVal === []) {
            return;
        }
        if ($section === 'typography') {
            $merged['_typography'] = $merged['_typography'] ?? [];
            $merged['_typography'][$key] = $visualVal;
            if (in_array($key, ['primary_font', 'secondary_font'], true)) {
                $merged['visual']['fonts'] = array_values(array_unique(array_merge(
                    $merged['visual']['fonts'] ?? [],
                    [is_string($visualVal) ? $visualVal : (string) $visualVal]
                )));
            }
            return;
        }
        if (isset($merged[$section][$key])) {
            $merged[$section][$key] = $visualVal;
        }
    }

    protected function boostWhenAgree(array &$merged, array $text, array $visual, string $path): void
    {
        [$section, $key] = $this->parsePath($path);
        if ($section === null) {
            return;
        }
        $textVal = $this->getNested($text, $section, $key);
        $visualVal = $this->getNested($visual, $section, $key);
        $mergedVal = $this->getNested($merged, $section, $key);
        if ($textVal !== null && $visualVal !== null && $this->valuesSimilar($textVal, $visualVal)) {
            if (is_array($mergedVal) && isset($mergedVal['weight'])) {
                $mergedVal['weight'] = min(1.0, ($mergedVal['weight'] ?? 0.5) + 0.1);
            }
        }
    }

    protected function parsePath(string $path): ?array
    {
        if (! str_contains($path, '.')) {
            return null;
        }
        $parts = explode('.', $path, 2);
        return [$parts[0], $parts[1]];
    }

    protected function getNested(array $arr, string $section, string $key): mixed
    {
        if ($section === 'typography') {
            return $arr['_typography'][$key] ?? $arr['typography'][$key] ?? null;
        }
        return $arr[$section][$key] ?? null;
    }

    protected function valuesSimilar(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }
        if (is_array($a) && is_array($b)) {
            $a2 = $a;
            $b2 = $b;
            sort($a2);
            sort($b2);
            return json_encode($a2) === json_encode($b2);
        }
        return false;
    }
}
