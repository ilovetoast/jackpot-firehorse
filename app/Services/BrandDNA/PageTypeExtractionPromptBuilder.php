<?php

namespace App\Services\BrandDNA;

/**
 * Builds page-type-specific extraction prompts.
 * Do not use one generic prompt for every page.
 */
class PageTypeExtractionPromptBuilder
{
    protected const EXTRACTION_TARGETS = [
        'archetype', 'mission', 'purpose', 'promise', 'positioning', 'beliefs', 'values',
        'tone_of_voice', 'tone_keywords', 'brand_look', 'photography_style', 'visual_style',
        'logo_usage', 'primary_logo', 'secondary_logo', 'primary_colors', 'secondary_colors',
        'accent_colors', 'color_palette', 'primary_font', 'secondary_font', 'heading_style',
        'body_style', 'design_cues', 'tagline', 'audience', 'industry', 'market_category',
        'competitive_position',
    ];

    protected const FIELDS_BY_PAGE_TYPE = [
        'archetype' => ['archetype', 'personality traits', 'tone_of_voice', 'tone_keywords'],
        'color_palette' => ['visible colors', 'named palette colors', 'hex values', 'primary_colors', 'secondary_colors', 'accent_colors'],
        'typography' => ['primary font', 'secondary font', 'heading style', 'body style'],
        'example_gallery' => ['photography style', 'visual cues', 'repeated design patterns'],
        'purpose' => ['mission', 'purpose', 'why we exist'],
        'promise' => ['promise', 'positioning', 'what we deliver'],
        'positioning' => ['positioning', 'industry', 'tagline', 'competitive_position'],
        'beliefs' => ['beliefs', 'core beliefs'],
        'values' => ['values', 'core values'],
        'brand_voice' => ['tone_of_voice', 'tone_keywords', 'brand voice'],
        'brand_story' => ['mission', 'positioning', 'brand story'],
        'strategy' => ['mission', 'positioning', 'industry', 'tagline'],
        'logo_usage' => ['logo_usage', 'primary_logo', 'secondary_logo'],
        'photography' => ['photography_style', 'visual_style'],
        'product_examples' => ['photography_style', 'visual_style'],
        'visual_identity' => ['primary_colors', 'fonts', 'logo_usage'],
        'brand_style' => ['visual_style', 'tone_keywords'],
    ];

    /**
     * @param  array<string>|null  $overrideFields  Optional. When provided (e.g. from title/OCR fallback), use these prompt targets instead of page-type defaults.
     */
    public function buildPrompt(string $pageType, ?string $ocrText = null, ?array $overrideFields = null): string
    {
        $fields = $overrideFields ?? (self::FIELDS_BY_PAGE_TYPE[$pageType] ?? []);
        $allowedTargets = $this->getAllowedTargetsForPageType($pageType, $fields);

        $base = <<<PROMPT
Analyze this rendered brand-guidelines page.

Only extract the following if clearly present or strongly implied:
- {$this->formatTargetList($allowedTargets)}

CRITICAL: Do not return page titles, section labels, field labels, or nearby descriptive body copy as the field value.
Return only the actual value for the requested field.
If only a label is visible but no value is present, return nothing for that field.

For typography: Return only likely font family names (e.g. Montserrat, Helvetica Neue), not sample headlines or marketing copy.
For tone/voice: Return actual tone descriptors only, not the phrase "tone of voice" or parts of that label.
For positioning/mission: Return only complete positioning/mission statements, not partial phrases or fragments.

For each extracted item, return JSON with:
- field (path)
- value
- confidence (0-1)
- evidence (brief description of what you see)
- page number
- page type

Do not guess fields not supported by the page.
If unclear, return nothing for that field.
Return JSON array of extractions only. No markdown. No explanation.
PROMPT;

        if ($ocrText !== null && $ocrText !== '' && strlen(trim($ocrText)) > 10) {
            $base .= "\n\nOCR text from this page (use for context, prefer visual when both present):\n" . mb_substr(trim($ocrText), 0, 1500);
        }

        return $base;
    }

    protected function getAllowedTargetsForPageType(string $pageType, array $typeFields): array
    {
        if ($pageType === 'example_gallery' || $pageType === 'product_examples') {
            return ['photography_style', 'visual_style', 'design_cues'];
        }

        if ($pageType === 'table_of_contents' || $pageType === 'cover' || $pageType === 'contact' || $pageType === 'appendix') {
            return [];
        }

        if (! empty($typeFields)) {
            return $typeFields;
        }

        return array_slice(self::EXTRACTION_TARGETS, 0, 12);
    }

    protected function formatTargetList(array $targets): string
    {
        if (empty($targets)) {
            return 'none (extract nothing)';
        }
        return implode("\n- ", $targets);
    }

    /**
     * Get the expected extraction schema for a page type (for response parsing).
     */
    public function getExpectedFieldsForPageType(string $pageType): array
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        return $config[$pageType] ?? [];
    }
}
