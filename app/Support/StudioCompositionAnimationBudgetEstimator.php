<?php

namespace App\Support;

/**
 * Matches {@see \App\Studio\Animation\Services\StudioAnimationCompletionService::buildStudioAnimationCostBreakdown} COGS total.
 */
final class StudioCompositionAnimationBudgetEstimator
{
    public static function estimateCogsUsdForDurationSeconds(int $durationSeconds): float
    {
        $perJob = (float) config('studio_animation.cost_tracking.estimated_usd_per_job', 1.0);
        $perExtraSec = (float) config('studio_animation.cost_tracking.estimated_usd_per_extra_second', 0.0);
        $covers = max(1, (int) config('studio_animation.credits.base_covers_seconds', 5));
        $d = max(1, $durationSeconds);
        $extra = max(0, $d - $covers);

        return round($perJob + $extra * $perExtraSec, 6);
    }
}
