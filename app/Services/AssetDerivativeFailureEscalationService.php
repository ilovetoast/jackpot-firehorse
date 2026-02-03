<?php

namespace App\Services;

use App\Models\AssetDerivativeFailure;
use App\Models\User;
use App\Services\TicketCreationService;
use Illuminate\Support\Facades\Log;

/**
 * Phase T-1: Creates support tickets when derivative failures escalate.
 *
 * Rules:
 * - Create ticket when failure_count >= 3 OR agent severity === "system"
 * - Only one ticket per derivative failure record (tracked via escalation_ticket_id)
 * - ffmpeg / infra → engineering
 * - corrupt file / codec → support
 */
class AssetDerivativeFailureEscalationService
{
    public function __construct(
        protected TicketCreationService $ticketService
    ) {}

    public function createTicketIfNeeded(AssetDerivativeFailure $record, ?string $agentSummary): ?object
    {
        $record->refresh();

        if ($record->escalation_ticket_id) {
            return null;
        }

        $shouldCreate = $record->failure_count >= 3 || $agentSummary !== null;

        if (! $shouldCreate) {
            return null;
        }

        $subject = "Derivative failure: {$record->derivative_type} for asset {$record->asset_id}";
        $description = $this->buildDescription($record, $agentSummary);

        $creator = User::where('email', 'system@internal')->first() ?? User::find(1);

        try {
            $ticket = $this->ticketService->createInternalEngineeringTicket([
                'subject' => $subject,
                'description' => $description,
                'tenant_id' => $record->asset?->tenant_id,
                'severity' => 'high',
                'metadata' => [
                    'asset_derivative_failure_id' => $record->id,
                    'asset_id' => $record->asset_id,
                    'derivative_type' => $record->derivative_type,
                    'processor' => $record->processor,
                    'failure_reason' => $record->failure_reason,
                    'failure_count' => $record->failure_count,
                    'agent_summary' => $agentSummary,
                ],
            ], $creator);

            $record->update(['escalation_ticket_id' => $ticket->id]);

            Log::info('[AssetDerivativeFailureEscalationService] Created ticket', [
                'derivative_failure_id' => $record->id,
                'ticket_id' => $ticket->id,
            ]);

            return $ticket;
        } catch (\Throwable $e) {
            Log::error('[AssetDerivativeFailureEscalationService] Failed to create ticket', [
                'derivative_failure_id' => $record->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function buildDescription(AssetDerivativeFailure $record, ?string $agentSummary): string
    {
        $lines = [
            "Asset ID: {$record->asset_id}",
            "Derivative type: {$record->derivative_type}",
            "Processor: {$record->processor}",
            "Failure reason: " . ($record->failure_reason ?? 'unknown'),
            "Failure count: {$record->failure_count}",
            "Codec: " . ($record->metadata['codec'] ?? 'unknown'),
            "MIME: " . ($record->metadata['mime'] ?? 'unknown'),
        ];
        if ($agentSummary) {
            $lines[] = "Agent summary: " . substr($agentSummary, 0, 2000);
        }
        $trace = $record->metadata['exception_trace'] ?? '';
        if ($trace) {
            $lines[] = "Trace: " . substr($trace, 0, 3000);
        }

        return implode("\n", $lines);
    }
}
