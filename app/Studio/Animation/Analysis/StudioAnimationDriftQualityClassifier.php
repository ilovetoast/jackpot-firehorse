<?php

namespace App\Studio\Animation\Analysis;

/**
 * Maps drift analysis into a coarse product signal (low / medium / high).
 */
final class StudioAnimationDriftQualityClassifier
{
    /**
     * @param  array<string, mixed>  $drift  Output of StartFrameDriftAnalyzer::analyze plus optional keys
     */
    public static function classify(array $drift): string
    {
        $status = (string) ($drift['frame_drift_status'] ?? '');
        if ($status === 'match') {
            return 'low';
        }
        if ($status === 'unavailable') {
            return 'low';
        }

        $reasons = $drift['mismatch_reasons'] ?? [];
        if (! is_array($reasons)) {
            $reasons = [];
        }
        foreach ($reasons as $r) {
            if (is_string($r) && str_contains($r, 'dimension_mismatch')) {
                return 'high';
            }
        }

        $score = $drift['frame_drift_score'] ?? null;
        $highT = (float) config('studio_animation.drift_quality.high_score_threshold', 0.02);
        $medT = (float) config('studio_animation.drift_quality.medium_score_threshold', 0.0);

        if (is_numeric($score) && (float) $score > $highT) {
            return 'high';
        }
        if (is_numeric($score) && (float) $score > $medT) {
            return 'medium';
        }

        return 'medium';
    }
}
