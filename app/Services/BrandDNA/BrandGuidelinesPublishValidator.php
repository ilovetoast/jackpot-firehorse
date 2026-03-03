<?php

namespace App\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModelVersion;

/**
 * Validates a draft BrandModelVersion before publish.
 * Returns list of missing required fields per v1 minimum requirements.
 */
class BrandGuidelinesPublishValidator
{
    /**
     * Validate draft and brand. Returns empty array if valid; otherwise list of missing field descriptions.
     * Minimum brand completeness per stabilization spec.
     *
     * @return array<string>
     */
    public function validate(BrandModelVersion $draft, Brand $brand): array
    {
        $missing = [];
        $payload = $draft->model_payload ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        // 1. personality.primary_archetype
        $primary = $payload['personality']['primary_archetype'] ?? null;
        if (empty(trim((string) $primary))) {
            $missing[] = 'Archetype: Primary archetype is required';
        }

        // 2. identity.mission
        $mission = $payload['identity']['mission'] ?? null;
        if (empty(trim((string) $mission))) {
            $missing[] = 'Purpose: Mission (WHY) is required';
        }

        // 3. identity.positioning
        $positioning = $payload['identity']['positioning'] ?? null;
        if (empty(trim((string) $positioning))) {
            $missing[] = 'Purpose: Positioning statement (WHAT) is required';
        }

        // 4. ≥ 3 tone keywords
        $toneKeywords = $payload['scoring_rules']['tone_keywords'] ?? $payload['personality']['tone_keywords'] ?? [];
        $toneKeywords = is_array($toneKeywords) ? $toneKeywords : [];
        if (count($toneKeywords) < 3) {
            $missing[] = 'Expression: At least 3 tone keywords are required';
        }

        // 5. ≥ 1 allowed_color_palette color
        $palette = $payload['scoring_rules']['allowed_color_palette'] ?? [];
        $paletteCount = $this->countPaletteColors($palette);
        if ($paletteCount < 1) {
            $missing[] = 'Standards: At least 1 color in allowed palette is required';
        }

        // 6. typography.primary_font
        $primaryFont = $payload['typography']['primary_font'] ?? null;
        if (empty(trim((string) $primaryFont))) {
            $missing[] = 'Standards: Primary font is required';
        }

        return $missing;
    }

    protected function countPaletteColors(array $palette): int
    {
        $count = 0;
        foreach ($palette as $item) {
            if (is_array($item) && ! empty(trim((string) ($item['hex'] ?? '')))) {
                $count++;
            } elseif (is_string($item) && trim($item) !== '') {
                $count++;
            }
        }

        return $count;
    }
}
