<?php

namespace App\Services;

use App\Models\AlertCandidate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 4 Step 4 — Alert Candidate Service
 * 
 * Consumes pattern detection results from locked phases only.
 * Must not modify detection rules, aggregation logic, or event producers.
 * 
 * AlertCandidateService
 * 
 * Manages creation and updates of alert candidates based on pattern detection results.
 * Handles deduplication by updating existing open alerts rather than creating duplicates.
 * 
 * NO NOTIFICATIONS — only persists alert candidates.
 * NO ALERT DELIVERY — records are for review, suppression, escalation, or AI explanation.
 */
class AlertCandidateService
{
    /**
     * Process pattern detection results and create/update alert candidates.
     * 
     * @param Collection<array{
     *   rule_id: int,
     *   rule_name: string,
     *   scope: string,
     *   subject_id: string|null,
     *   severity: string,
     *   observed_count: int,
     *   threshold_count: int,
     *   window_minutes: int,
     *   metadata_summary: array
     * }> $detectionResults
     * @param Carbon|null $detectedAt Time when detection occurred (defaults to now)
     * @return Collection<AlertCandidate> Created or updated alert candidates
     */
    public function processDetectionResults(Collection $detectionResults, ?Carbon $detectedAt = null): Collection
    {
        $detectedAt = $detectedAt ?: Carbon::now();

        Log::debug('[AlertCandidateService] Processing detection results', [
            'result_count' => $detectionResults->count(),
            'detected_at' => $detectedAt->toIso8601String(),
        ]);

        $processed = collect();

        foreach ($detectionResults as $result) {
            try {
                $alert = $this->createOrUpdateAlert($result, $detectedAt);
                $processed->push($alert);
            } catch (\Throwable $e) {
                Log::error('[AlertCandidateService] Error processing detection result', [
                    'rule_id' => $result['rule_id'] ?? null,
                    'scope' => $result['scope'] ?? null,
                    'subject_id' => $result['subject_id'] ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue with other results
            }
        }

        Log::info('[AlertCandidateService] Detection results processed', [
            'processed_count' => $processed->count(),
            'total_results' => $detectionResults->count(),
        ]);

        return $processed;
    }

    /**
     * Create or update an alert candidate based on a detection result.
     * 
     * Deduplication Strategy:
     * - Look for existing open alert with same rule_id + scope + subject_id
     * - If found: Update (increment detection_count, update last_detected_at, update observed_count)
     * - If not found: Create new alert candidate
     * - Allows multiple alerts for same rule+scope+subject if previous is acknowledged/resolved
     * 
     * @param array{
     *   rule_id: int,
     *   rule_name: string,
     *   scope: string,
     *   subject_id: string|null,
     *   severity: string,
     *   observed_count: int,
     *   threshold_count: int,
     *   window_minutes: int,
     *   metadata_summary: array
     * } $result
     * @param Carbon $detectedAt
     * @return AlertCandidate
     */
    public function createOrUpdateAlert(array $result, Carbon $detectedAt): AlertCandidate
    {
        // Normalize subject_id (convert null to empty string for unique constraint)
        $subjectId = $result['subject_id'] ?? null;

        // Extract tenant_id from result if available (for tenant/asset/download scopes)
        $tenantId = null;
        if ($result['scope'] === 'tenant' && $subjectId) {
            $tenantId = (int) $subjectId;
        } elseif (in_array($result['scope'], ['asset', 'download']) && $subjectId) {
            // For asset/download scopes, tenant_id should be extracted from metadata or relationships
            // For now, we'll leave it null and let it be populated later if needed
            // This can be enhanced in future phases
        }

        // Look for existing open alert with same rule + scope + subject
        $existing = AlertCandidate::where('rule_id', $result['rule_id'])
            ->where('scope', $result['scope'])
            ->where('subject_id', $subjectId)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            // Update existing alert: increment detection count, update timestamps and counts
            $existing->update([
                'observed_count' => $result['observed_count'], // Update to latest observed count
                'last_detected_at' => $detectedAt,
                'detection_count' => $existing->detection_count + 1,
                'context' => $result['metadata_summary'] ?? null, // Update context with latest metadata
                'tenant_id' => $tenantId ?: $existing->tenant_id, // Update tenant_id if provided
            ]);

            Log::debug('[AlertCandidateService] Updated existing alert candidate', [
                'alert_id' => $existing->id,
                'rule_id' => $result['rule_id'],
                'scope' => $result['scope'],
                'subject_id' => $subjectId,
                'detection_count' => $existing->detection_count,
            ]);

            return $existing->fresh();
        }

        // Create new alert candidate
        $alert = AlertCandidate::create([
            'rule_id' => $result['rule_id'],
            'scope' => $result['scope'],
            'subject_id' => $subjectId,
            'tenant_id' => $tenantId,
            'severity' => $result['severity'],
            'observed_count' => $result['observed_count'],
            'threshold_count' => $result['threshold_count'],
            'window_minutes' => $result['window_minutes'],
            'status' => 'open',
            'first_detected_at' => $detectedAt,
            'last_detected_at' => $detectedAt,
            'detection_count' => 1,
            'context' => $result['metadata_summary'] ?? null,
        ]);

        Log::debug('[AlertCandidateService] Created new alert candidate', [
            'alert_id' => $alert->id,
            'rule_id' => $result['rule_id'],
            'scope' => $result['scope'],
            'subject_id' => $subjectId,
            'severity' => $result['severity'],
        ]);

        return $alert;
    }

    /**
     * Get open alert candidates.
     * 
     * @param array $filters Optional filters (severity, tenant_id, rule_id, scope)
     * @return Collection<AlertCandidate>
     */
    public function getOpenAlerts(array $filters = []): Collection
    {
        $query = AlertCandidate::open();

        if (isset($filters['severity'])) {
            $query->bySeverity($filters['severity']);
        }

        if (isset($filters['tenant_id'])) {
            $query->forTenant($filters['tenant_id']);
        }

        if (isset($filters['rule_id'])) {
            $query->byRule($filters['rule_id']);
        }

        if (isset($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }

        return $query->orderBy('first_detected_at', 'desc')->get();
    }

    /**
     * Acknowledge an alert candidate.
     * 
     * Status transition: open → acknowledged
     * This allows a new alert to be created for the same rule+scope+subject if condition persists.
     * 
     * @param int $alertId
     * @return AlertCandidate|null
     */
    public function acknowledgeAlert(int $alertId): ?AlertCandidate
    {
        $alert = AlertCandidate::find($alertId);

        if (!$alert) {
            Log::warning('[AlertCandidateService] Attempted to acknowledge non-existent alert', [
                'alert_id' => $alertId,
            ]);
            return null;
        }

        $alert->update([
            'status' => 'acknowledged',
        ]);

        Log::info('[AlertCandidateService] Alert acknowledged', [
            'alert_id' => $alert->id,
            'rule_id' => $alert->rule_id,
        ]);

        return $alert->fresh();
    }

    /**
     * Resolve an alert candidate.
     * 
     * Status transition: open → resolved (or acknowledged → resolved)
     * This allows a new alert to be created for the same rule+scope+subject if condition recurs.
     * 
     * @param int $alertId
     * @return AlertCandidate|null
     */
    public function resolveAlert(int $alertId): ?AlertCandidate
    {
        $alert = AlertCandidate::find($alertId);

        if (!$alert) {
            Log::warning('[AlertCandidateService] Attempted to resolve non-existent alert', [
                'alert_id' => $alertId,
            ]);
            return null;
        }

        $alert->update([
            'status' => 'resolved',
        ]);

        Log::info('[AlertCandidateService] Alert resolved', [
            'alert_id' => $alert->id,
            'rule_id' => $alert->rule_id,
        ]);

        return $alert->fresh();
    }
}
