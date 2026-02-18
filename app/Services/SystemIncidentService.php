<?php

namespace App\Services;

use App\Models\SystemIncident;
use App\Jobs\SupportIncidentClassificationAgentJob;
use Illuminate\Support\Str;

/**
 * Unified operational incident recording.
 *
 * DB is source of truth. No logging-only logic.
 */
class SystemIncidentService
{
    /**
     * Record an incident.
     *
     * @param array $payload Keys: source_type, source_id?, tenant_id?, severity, title, message?, metadata?, retryable?, requires_support?, unique_signature?
     * @return SystemIncident
     */
    public function record(array $payload): SystemIncident
    {
        $incident = SystemIncident::create([
            'id' => (string) Str::uuid(),
            'source_type' => $payload['source_type'],
            'source_id' => $payload['source_id'] ?? null,
            'tenant_id' => $payload['tenant_id'] ?? null,
            'severity' => $payload['severity'],
            'title' => $payload['title'],
            'message' => $payload['message'] ?? null,
            'metadata' => $payload['metadata'] ?? [],
            'retryable' => $payload['retryable'] ?? false,
            'requires_support' => $payload['requires_support'] ?? false,
            'auto_resolved' => false,
            'resolved_at' => null,
            'detected_at' => $payload['detected_at'] ?? now(),
        ]);

        if ($incident->requires_support) {
            SupportIncidentClassificationAgentJob::dispatch($incident->id);
        }

        return $incident;
    }

    /**
     * Resolve all unresolved incidents for a source.
     */
    public function resolveBySource(string $sourceType, string $sourceId): int
    {
        return SystemIncident::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'auto_resolved' => true,
            ]);
    }

    /**
     * Record incident only if one with the same unique_signature does not already exist (unresolved).
     *
     * @param array $payload Must include unique_signature (string)
     * @return SystemIncident|null The created incident, or null if duplicate
     */
    public function recordIfNotExists(array $payload): ?SystemIncident
    {
        $signature = $payload['unique_signature'] ?? null;
        if (!$signature) {
            return $this->record($payload);
        }

        $exists = SystemIncident::whereNull('resolved_at')
            ->where('metadata->unique_signature', $signature)
            ->exists();

        if ($exists) {
            return null;
        }

        $metadata = array_merge($payload['metadata'] ?? [], ['unique_signature' => $signature]);
        $payload['metadata'] = $metadata;
        unset($payload['unique_signature']);

        return $this->record($payload);
    }
}
