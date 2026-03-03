<?php

namespace App\Services\BrandDNA;

/**
 * Snapshot-based suggestion engine. Generates actionable suggestions when
 * snapshot data (website) differs from draft. Deterministic, no AI.
 */
class BrandSnapshotSuggestionService
{
    public function generate(
        array $draftPayload,
        array $snapshotRaw,
        array $coherence
    ): array {
        $suggestions = [];

        $suggestions = array_merge(
            $suggestions,
            $this->suggestColorPalette($draftPayload, $snapshotRaw),
            $this->suggestTypography($draftPayload, $snapshotRaw),
            $this->suggestLogo($snapshotRaw)
        );

        return [
            'suggestions' => $this->normalizeSuggestions($suggestions),
        ];
    }

    protected function suggestColorPalette(array $draftPayload, array $snapshotRaw): array
    {
        $snapshotColors = $this->normalizeColors($snapshotRaw['primary_colors'] ?? []);
        if (empty($snapshotColors)) {
            return [];
        }

        $draftPalette = $draftPayload['scoring_rules']['allowed_color_palette'] ?? [];
        $draftColors = $this->normalizePaletteToHex($draftPalette);
        if (empty($draftColors)) {
            return [];
        }

        $overlap = count(array_intersect($draftColors, $snapshotColors));
        if ($overlap > 0) {
            return [];
        }

        return [
            [
                'key' => 'SUG:standards.allowed_color_palette',
                'path' => 'scoring_rules.allowed_color_palette',
                'type' => 'update',
                'value' => $snapshotRaw['primary_colors'],
                'reason' => 'Detected website colors differ from draft palette.',
                'confidence' => 0.9,
            ],
        ];
    }

    protected function suggestTypography(array $draftPayload, array $snapshotRaw): array
    {
        $detectedFonts = array_map(
            fn ($f) => strtolower(trim((string) $f)),
            $snapshotRaw['detected_fonts'] ?? []
        );
        $detectedFonts = array_filter($detectedFonts, fn ($f) => $f !== '');
        if (empty($detectedFonts)) {
            return [];
        }

        $payload = (new BrandDnaPayloadNormalizer)->normalize($draftPayload);
        $typography = $payload['typography'] ?? [];
        $primaryFont = trim((string) ($typography['primary_font'] ?? ''));
        if ($primaryFont === '') {
            return [];
        }

        $draftFontLower = strtolower($primaryFont);
        if (in_array($draftFontLower, $detectedFonts, true)) {
            return [];
        }

        $firstDetected = $snapshotRaw['detected_fonts'][0] ?? $detectedFonts[0];

        return [
            [
                'key' => 'SUG:standards.primary_font',
                'path' => 'typography.primary_font',
                'type' => 'update',
                'value' => $firstDetected,
                'reason' => 'Website uses a different primary font.',
                'confidence' => 0.7,
            ],
        ];
    }

    protected function suggestLogo(array $snapshotRaw): array
    {
        $logoUrl = $snapshotRaw['logo_url'] ?? null;
        if ($logoUrl === null || trim((string) $logoUrl) === '') {
            return [];
        }

        return [
            [
                'key' => 'SUG:standards.logo',
                'path' => 'visual.detected_logo',
                'type' => 'informational',
                'value' => $logoUrl,
                'reason' => 'Logo detected on website.',
                'confidence' => 0.6,
            ],
        ];
    }

    protected function normalizeSuggestions(array $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $s) {
            $key = $s['key'] ?? '';
            if (! str_starts_with($key, 'SUG:')) {
                $key = 'SUG:' . ltrim($key, '.');
            }
            $confidence = (float) ($s['confidence'] ?? 0);
            $confidence = max(0.0, min(1.0, $confidence));

            $weight = (float) ($s['weight'] ?? $confidence);
            $out[] = [
                'key' => $key,
                'path' => $s['path'] ?? '',
                'type' => $s['type'] ?? 'informational',
                'value' => $s['value'] ?? null,
                'reason' => $s['reason'] ?? '',
                'confidence' => $confidence,
                'weight' => $weight,
                'confidence_tier' => SuggestionConfidenceTier::fromWeight($weight),
            ];
        }

        return $out;
    }

    protected function normalizePaletteToHex(array $palette): array
    {
        $hex = [];
        foreach ($palette as $item) {
            if (is_array($item) && isset($item['hex'])) {
                $h = strtolower(trim((string) $item['hex']));
                if ($h !== '') {
                    $hex[] = $h;
                }
            } elseif (is_string($item) && trim($item) !== '') {
                $hex[] = strtolower(trim($item));
            }
        }

        return array_values(array_unique($hex));
    }

    protected function normalizeColors(array $colors): array
    {
        $hex = [];
        foreach ($colors as $c) {
            $h = strtolower(trim((string) $c));
            if ($h !== '') {
                $hex[] = $h;
            }
        }

        return array_values(array_unique($hex));
    }
}
