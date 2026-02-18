<?php

namespace App\Services\Reliability;

use App\Models\SystemIncident;
use Illuminate\Support\Facades\Log;

/**
 * Centralized escalation rules for incidents.
 *
 * Rules:
 * - critical → immediate ticket
 * - error → after 1 failed repair
 * - warning → after 3 attempts
 * - age > 15 minutes escalates one level
 */
class EscalationPolicy
{
    protected const AGE_ESCALATION_MINUTES = 15;

    /**
     * Whether a ticket should be created for this incident.
     */
    public function shouldCreateTicket(SystemIncident $incident): bool
    {
        $repairAttempts = (int) ($incident->metadata['repair_attempts'] ?? $incident->metadata['recovery_attempt_count'] ?? 0);
        $effectiveSeverity = $this->effectiveSeverity($incident);

        if ($effectiveSeverity === 'critical') {
            return true;
        }

        if ($effectiveSeverity === 'error') {
            return $repairAttempts >= 1;
        }

        if ($effectiveSeverity === 'warning') {
            return $repairAttempts >= 3;
        }

        return false;
    }

    /**
     * Severity after age-based escalation (stuck > 15 min → one level up).
     */
    public function effectiveSeverity(SystemIncident $incident): string
    {
        $base = strtolower($incident->severity ?? 'info');

        if (!$incident->detected_at) {
            return $base;
        }

        $minutesStuck = $incident->detected_at->diffInMinutes(now());
        if ($minutesStuck < self::AGE_ESCALATION_MINUTES) {
            return $base;
        }

        return match ($base) {
            'info' => 'warning',
            'warning' => 'error',
            'error' => 'critical',
            'critical' => 'critical',
            default => $base,
        };
    }

    /**
     * Apply age-based escalation to incident (update severity if stuck > 15 min).
     */
    public function applyAgeEscalation(SystemIncident $incident): void
    {
        $effective = $this->effectiveSeverity($incident);
        $current = strtolower($incident->severity ?? 'info');

        if ($effective !== $current && $effective === 'critical') {
            $incident->update(['severity' => 'critical']);
            Log::info('[EscalationPolicy] Escalated incident due to age', [
                'incident_id' => $incident->id,
                'title' => $incident->title,
                'previous_severity' => $current,
                'new_severity' => 'critical',
            ]);
        }
    }
}
