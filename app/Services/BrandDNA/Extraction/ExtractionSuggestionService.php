<?php

namespace App\Services\BrandDNA\Extraction;

class ExtractionSuggestionService
{
    public function generateSuggestions(array $extraction): array
    {
        $suggestions = [];
        $explicit = $extraction['explicit_signals'] ?? [];

        if (($explicit['archetype_declared'] ?? false) && !empty($extraction['personality']['primary_archetype'])) {
            $suggestions[] = [
                'key' => 'SUG:personality.primary_archetype',
                'path' => 'personality.primary_archetype',
                'type' => 'update',
                'value' => $extraction['personality']['primary_archetype'],
                'reason' => 'Declared explicitly in Brand Guidelines.',
                'confidence' => 0.95,
            ];
        }

        if (($explicit['mission_declared'] ?? false) && !empty($extraction['identity']['mission'])) {
            $suggestions[] = [
                'key' => 'SUG:identity.mission',
                'path' => 'identity.mission',
                'type' => 'update',
                'value' => $extraction['identity']['mission'],
                'reason' => 'Extracted from Brand Guidelines.',
                'confidence' => 0.9,
            ];
        }

        if (($explicit['positioning_declared'] ?? false) && !empty($extraction['identity']['positioning'])) {
            $suggestions[] = [
                'key' => 'SUG:identity.positioning',
                'path' => 'identity.positioning',
                'type' => 'update',
                'value' => $extraction['identity']['positioning'],
                'reason' => 'Extracted from Brand Guidelines.',
                'confidence' => 0.9,
            ];
        }

        if (!empty($extraction['visual']['primary_colors'])) {
            $palette = array_map(fn ($c) => is_string($c) ? ['hex' => $c] : $c, $extraction['visual']['primary_colors']);
            $suggestions[] = [
                'key' => 'SUG:standards.allowed_color_palette',
                'path' => 'scoring_rules.allowed_color_palette',
                'type' => 'update',
                'value' => $palette,
                'reason' => 'Colors extracted from source materials.',
                'confidence' => $extraction['confidence'] ?? 0.7,
            ];
        }

        if (!empty($extraction['visual']['fonts'])) {
            $primaryFont = $extraction['visual']['fonts'][0] ?? null;
            if ($primaryFont) {
                $suggestions[] = [
                    'key' => 'SUG:typography.primary_font',
                    'path' => 'typography.primary_font',
                    'type' => 'update',
                    'value' => $primaryFont,
                    'reason' => 'Typography extracted from source materials.',
                    'confidence' => 0.8,
                ];
            }
        }

        return $suggestions;
    }
}
