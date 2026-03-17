<?php

namespace App\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModelVersion;

/**
 * Validates a draft BrandModelVersion before publish.
 * Returns hard errors (block publish) and soft warnings (can be acknowledged).
 */
class BrandGuidelinesPublishValidator
{
    /**
     * Validate draft and brand.
     *
     * @return array{errors: array<string>, warnings: array<string>}
     */
    public function validate(BrandModelVersion $draft, Brand $brand): array
    {
        $errors = [];
        $warnings = [];
        $payload = $draft->model_payload ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        // --- Hard requirements (block publish) ---

        $primary = self::unwrap($payload['personality']['primary_archetype'] ?? null);
        if (empty(trim((string) $primary))) {
            $errors[] = 'Archetype: Primary archetype is required';
        }

        $mission = self::unwrap($payload['identity']['mission'] ?? null);
        if (empty(trim((string) $mission))) {
            $errors[] = 'Purpose: Mission (WHY) is required';
        }

        $positioning = self::unwrap($payload['identity']['positioning'] ?? null);
        if (empty(trim((string) $positioning))) {
            $errors[] = 'Purpose: Positioning statement (WHAT) is required';
        }

        $hasLogo = ! empty($brand->logo_id);
        if (! $hasLogo) {
            $logoRef = BrandModelVersion::find($draft->id)
                ?->assetsForContext('logo_reference')
                ->exists();
            $hasLogo = $logoRef;
        }
        if (! $hasLogo) {
            $errors[] = 'Standards: A brand logo is required';
        }

        // --- Soft warnings (can be acknowledged) ---

        $toneKeywords = self::unwrap($payload['scoring_rules']['tone_keywords'] ?? $payload['personality']['tone_keywords'] ?? []);
        $toneKeywords = is_array($toneKeywords) ? $toneKeywords : [];
        if (count($toneKeywords) < 3) {
            $warnings[] = 'Expression: At least 3 tone keywords are recommended';
        }

        $palette = $payload['scoring_rules']['allowed_color_palette'] ?? [];
        $paletteCount = $this->countPaletteColors($palette);
        if ($paletteCount < 1) {
            $warnings[] = 'Standards: At least 1 color in allowed palette is recommended';
        }

        $fontsArr = self::unwrap($payload['typography']['fonts'] ?? []);
        $primaryFont = self::unwrap($payload['typography']['primary_font'] ?? null);
        $hasFonts = (is_array($fontsArr) && count($fontsArr) > 0) || !empty(trim((string) $primaryFont));
        if (! $hasFonts) {
            $warnings[] = 'Standards: At least one font is recommended';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
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

    protected static function unwrap(mixed $val): mixed
    {
        if (is_array($val) && array_key_exists('value', $val) && isset($val['source'])) {
            return $val['value'];
        }

        return $val;
    }
}
