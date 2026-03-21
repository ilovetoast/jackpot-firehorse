<?php

namespace App\Services\AI\Insights;

/**
 * Priority ranking for Insights Review — surfaces best suggestions first.
 */
class SuggestionPriority
{
    public const CONFIDENCE_WEIGHT = 0.6;

    public const SUPPORT_LOG_WEIGHT = 0.4;

    /**
     * priority_score = confidence * 0.6 + log(supporting_assets) * 0.4 (natural log; supporting floored at 1).
     */
    public static function score(float $confidence, int $supportingAssetCount): float
    {
        $c = max(0.0, min(1.0, $confidence));
        $n = max(1, $supportingAssetCount);

        return round(
            $c * self::CONFIDENCE_WEIGHT + log($n) * self::SUPPORT_LOG_WEIGHT,
            4
        );
    }
}
