<?php

namespace App\Services;

use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Asset;
use App\Models\SystemIncident;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Jobs\ProcessAssetJob;
use App\Jobs\PromoteAssetJob;
use App\Services\Assets\AssetStateReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central recovery logic for system incidents.
 *
 * Used by SystemAutoRecoverCommand and admin incident action endpoints.
 * Must not mutate asset unless reconciliation confirms safe repair.
 * Must not create duplicate tickets (idempotent).
 *
 * Create Ticket: Creates a Ticket (admin support UI) not SupportTicket.
 * Admin support tickets route expects Ticket model.
 */
class SystemIncidentRecoveryService
{
    public function __construct(
        protected AssetStateReconciliationService $reconciliationService,
        protected SystemIncidentService $incidentService
    ) {}

    /**
     * Attempt repair: reconcile asset, check if incident is resolved.
     *
     * @return array{resolved: bool, changes: array}
     */
    public function attemptRepair(SystemIncident $incident): array
    {
        if ($incident->resolved_at) {
            return ['resolved' => false, 'changes' => []];
        }

        if ($incident->source_type === 'asset' && $incident->source_id) {
            return $this->attemptRepairAsset($incident);
        }

        if ($incident->source_type === 'job' && $incident->source_id) {
            return $this->attemptRepairJob($incident);
        }

        return ['resolved' => false, 'changes' => []];
    }

    /**
     * Create Ticket (admin support UI) for incident if none exists (idempotent).
     * Returns the created ticket, or the existing open ticket if one already exists.
     */
    public function createTicket(SystemIncident $incident): ?Ticket
    {
        if ($incident->resolved_at) {
            return null;
        }

        $sourceId = $incident->source_id;
        if (!$sourceId) {
            return null;
        }

        $assetId = $sourceId;
        $asset = Asset::find($assetId);

        // Check for existing open Ticket for this asset from operations center
        $existingTicket = Ticket::where('type', TicketType::INTERNAL)
            ->where('metadata->asset_id', $assetId)
            ->where('metadata->source', 'operations_incident')
            ->whereIn('status', [
                TicketStatus::OPEN,
                TicketStatus::IN_PROGRESS,
                TicketStatus::WAITING_ON_SUPPORT,
                TicketStatus::WAITING_ON_USER,
                TicketStatus::BLOCKED,
            ])
            ->first();

        if ($existingTicket) {
            return $existingTicket;
        }

        $subject = $asset ? "Asset processing: {$asset->title}" : $incident->title;
        $description = "Created from Operations Center incident.\n\n"
            . "Incident: {$incident->title}\n"
            . ($incident->message ? "Details: {$incident->message}\n" : '')
            . "\nAsset ID: {$assetId}\n"
            . "Analysis status: " . ($asset?->analysis_status ?? 'unknown') . "\n"
            . "Thumbnail status: " . ($asset?->thumbnail_status?->value ?? 'unknown');

        $severityValue = match (strtolower($incident->severity ?? 'warning')) {
            'critical' => TicketSeverity::P0,
            'error' => TicketSeverity::P1,
            default => TicketSeverity::P2,
        };

        $creator = User::where('email', 'system@internal')->first() ?? User::find(1);
        if (!$creator) {
            return null;
        }

        $tenantId = $asset?->tenant_id ?? $incident->tenant_id;
        if ($tenantId && !\App\Models\Tenant::where('id', $tenantId)->exists()) {
            $tenantId = null;
        }

        $hasSeverityColumn = \Illuminate\Support\Facades\Schema::hasColumn('tickets', 'severity');

        try {
            $ticket = DB::transaction(function () use ($incident, $asset, $assetId, $subject, $description, $severityValue, $creator, $tenantId, $hasSeverityColumn) {
                $createData = [
                    'type' => TicketType::INTERNAL,
                    'status' => TicketStatus::OPEN,
                    'tenant_id' => $tenantId,
                    'created_by_user_id' => $creator->id,
                    'assigned_team' => \App\Enums\TicketTeam::ENGINEERING,
                    'metadata' => [
                        'subject' => $subject,
                        'description' => $description,
                        'asset_id' => $assetId,
                        'incident_id' => $incident->id,
                        'incident_title' => $incident->title,
                        'source' => 'operations_incident',
                        'analysis_status' => $asset?->analysis_status ?? 'unknown',
                        'thumbnail_status' => $asset?->thumbnail_status?->value ?? null,
                        'severity' => $severityValue->value,
                    ],
                ];
                if ($hasSeverityColumn) {
                    $createData['severity'] = $severityValue;
                }
                $ticket = Ticket::create($createData);

                if ($asset?->brand_id) {
                    $ticket->brands()->attach([$asset->brand_id]);
                }

                TicketMessage::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $creator->id,
                    'body' => $description,
                    'is_internal' => false,
                ]);

                return $ticket;
            });

