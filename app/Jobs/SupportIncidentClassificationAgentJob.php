<?php

namespace App\Jobs;

use App\Models\SystemIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stub: Support Incident Classification Agent.
 *
 * Dispatched when system_incident is created with requires_support = true.
 * For now: no heavy logic. Placeholder for future AI classification.
 */
class SupportIncidentClassificationAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $incidentId
    ) {}

    public function handle(): void
    {
        $incident = SystemIncident::find($this->incidentId);
        if (!$incident || $incident->resolved_at) {
            return;
        }
        // Stub: no classification logic yet
    }
}
