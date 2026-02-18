<?php

namespace App\Services\Reliability;

use App\Models\Asset;
use App\Models\SystemIncident;
use Illuminate\Support\Facades\DB;

/**
 * Centralized reliability metrics.
 *
 * - Integrity rate (visual metadata)
 * - MTTR
 * - Recovery success rate
 * - Ticket escalation rate
 */
class ReliabilityMetricsService
{
    protected const MTTR_WINDOW_HOURS = 24;

    /**
     * Visual metadata integrity: % of eligible assets where visualMetadataReady.
     */
    public function getIntegrityRate(): array
    {
        try {
            $eligible = Asset::whereSupportsThumbnailMetadata()->count();
            $invalid = Asset::whereSupportsThumbnailMetadata()->whereVisualMetadataInvalid()->count();
            $valid = max(0, $eligible - $invalid);
            $ratePercent = $eligible > 0 ? round(100 * $valid / $eligible, 1) : 100;

            $incidentsCount = SystemIncident::whereNull('resolved_at')
                ->where('title', 'Expected visual metadata missing')
                ->count();

            $sloTarget = 95;
            $status = $ratePercent >= $sloTarget ? 'healthy' : ($ratePercent >= 80 ? 'warning' : 'critical');

            return [
                'status' => $status,
                'rate_percent' => $ratePercent,
                'eligible' => $eligible,
                'invalid' => $invalid,
                'valid' => $valid,
                'incidents_count' => $incidentsCount,
                'slo_target_percent' => $sloTarget,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unknown',
                'rate_percent' => 0,
                'eligible' => 0,
                'invalid' => 0,
                'valid' => 0,
                'incidents_count' => 0,
                'slo_target_percent' => 95,
            ];
        }
    }

    /**
     * Mean Time To Repair — average resolution time for incidents in last 24h.
     */
    public function getMTTR(): array
    {
        try {
            $since = now()->subHours(self::MTTR_WINDOW_HOURS);
            $result = SystemIncident::whereNotNull('resolved_at')
                ->where('resolved_at', '>=', $since)
                ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, detected_at, resolved_at)) as mttr_minutes_avg')
                ->selectRaw('COUNT(*) as resolved_count')
                ->first();

            $mttrMinutes = $result && $result->mttr_minutes_avg !== null
                ? (float) $result->mttr_minutes_avg
                : null;

            return [
                'mttr_minutes_avg' => $mttrMinutes,
                'resolved_count_24h' => (int) ($result->resolved_count ?? 0),
                'window_hours' => self::MTTR_WINDOW_HOURS,
            ];
        } catch (\Throwable $e) {
            return [
                'mttr_minutes_avg' => null,
                'resolved_count_24h' => 0,
                'window_hours' => self::MTTR_WINDOW_HOURS,
            ];
        }
    }

    /**
     * Recovery success rate: % of resolved incidents that were auto-resolved.
     */
    public function getRecoverySuccessRate(): array
    {
        try {
            $since = now()->subHours(self::MTTR_WINDOW_HOURS);
            $resolved = SystemIncident::whereNotNull('resolved_at')
                ->where('resolved_at', '>=', $since);

            $total = (clone $resolved)->count();
            $autoResolved = (clone $resolved)->where('auto_resolved', true)->count();

            $ratePercent = $total > 0 ? round(100 * $autoResolved / $total, 1) : 100;

            return [
                'recovery_rate_percent' => $ratePercent,
                'auto_resolved_count' => $autoResolved,
                'manual_resolved_count' => $total - $autoResolved,
                'total_resolved' => $total,
                'window_hours' => self::MTTR_WINDOW_HOURS,
            ];
        } catch (\Throwable $e) {
            return [
                'recovery_rate_percent' => 0,
                'auto_resolved_count' => 0,
                'manual_resolved_count' => 0,
                'total_resolved' => 0,
                'window_hours' => self::MTTR_WINDOW_HOURS,
            ];
        }
    }

    /**
     * Ticket escalation: tickets created from operations_incident in last 24h.
     */
    public function getTicketEscalationRate(): array
    {
        try {
            $since = now()->subHours(self::MTTR_WINDOW_HOURS);
            $escalatedCount = \App\Models\Ticket::where('metadata->source', 'operations_incident')
                ->where('created_at', '>=', $since)
                ->count();

            $totalResolved = SystemIncident::whereNotNull('resolved_at')
                ->where('resolved_at', '>=', $since)
                ->count();

            $unresolved = SystemIncident::whereNull('resolved_at')->count();

            return [
                'escalated_count_24h' => $escalatedCount,
                'total_resolved_24h' => $totalResolved,
                'unresolved_count' => $unresolved,
                'window_hours' => self::MTTR_WINDOW_HOURS,
            ];
        } catch (\Throwable $e) {
            return [
                'escalated_count_24h' => 0,
                'total_resolved_24h' => 0,
                'unresolved_count' => 0,
                'window_hours' => self::MTTR_WINDOW_HOURS,
            ];
        }
    }

    /**
     * All metrics in one call.
     */
    public function getAll(): array
    {
        return [
            'integrity' => $this->getIntegrityRate(),
            'mttr' => $this->getMTTR(),
            'recovery_success' => $this->getRecoverySuccessRate(),
            'ticket_escalation' => $this->getTicketEscalationRate(),
        ];
    }
}
