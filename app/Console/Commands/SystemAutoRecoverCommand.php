<?php

namespace App\Console\Commands;

use App\Models\SystemIncident;
use App\Services\SystemIncidentRecoveryService;
use Illuminate\Console\Command;

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

    public function handle(SystemIncidentRecoveryService $recoveryService): int
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

            $result = $recoveryService->attemptRepair($incident);

            if ($result['resolved']) {
                $resolved++;
                $processed++;
                continue;
            }

            // Not resolved - apply SLA rules
            $metadata = $incident->metadata ?? [];
            $recoveryAttemptCount = ($metadata['recovery_attempt_count'] ?? 0) + 1;
            $incident->update([
                'metadata' => array_merge($metadata, [
                    'recovery_attempt_count' => $recoveryAttemptCount,
                    'last_recovery_attempt_at' => now()->toIso8601String(),
                ]),
            ]);

            // SLA: should we create ticket?
            $shouldCreateTicket = $recoveryService->shouldCreateTicketBySeverity($incident, $recoveryAttemptCount);
            if ($shouldCreateTicket) {
                $ticket = $recoveryService->createTicket($incident);
                if ($ticket) {
                    $ticketsCreated++;
                }
            }

            // Retry if retryable (for job) and not already retried this run
            if ($incident->source_type === 'job' && $incident->retryable && !($metadata['retried'] ?? false)) {
                if ($recoveryService->dispatchRetry($incident)) {
                    $retriesDispatched++;
                }
            }

            // For asset: retry if retryable
            if ($incident->source_type === 'asset' && $incident->retryable && !($metadata['retried'] ?? false)) {
                if ($recoveryService->dispatchRetry($incident)) {
                    $retriesDispatched++;
                }
            }

            $processed++;
        }

        if ($processed > 0 || $ticketsCreated > 0 || $retriesDispatched > 0) {
            $this->info("Auto-recover: {$processed} processed, {$resolved} resolved, {$ticketsCreated} ticket(s) created, {$retriesDispatched} retry(ies) dispatched.");
        }

        return self::SUCCESS;
    }
}
