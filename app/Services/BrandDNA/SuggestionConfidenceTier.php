<?php

namespace App\Services\BrandDNA;

/**
 * Maps suggestion weight to confidence tier for badge display.
 * Does not change suggestion confidence logic.
 */
class SuggestionConfidenceTier
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /**
     * @return 'high'|'medium'|'low'
     */
    public static function fromWeight(float $weight): string
    {
        if ($weight >= 0.9) {
            return self::HIGH;
        }
        if ($weight >= 0.7) {
            return self::MEDIUM;
        }

        return self::LOW;
    }

    /**
     * Add confidence_tier to each suggestion. Uses weight if present, else confidence.
     */
    public static function addToSuggestions(array $suggestions): array
    {
        return array_map(function ($s) {
            $weight = (float) ($s['weight'] ?? $s['confidence'] ?? 0);
            $s['confidence_tier'] = self::fromWeight($weight);

            return $s;
        }, $suggestions);
    }
}
