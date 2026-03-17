<?php

namespace App\Services\BrandDNA;

use App\Services\BrandDNA\Extraction\ExtractionSuggestionService;

/**
 * Transforms raw suggestion array into frontend-friendly format.
 * Supports both new structured format (path, value, confidence, auto_apply) and legacy keys.
 */
class SuggestionViewTransformer
{
    /**
     * Normalize suggestions for frontend. Handles:
     * - Array of structured items (from ExtractionSuggestionService) → transform to full format
     * - Legacy object (recommended_archetypes, mission_suggestion, etc.) → pass through, add empty items/auto_applied/suggested/ignored
     * - Enriches recommended_archetypes from snapshot evidence_map when not in suggestions
     *
     * @param  array|mixed  $suggestions
     * @param  array{evidence_map?: array}  $snapshot  Optional snapshot to enrich from evidence_map
     * @return array{items: array, recommended_archetypes: array, mission_suggestion: ?string, positioning_suggestion: ?string, tone_suggestion: ?array, auto_applied: array, suggested: array, ignored: array}
     */
    public static function forFrontend(mixed $suggestions, array $snapshot = []): array
    {
        $defaults = [
            'items' => [],
            'recommended_archetypes' => [],
            'mission_suggestion' => null,
            'positioning_suggestion' => null,
            'tone_suggestion' => null,
            'tagline_suggestion' => null,
            'industry_suggestion' => null,
            'target_audience_suggestion' => null,
            'beliefs_suggestion' => null,
            'values_suggestion' => null,
            'traits_suggestion' => null,
            'tone_keywords_suggestion' => null,
            'voice_description_suggestion' => null,
            'brand_look_suggestion' => null,
            'brand_color_suggestions' => [],
            'fonts_suggestion' => [],
            'auto_applied' => [],
            'suggested' => [],
            'ignored' => [],
        ];

        if (! is_array($suggestions)) {
            return $defaults;
        }

        // Legacy format: associative with recommended_archetypes etc.
        if (isset($suggestions['recommended_archetypes']) || isset($suggestions['mission_suggestion'])) {
            $legacy = array_intersect_key($suggestions, array_flip(['recommended_archetypes', 'mission_suggestion', 'positioning_suggestion', 'tone_suggestion']));
            $result = array_merge($defaults, $legacy);
            self::enrichFromEvidenceMap($result, $snapshot);
            return $result;
        }

        // New format: list of structured items
        $first = $suggestions[0] ?? null;
        if (is_array($first) && isset($first['path'])) {
            $result = self::transformStructured($suggestions);
            self::enrichFromEvidenceMap($result, $snapshot);
            return $result;
        }

        self::enrichFromEvidenceMap($defaults, $snapshot);
        return $defaults;
    }

