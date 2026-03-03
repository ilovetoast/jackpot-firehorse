<?php

namespace App\Services\BrandDNA\Extraction;

/**
 * Source weight constants for extraction signals.
 * Authority: User draft > PDF explicit > Materials > Website > AI > Heuristic.
 */
class SignalWeights
{
    public const PDF_EXPLICIT = 1.0;
    public const PDF_INFERRED = 0.85;
    public const MATERIALS_EXPLICIT = 0.9;
    public const WEBSITE_DETERMINISTIC = 0.7;
    public const AI_INFERENCE = 0.6;
    public const HEURISTIC = 0.5;

    public static function wrap(string $source, string $sourceType, float $weight, mixed $value): array
    {
        return [
            'value' => $value,
            'source' => $source,
            'source_type' => $sourceType,
            'weight' => $weight,
        ];
    }

    public static function unwrap(mixed $signal): mixed
    {
        if (is_array($signal) && isset($signal['value'])) {
            return $signal['value'];
        }
        return $signal;
    }

    public static function getWeight(mixed $signal): float
    {
        if (is_array($signal) && isset($signal['weight'])) {
            return (float) $signal['weight'];
        }
        return 1.0;
    }
}
