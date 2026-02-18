<?php

namespace App\Services\Reliability;

use App\Models\Asset;
use App\Models\SystemIncident;
use App\Models\Ticket;
use App\Services\SystemIncidentService;
use App\Services\SystemIncidentRecoveryService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Unified Reliability Engine.
 *
 * Centralizes incident reporting, repair strategies, escalation, and metrics.
 * Every failure flows through one engine.
 */
class ReliabilityEngine
{
    public function __construct(
        protected SystemIncidentService $incidentService,
        protected EscalationPolicy $escalationPolicy,
        protected ReliabilityMetricsService $metricsService,
        /** @var RepairStrategyInterface[] */
        protected array $repairStrategies = [],
    ) {
        if (empty($this->repairStrategies)) {
            $this->repairStrategies = $this->defaultStrategies();
        }
    }

    /**
     * Report an incident (creates or deduplicates).
     *
     * @param array $data Keys: source_type, source_id?, tenant_id?, severity?, title, message?, metadata?, retryable?, requires_support?, unique_signature?, context?, asset?, asset_id?
     * @return SystemIncident|null
     */
    public function report(array $data): ?SystemIncident
    {
        $severity = $data['severity'] ?? null;
        if ($severity === null && isset($data['context'])) {
            $severity = $this->classifySeverity($data);
        }
        $data['severity'] = $severity ?? 'warning';

        $payload = array_diff_key($data, array_flip(['context', 'asset', 'asset_id']));
        if (!empty($data['unique_signature'])) {
            return $this->incidentService->recordIfNotExists($payload);
        }

        return $this->incidentService->record($payload);
    }

    /**
     * Attempt recovery using registered repair strategies.
     *
     * @return array{resolved: bool, changes: array}
     */
    public function attemptRecovery(SystemIncident $incident): array
    {
        if ($incident->resolved_at) {
            return ['resolved' => false, 'changes' => []];
        }

        $this->escalationPolicy->applyAgeEscalation($incident);
        $incident->refresh();

        foreach ($this->repairStrategies as $strategy) {
            if (!$strategy->supports($incident)) {
                continue;
            }

            $result = $strategy->attempt($incident);
            if ($result->resolved) {
                $this->resolve($incident, true);
                return ['resolved' => true, 'changes' => $result->changes];
            }
        }

        return ['resolved' => false, 'changes' => []];
    }

    /**
     * Mark incident as resolved.
     */
    public function resolve(SystemIncident $incident, bool $autoResolved = false): void
    {
        if ($incident->resolved_at) {
            return;
        }

        $metadata = $incident->metadata ?? [];
        if ($autoResolved) {
            $metadata['auto_recovered'] = true;
        }

        $incident->update([
            'resolved_at' => now(),
            'auto_resolved' => $autoResolved,
            'metadata' => $metadata,
        ]);

        $this->emitMetrics($incident);

        Log::info('[ReliabilityEngine] Incident resolved', [
            'incident_id' => $incident->id,
            'auto_resolved' => $autoResolved,
        ]);
    }

    /**
     * Escalate: create ticket if policy requires.
     */
    public function escalate(SystemIncident $incident): ?Ticket
    {
        if ($incident->resolved_at) {
            return null;
        }

        if (!$this->escalationPolicy->shouldCreateTicket($incident)) {
            return null;
        }

        return app(SystemIncidentRecoveryService::class)->createTicket($incident);
    }

    /**
     * Emit metrics for an incident (hook for future metric backends).
     */
    public function emitMetrics(SystemIncident $incident): void
    {
        // Metrics are computed on-demand via ReliabilityMetricsService.
        // This hook can be extended for real-time metric emission (e.g. StatsD, Datadog).
    }

    /**
     * Classify severity from context (unified logic from PopulateAutomaticMetadataJob, SystemAutoRecoverCommand).
     *
     * @param array $data May include: context, asset?, title, metadata
     * @return string 'critical'|'error'|'warning'|'info'
     */
    public function classifySeverity(array $data): string
    {
        $context = $data['context'] ?? null;
        $asset = $data['asset'] ?? null;
        if (!$asset && !empty($data['asset_id'])) {
            $asset = Asset::find($data['asset_id']);
        }

        if ($context === 'visual_metadata_missing' && $asset instanceof Asset) {
            return $this->classifyVisualMetadataSeverity($asset);
        }

        if ($context === 'stuck_incident' && isset($data['minutes_stuck'])) {
            return $data['minutes_stuck'] >= 15 ? 'critical' : ($data['severity'] ?? 'warning');
        }

        return $data['severity'] ?? 'warning';
    }

    /**
     * Visual metadata incident severity (from PopulateAutomaticMetadataJob).
     */
    public function classifyVisualMetadataSeverity(Asset $asset): string
    {
        $metadata = $asset->metadata ?? [];
        $thumbnailTimeout = (bool) ($metadata['thumbnail_timeout'] ?? false);
        $hasDimensions = !empty($asset->thumbnailDimensions('medium'));
        $retryCount = $asset->thumbnail_retry_count ?? 0;

        if ($thumbnailTimeout && !$hasDimensions) {
            return 'critical';
        }

        if ($retryCount >= 2) {
            return 'error';
        }

        return 'warning';
    }

    /**
     * Resolve by source (delegates to SystemIncidentService).
     */
    public function resolveBySource(string $sourceType, string $sourceId): int
    {
        return $this->incidentService->resolveBySource($sourceType, $sourceId);
    }

    protected function defaultStrategies(): array
    {
        return [
            app(VisualMetadataRepairStrategy::class),
            app(ThumbnailRetryStrategy::class),
            app(JobRetryStrategy::class),
        ];
    }
}
