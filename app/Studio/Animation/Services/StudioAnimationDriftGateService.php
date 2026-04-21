<?php

namespace App\Studio\Animation\Services;

/**
 * Optional drift-based submission gating (default: warn metadata only).
 *
 * @phpstan-type DriftDecision array{
 *   drift_checked: bool,
 *   drift_warned: bool,
 *   drift_blocked: bool,
 *   blocked_reason: string|null,
 *   gate_mode: string,
 *   gate_enabled: bool
 * }
 */
final class StudioAnimationDriftGateService
{
    /**
     * @param  array<string, mixed>|null  $canonicalFrame
     * @param  array<string, mixed>  $jobSettings  merged settings (may include preflight_risk)
     * @return DriftDecision
     */
    public function evaluate(?array $canonicalFrame, array $jobSettings = []): array
    {
        $enabled = (bool) config('studio_animation.drift_gate.enabled', false);
        $mode = (string) config('studio_animation.drift_gate.mode', 'warn_only');
        $threshold = (float) config('studio_animation.drift_gate.score_threshold', 0.15);
        $strict = (bool) config('studio_animation.drift_gate.strict_drift_block', false);

        $base = [
            'drift_checked' => false,
            'drift_warned' => false,
            'drift_blocked' => false,
            'blocked_reason' => null,
            'gate_mode' => $mode,
            'gate_enabled' => $enabled,
        ];

        if ($canonicalFrame === null || $canonicalFrame === []) {
            return $base;
        }

        $base['drift_checked'] = true;

        if (! $enabled) {
            return $base;
        }

        $status = (string) ($canonicalFrame['frame_drift_status'] ?? '');
        $score = $canonicalFrame['frame_drift_score'] ?? null;
        $isMismatch = $status === 'mismatch';
        $driftLevel = (string) ($canonicalFrame['drift_level'] ?? 'low');

        if ($mode === 'warn_only') {
            if ($isMismatch) {
                $base['drift_warned'] = true;
            }

            return $base;
        }

        if ($mode === 'block_any' && $isMismatch) {
            $base['drift_warned'] = true;
            $base['drift_blocked'] = true;
            $base['blocked_reason'] = 'drift_mismatch_block_any';

            return $base;
        }

        if ($mode === 'block_high' && $isMismatch) {
            if ($strict && ($driftLevel === 'medium' || $driftLevel === 'high')) {
                $base['drift_warned'] = true;
                $base['drift_blocked'] = true;
                $base['blocked_reason'] = 'drift_level_medium_or_high_strict';

                return $base;
            }

            if (! $strict && $driftLevel === 'high') {
                $base['drift_warned'] = true;
                $base['drift_blocked'] = true;
                $base['blocked_reason'] = 'drift_level_high';

                return $base;
            }

            $preflight = $jobSettings['preflight_risk'] ?? null;
            $riskLevel = is_array($preflight) ? (string) ($preflight['risk_level'] ?? '') : '';
            $scoreExceeds = is_numeric($score) && (float) $score > $threshold;

            if ($riskLevel === 'high' || $scoreExceeds) {
                $base['drift_warned'] = true;
                $base['drift_blocked'] = true;
                $base['blocked_reason'] = $riskLevel === 'high'
                    ? 'drift_mismatch_preflight_high'
                    : 'drift_mismatch_score_threshold';

                return $base;
            }

            $base['drift_warned'] = true;

            return $base;
        }

        if ($isMismatch) {
            $base['drift_warned'] = true;
        }

        return $base;
    }

    public function shouldAbortSubmission(array $decision): bool
    {
        return ($decision['drift_blocked'] ?? false) === true;
    }
}
