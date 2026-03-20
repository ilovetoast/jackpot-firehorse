<?php

namespace App\Services\BrandDNA;

/**
 * Brand DNA Payload Normalizer — ensures defaults exist for model_payload keys.
 * Additive only: never removes or renames existing keys.
 * Used when loading/saving drafts for the Brand Guidelines Builder.
 *
 * Canonical field locations (use these for snapshot vs draft comparison):
 * - tone_keywords: scoring_rules.tone_keywords (personality.tone_keywords is legacy, normalized here)
 * - traits: personality.traits
 * - allowed_color_palette: scoring_rules.allowed_color_palette
 */
class BrandDnaPayloadNormalizer
{
    /**
     * Default structure for new keys. Existing keys are preserved.
     * Schema additions per Brand Guidelines Builder v1:
     * - personality: primary_archetype, candidate_archetypes, rejected_archetypes
     * - identity: beliefs, values
     * - visual: visual_density, textures
     */
    protected static function defaults(): array
    {
        return [
            'sources' => [
                'website_url' => null,
                'social_urls' => [],
                'notes' => null,
            ],
            'personality' => [
                'primary_archetype' => null,
                'candidate_archetypes' => [],
                'rejected_archetypes' => [],
                'archetype' => null, // legacy; keep compatible
                'traits' => [],
                'tone' => null,
                'voice' => null,
                'voice_description' => null,
                'brand_voice' => null,
                'brand_look' => null,
            ],
            'identity' => [
                'beliefs' => [],
                'values' => [],
                'tagline' => null,
                'mission' => null,
                'positioning' => null,
                'industry' => null,
                'target_audience' => null,
                'market_category' => null,
                'competitive_position' => null,
            ],
            'visual' => [
                'visual_density' => null,
                'textures' => [],
                'approved_references' => [],
                'style' => null,
                'composition' => null,
                'color_temperature' => null,
                'photography_style' => null,
                'composition_style' => null,
                'color_system' => [],
                'brand_look' => null,
            ],
            'typography' => [
                'primary_font' => null,
                'primary_font_style' => null,
                'secondary_font' => null,
                'secondary_font_style' => null,
                'font_mood' => null,
                'heading_style' => null,
                'headline_treatment' => null,
                'headline_appearance_features' => [],
                'body_style' => null,
                'external_font_links' => [],
                'fonts' => [],
            ],
            'scoring_rules' => [
                'allowed_color_palette' => [],
                'allowed_fonts' => [],
                'banned_colors' => [],
                'tone_keywords' => [],
                'banned_keywords' => [],
                'photography_attributes' => [],
            ],
            'scoring_config' => [
                'color_weight' => 10,
                'typography_weight' => 20,
                'tone_weight' => 20,
                'imagery_weight' => 50,
            ],
        ];
    }

    /**
     * Normalize payload: merge defaults into existing, preserving all existing values.
     * Only adds missing keys; never overwrites.
     */
    public function normalize(array $payload): array
    {
        $defaults = self::defaults();
        $result = $payload;

        foreach ($defaults as $section => $sectionDefaults) {
            if (! is_array($sectionDefaults)) {
                continue;
            }
            $existing = $result[$section] ?? [];
            if (! is_array($existing)) {
                $existing = [];
            }
            foreach ($sectionDefaults as $key => $defaultValue) {
                if (! array_key_exists($key, $existing)) {
                    $existing[$key] = $defaultValue;
                }
            }
            $result[$section] = $existing;
        }

        // Canonical: scoring_rules.tone_keywords. Normalize legacy personality.tone_keywords into it.
        $result = $this->normalizeToneKeywords($result);

        return $result;
    }

    /**
     * Ensure tone_keywords has a single canonical location: scoring_rules.tone_keywords.
     * If personality.tone_keywords has values and scoring_rules is empty, copy. Otherwise scoring_rules wins.
     */
    protected function normalizeToneKeywords(array $payload): array
    {
        $scoringRules = $payload['scoring_rules'] ?? [];
        $personality = $payload['personality'] ?? [];

        $scoringTone = $scoringRules['tone_keywords'] ?? [];
        $personalityTone = $personality['tone_keywords'] ?? [];

        $scoringTone = is_array($scoringTone) ? $scoringTone : [];
        $personalityTone = is_array($personalityTone) ? $personalityTone : [];

        if (empty($scoringTone) && ! empty($personalityTone)) {
            $scoringRules['tone_keywords'] = array_values(array_unique($personalityTone));
            $payload['scoring_rules'] = array_merge($payload['scoring_rules'] ?? [], $scoringRules);
        }

        return $payload;
    }
}
