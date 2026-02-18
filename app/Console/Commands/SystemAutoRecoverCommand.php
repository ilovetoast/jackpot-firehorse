<?php

namespace App\Console\Commands;

use App\Models\SystemIncident;
use App\Services\Reliability\ReliabilityEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic auto-recovery loop for operational incidents.
 *
 * - Fetches unresolved incidents (limit 200)
 * - For asset incidents: reconcile, resolve if fixed, else create ticket per SLA
 * - For job incidents: retry if retryable, else ensure ticket exists
 * - Max 50 recoveries per run, chunking, logging
 */
class SystemAutoRecoverCommand extends Command
{
    protected $signature = 'system:auto-recover';

    protected $description = 'Auto-recover unresolved system incidents (reconcile, retry, create tickets)';

    protected const MAX_INCIDENTS_PER_RUN = 200;
    protected const MAX_RECOVERIES_PER_RUN = 50;

    public function handle(ReliabilityEngine $reliabilityEngine): int
    {
        $incidents = SystemIncident::whereNull('resolved_at')
            ->orderBy('detected_at', 'asc')
            ->limit(self::MAX_INCIDENTS_PER_RUN)
            ->get();

        $processed = 0;
        $resolved = 0;
        $ticketsCreated = 0;
        $retriesDispatched = 0;

        foreach ($incidents as $incident) {
            if ($processed >= self::MAX_RECOVERIES_PER_RUN) {
                break;
            }

            Log::info('[SystemAutoRecover] Auto recovery attempt', [
                'incident_id' => $incident->id,
                'source_type' => $incident->source_type,
                'source_id' => $incident->source_id,
                'severity' => $incident->severity,
            ]);

            $result = $reliabilityEngine->attemptRecovery($incident);

            if ($result['resolved']) {
                $resolved++;
                $processed++;
                continue;
            }

            // Not resolved - increment repair attempts (canonical: repair_attempts, last_repair_attempt_at)
            $metadata = $incident->metadata ?? [];
            $repairAttempts = ($metadata['repair_attempts'] ?? $metadata['recovery_attempt_count'] ?? 0) + 1;
            $metadata = array_merge($metadata, [
                'repair_attempts' => $repairAttempts,
                'last_repair_attempt_at' => now()->toIso8601String(),
                'recovery_attempt_count' => $repairAttempts, // backward compat
                'last_recovery_attempt_at' => now()->toIso8601String(),
            ]);
            $incident->update(['metadata' => $metadata]);

            // SLA: create ticket per EscalationPolicy (critical→immediate, error→1 attempt, warning→3 attempts, age>15min escalates)
            $ticket = $reliabilityEngine->escalate($incident);
                if ($ticket) {
                    $ticketsCreated++;
                }
            }

            // Retry dispatched by repair strategies during attemptRecovery
            $incident->refresh();
            if (($incident->metadata['retried'] ?? false) && !($metadata['retried'] ?? false)) {
                $retriesDispatched++;
            }

            $processed++;
        }

        if ($processed > 0 || $ticketsCreated > 0 || $retriesDispatched > 0) {
            $this->info("Auto-recover: {$processed} processed, {$resolved} resolved, {$ticketsCreated} ticket(s) created, {$retriesDispatched} retry(ies) dispatched.");
        }

        return self::SUCCESS;
    }
}