    /**
     * @param  array<int, array{path?: string, value?: mixed, confidence?: float, auto_apply?: bool, source?: array}>  $suggestions
     */
    protected static function transformStructured(array $suggestions): array
    {
        $items = [];
        $autoApplied = [];
        $suggested = [];
        $ignored = [];

        $pathToLegacy = [
            'personality.primary_archetype' => 'recommended_archetypes',
            'identity.mission' => 'mission_suggestion',
            'identity.positioning' => 'positioning_suggestion',
            'scoring_rules.tone_keywords' => 'tone_suggestion',
            'identity.tagline' => 'tagline_suggestion',
            'identity.industry' => 'industry_suggestion',
            'identity.target_audience' => 'target_audience_suggestion',
            'identity.beliefs' => 'beliefs_suggestion',
            'identity.values' => 'values_suggestion',
            'personality.traits' => 'traits_suggestion',
            'personality.tone_keywords' => 'tone_keywords_suggestion',
            'personality.voice_description' => 'voice_description_suggestion',
            'personality.brand_look' => 'brand_look_suggestion',
        ];

        $legacy = [
            'recommended_archetypes' => [],
            'mission_suggestion' => null,
            'positioning_suggestion' => null,
            'tone_suggestion' => null,
            'tagline_suggestion' => null,
            'industry_suggestion' => null,
            'target_audience_suggestion' => null,
            'beliefs_suggestion' => null,
            'values_suggestion' => null,
            'traits_suggestion' => null,
            'tone_keywords_suggestion' => null,
            'voice_description_suggestion' => null,
            'brand_look_suggestion' => null,
            'brand_color_suggestions' => [],
            'fonts_suggestion' => [],
        ];

        foreach ($suggestions as $s) {
            if (! is_array($s)) {
                continue;
            }
            $path = $s['path'] ?? null;
            $confidence = (float) ($s['confidence'] ?? 0);
            $autoApply = $s['auto_apply'] ?? false;

            if ($confidence < ExtractionSuggestionService::CONFIDENCE_MIN) {
                if ($path) {
                    $ignored[] = $path;
                }
                continue;
            }

            $items[] = $s;

            if ($autoApply) {
                if ($path) {
                    $autoApplied[] = $path;
                }
            } else {
                if ($path) {
                    $suggested[] = $path;
                }
            }

            if ($path && isset($pathToLegacy[$path])) {
                $value = $s['value'] ?? null;
                $key = $pathToLegacy[$path];
                if ($key === 'recommended_archetypes') {
                    $legacy['recommended_archetypes'][] = array_merge(
                        is_array($value) ? $value : ['label' => $value],
                        ['confidence' => $confidence]
                    );
                } elseif (in_array($key, ['mission_suggestion', 'positioning_suggestion', 'tagline_suggestion', 'industry_suggestion', 'target_audience_suggestion', 'voice_description_suggestion', 'brand_look_suggestion'], true)) {
                    $unwrapped = is_array($value) && array_key_exists('value', $value) && isset($value['source']) ? $value['value'] : $value;
                    $legacy[$key] = is_string($unwrapped) ? $unwrapped : (string) ($unwrapped ?? '');
                } elseif (in_array($key, ['tone_suggestion', 'beliefs_suggestion', 'values_suggestion', 'traits_suggestion', 'tone_keywords_suggestion'], true)) {
                    $unwrapped = is_array($value) && array_key_exists('value', $value) && isset($value['source']) ? $value['value'] : $value;
                    $legacy[$key] = is_array($unwrapped) ? $unwrapped : (array) $unwrapped;
                }
            }

            if ($path === 'typography.fonts') {
                $value = $s['value'] ?? [];
                $unwrapped = is_array($value) && array_key_exists('value', $value) && isset($value['source']) ? $value['value'] : $value;
                $legacy['fonts_suggestion'] = is_array($unwrapped) ? $unwrapped : [];
            }

            if ($path && str_starts_with($path, 'brand_colors.')) {
                $colorKey = str_replace('brand_colors.', '', $path);
                $legacy['brand_color_suggestions'][] = [
                    'key' => $colorKey,
                    'value' => $s['value'] ?? null,
                    'confidence' => $confidence,
                ];
            }
        }

        return array_merge($legacy, [
            'items' => $items,
            'auto_applied' => array_values(array_unique($autoApplied)),
            'suggested' => array_values(array_unique($suggested)),
            'ignored' => array_values(array_unique($ignored)),
        ]);
    }

    /**
     * Enrich recommended_archetypes from snapshot evidence_map when archetype is in evidence but not in suggestions.
     */
    protected static function enrichFromEvidenceMap(array &$result, array $snapshot): void
    {
        $evidenceMap = $snapshot['evidence_map'] ?? [];
        $archetypeEvidence = $evidenceMap['personality.primary_archetype'] ?? null;
        if (empty($archetypeEvidence) || ! is_array($archetypeEvidence)) {
            return;
        }
        $finalValue = $archetypeEvidence['final_value'] ?? null;
        if ($finalValue === null || $finalValue === '') {
            return;
        }
        $existing = $result['recommended_archetypes'] ?? [];
        $existingLabels = array_map(fn ($a) => is_string($a) ? $a : ($a['label'] ?? $a['archetype'] ?? ''), $existing);
        if (in_array($finalValue, $existingLabels, true)) {
            return;
        }
        $confidence = 0.98;
        if (! empty($archetypeEvidence['winning_reason']) && $archetypeEvidence['winning_reason'] === 'explicit_archetype_match') {
            $confidence = 0.98;
        }
        $result['recommended_archetypes'][] = [
            'label' => is_string($finalValue) ? $finalValue : ($finalValue['value'] ?? (string) $finalValue),
            'confidence' => $confidence,
        ];
    }
}

