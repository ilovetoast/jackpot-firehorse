<?php

namespace App\Enums;

/**
 * Brand Intelligence alignment outcome (Execution-Based Intelligence scoring).
 */
enum BrandAlignmentState: string
{
    case ON_BRAND = 'on_brand';
    case PARTIAL_ALIGNMENT = 'partial_alignment';
    case OFF_BRAND = 'off_brand';
    case INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    /**
     * Legacy EBI `level` (low|medium|high|unknown) for backward compatibility.
     */
    public function toLegacyLevel(): string
    {
        return match ($this) {
            self::ON_BRAND => 'high',
            self::PARTIAL_ALIGNMENT => 'medium',
            self::OFF_BRAND => 'low',
            self::INSUFFICIENT_EVIDENCE => 'unknown',
        };
    }

    /**
     * Map a 0–1 similarity-style score (sufficient evidence path only).
     */
    public static function fromNormalizedScore(float $n): self
    {
        $n = max(0.0, min(1.0, $n));
        if ($n < 0.4) {
            return self::OFF_BRAND;
        }
        if ($n <= 0.7) {
            return self::PARTIAL_ALIGNMENT;
        }

        return self::ON_BRAND;
    }
}
