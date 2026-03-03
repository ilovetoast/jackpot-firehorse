<?php

namespace App\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\SuggestionConfidenceTier;

class ExtractionSuggestionService
{
    public function generateSuggestions(array $extraction, array $conflicts = []): array
    {
        $suggestions = [];
        $explicit = $extraction['explicit_signals'] ?? [];
        $conflictFields = array_column($conflicts, 'field');

        foreach ($conflicts as $conflict) {
            $field = $conflict['field'] ?? '';
            $recommended = $conflict['recommended'] ?? null;
            $weight = $conflict['recommended_weight'] ?? 0.5;
            $suggestions[] = [
                'key' => 'SUG:conflict.' . $field,
                'path' => $this->conflictFieldToPath($field),
                'type' => 'informational',
                'value' => $recommended,
                'reason' => sprintf('Multiple sources disagree. Recommended: %s.', is_scalar($recommended) ? $recommended : 'see value'),
                'confidence' => $weight,
                'weight' => $weight,
                'confidence_tier' => SuggestionConfidenceTier::fromWeight($weight),
            ];
        }

        if (($explicit['archetype_declared'] ?? false) && ! empty($extraction['personality']['primary_archetype']) && ! in_array('personality.primary_archetype', $conflictFields)) {
            $suggestions[] = [
                'key' => 'SUG:personality.primary_archetype',
                'path' => 'personality.primary_archetype',
                'type' => 'update',
                'value' => SignalWeights::unwrap($extraction['personality']['primary_archetype']),
                'reason' => 'Declared explicitly in Brand Guidelines.',
                'confidence' => 0.95,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'confidence_tier' => SuggestionConfidenceTier::fromWeight(SignalWeights::PDF_EXPLICIT),
            ];
        }

        if (($explicit['mission_declared'] ?? false) && ! empty($extraction['identity']['mission']) && ! in_array('identity.mission', $conflictFields)) {
            $suggestions[] = [
                'key' => 'SUG:identity.mission',
                'path' => 'identity.mission',
                'type' => 'update',
                'value' => SignalWeights::unwrap($extraction['identity']['mission']),
                'reason' => 'Extracted from Brand Guidelines.',
                'confidence' => 0.9,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'confidence_tier' => SuggestionConfidenceTier::fromWeight(SignalWeights::PDF_EXPLICIT),
            ];
        }

        if (($explicit['positioning_declared'] ?? false) && ! empty($extraction['identity']['positioning']) && ! in_array('identity.positioning', $conflictFields)) {
            $suggestions[] = [
                'key' => 'SUG:identity.positioning',
                'path' => 'identity.positioning',
                'type' => 'update',
                'value' => SignalWeights::unwrap($extraction['identity']['positioning']),
                'reason' => 'Extracted from Brand Guidelines.',
                'confidence' => 0.9,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'confidence_tier' => SuggestionConfidenceTier::fromWeight(SignalWeights::PDF_EXPLICIT),
            ];
        }

        if (! empty($extraction['visual']['primary_colors'])) {
            $colors = $extraction['visual']['primary_colors'];
            $palette = [];
            foreach (is_array($colors) ? $colors : [$colors] as $c) {
                if (is_string($c)) {
                    $palette[] = ['hex' => $c];
                } elseif (is_array($c) && isset($c['hex'])) {
                    $palette[] = $c;
                } elseif (is_array($c) && isset($c['value'])) {
                    $palette[] = is_string($c['value']) ? ['hex' => $c['value']] : $c['value'];
                }
            }
            $w = SignalWeights::WEBSITE_DETERMINISTIC;
            $suggestions[] = [
                'key' => 'SUG:standards.allowed_color_palette',
                'path' => 'scoring_rules.allowed_color_palette',
                'type' => 'update',
                'value' => $palette,
                'reason' => 'Colors extracted from source materials.',
                'confidence' => $extraction['confidence'] ?? 0.7,
                'weight' => $w,
                'confidence_tier' => SuggestionConfidenceTier::fromWeight($w),
            ];
        }

        if (! empty($extraction['visual']['fonts'])) {
            $fonts = $extraction['visual']['fonts'];
            $primaryFont = is_array($fonts) ? ($fonts[0] ?? null) : $fonts;
            if ($primaryFont) {
                $primaryFont = is_array($primaryFont) && isset($primaryFont['value']) ? $primaryFont['value'] : $primaryFont;
                $w = SignalWeights::WEBSITE_DETERMINISTIC;
                $suggestions[] = [
                    'key' => 'SUG:typography.primary_font',
                    'path' => 'typography.primary_font',
                    'type' => 'update',
                    'value' => $primaryFont,
                    'reason' => 'Typography extracted from source materials.',
                    'confidence' => 0.8,
                    'weight' => $w,
                    'confidence_tier' => SuggestionConfidenceTier::fromWeight($w),
                ];
            }
        }

        return $suggestions;
    }

    protected function conflictFieldToPath(string $field): string
    {
        $map = [
            'personality.primary_archetype' => 'personality.primary_archetype',
            'identity.mission' => 'identity.mission',
            'identity.positioning' => 'identity.positioning',
        ];

        return $map[$field] ?? $field;
    }
}
