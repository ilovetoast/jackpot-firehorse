<?php

namespace App\Enums;

/**
 * Outcome status for a single alignment dimension.
 *
 * Status derivation constraints (hard rules):
 *  - ALIGNED requires at least one HARD-weight evidence item.
 *  - PARTIAL requires at least one HARD or SOFT evidence item.
 *  - Readiness-only evidence can produce at most WEAK.
 *  - CONFIGURATION_ONLY evidence alone → NOT_EVALUABLE or MISSING_REFERENCE, never ALIGNED/PARTIAL/WEAK.
 *  - NOT_EVALUABLE must be used when the extraction path was unavailable, never WEAK or FAIL.
 */
enum DimensionStatus: string
{
    case ALIGNED = 'aligned';
    case PARTIAL = 'partial';
    case WEAK = 'weak';
    case NOT_EVALUABLE = 'not_evaluable';
    case MISSING_REFERENCE = 'missing_reference';
    case FAIL = 'fail';

    public function isEvaluable(): bool
    {
        return ! in_array($this, [self::NOT_EVALUABLE, self::MISSING_REFERENCE], true);
    }

    public function isPositive(): bool
    {
        return in_array($this, [self::ALIGNED, self::PARTIAL], true);
    }

    /**
     * UI-facing short label for quick-view chips.
     */
    public function quickViewLabel(): string
    {
        return match ($this) {
            self::ALIGNED => 'Aligned',
            self::PARTIAL => 'Partial',
            self::WEAK => 'Weak evidence',
            self::NOT_EVALUABLE => 'Not evaluated',
            self::MISSING_REFERENCE => 'No reference',
            self::FAIL => 'Not aligned',
        };
    }
}
