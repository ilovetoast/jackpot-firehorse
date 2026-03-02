<?php

namespace App\Services\BrandDNA\Extraction;

class BrandExtractionSchema
{
    public static function empty(): array
    {
        return [
            'identity' => [
                'mission' => null,
                'vision' => null,
                'positioning' => null,
                'industry' => null,
                'tagline' => null,
            ],
            'personality' => [
                'primary_archetype' => null,
                'traits' => [],
                'tone_keywords' => [],
            ],
            'visual' => [
                'primary_colors' => [],
                'secondary_colors' => [],
                'fonts' => [],
                'logo_detected' => null,
            ],
            'explicit_signals' => [
                'archetype_declared' => false,
                'mission_declared' => false,
                'positioning_declared' => false,
            ],
            'sources' => [
                'pdf' => [],
                'website' => [],
                'materials' => [],
            ],
            'confidence' => 0.0,
        ];
    }

    public static function merge(array ...$extractions): array
    {
        $result = self::empty();
        foreach ($extractions as $ext) {
            $result = self::mergeOne($result, $ext);
        }
        return $result;
    }

    protected static function mergeOne(array $base, array $ext): array
    {
        foreach (['identity', 'personality', 'visual', 'explicit_signals', 'sources'] as $section) {
            if (!isset($ext[$section]) || !is_array($ext[$section])) continue;
            foreach ($ext[$section] as $key => $value) {
                if ($value === null || $value === []) continue;
                if (is_array($value) && isset($base[$section][$key]) && is_array($base[$section][$key])) {
                    $base[$section][$key] = array_values(array_unique(array_merge(
                        $base[$section][$key],
                        is_array($value) ? $value : [$value]
                    )));
                } else {
                    $base[$section][$key] = $value;
                }
            }
        }
        if (isset($ext['confidence']) && is_numeric($ext['confidence'])) {
            $base['confidence'] = max($base['confidence'], (float)$ext['confidence']);
        }
        return $base;
    }
}