            Log::info('[SystemIncidentRecoveryService] Created Ticket for incident', [
                'incident_id' => $incident->id,
                'ticket_id' => $ticket->id,
            ]);

            return $ticket;
        } catch (\Throwable $e) {
            Log::error('[SystemIncidentRecoveryService] Failed to create ticket', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Mark incident resolved (manual).
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

        Log::info('[SystemIncidentRecoveryService] Incident resolved', [
            'incident_id' => $incident->id,
            'auto_resolved' => $autoResolved,
        ]);
    }

    /**
     * Dispatch retry for job/asset incident.
     */
    public function dispatchRetry(SystemIncident $incident): bool
    {
        if (!$incident->retryable || !$incident->source_id) {
            return false;
        }

        $asset = Asset::find($incident->source_id);
        if (!$asset) {
            return false;
        }

        if (($asset->analysis_status ?? '') === 'promotion_failed') {
            PromoteAssetJob::dispatch($asset->id);
        } else {
            ProcessAssetJob::dispatch($asset->id);
        }

        $metadata = $incident->metadata ?? [];
        $metadata['retried'] = true;
        $metadata['retried_at'] = now()->toIso8601String();
        $incident->update(['metadata' => $metadata]);

        return true;
    }

    /**
     * Check if incident is resolved after reconciliation.
     */
    public function isIncidentResolvedAfterReconcile(SystemIncident $incident): bool
    {
        if ($incident->source_type !== 'asset' && $incident->source_type !== 'job') {
            return false;
        }

        $asset = Asset::find($incident->source_id);
        if (!$asset) {
            return false;
        }

        // Re-check: asset in terminal good state?
        $status = $asset->analysis_status ?? 'uploading';
        if ($status === 'complete') {
            return true;
        }

        // Promotion failed with thumbnails = still needs attention
        if ($status === 'promotion_failed') {
            return false;
        }

        // Stuck states that incident was about
        $title = strtolower($incident->title ?? '');
        if (str_contains($title, 'uploading') && $status !== 'uploading') {
            return true;
        }
        if (str_contains($title, 'thumbnail') && $status === 'complete') {
            return true;
        }
        if (str_contains($title, 'promotion') && $status === 'complete') {
            return true;
        }

        return false;
    }

    /**
     * Should create ticket based on SLA rules?
     *
     * Severity rules:
     * - info: no auto ticket
     * - warning: only after 2 failures
     * - error: yes
     * - critical: immediate
     */
    public function shouldCreateTicketBySeverity(SystemIncident $incident, int $recoveryAttemptCount): bool
    {
        $severity = strtolower($incident->severity ?? 'info');

        if ($severity === 'info') {
            return false;
        }
        if ($severity === 'critical' || $severity === 'error') {
            return true;
        }
        if ($severity === 'warning') {
            return $recoveryAttemptCount >= 2;
        }

        return false;
    }

    protected function attemptRepairAsset(SystemIncident $incident): array
    {
        $asset = Asset::find($incident->source_id);
        if (!$asset) {
            return ['resolved' => false, 'changes' => []];
        }

        $result = $this->reconciliationService->reconcile($asset->fresh());
        $asset->refresh();

        if ($this->isIncidentResolvedAfterReconcile($incident)) {
            $this->resolve($incident, true);
            return ['resolved' => true, 'changes' => $result['changes'] ?? []];
        }

        return ['resolved' => false, 'changes' => $result['changes'] ?? []];
    }

    protected function attemptRepairJob(SystemIncident $incident): array
    {
        // Job incidents have source_id = asset id; treat as asset repair + optional retry
        $asset = Asset::find($incident->source_id);
        if (!$asset) {
            return ['resolved' => false, 'changes' => []];
        }

        $result = $this->reconciliationService->reconcile($asset->fresh());
        $asset->refresh();

        if ($this->isIncidentResolvedAfterReconcile($incident)) {
            $this->resolve($incident, true);
            return ['resolved' => true, 'changes' => $result['changes'] ?? []];
        }

        // If retryable, dispatch retry
        if ($incident->retryable) {
            $this->dispatchRetry($incident);
        }

        return ['resolved' => false, 'changes' => $result['changes'] ?? []];
    }
}
