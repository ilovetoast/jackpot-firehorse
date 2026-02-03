<?php

namespace App\Services;

use App\Enums\UploadFailureReason;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\TicketCreationService;
use Illuminate\Support\Facades\Log;

/**
 * Phase U-1: Creates support tickets when upload failures escalate.
 *
 * Rules:
 * - Create ticket when failure_count >= 3 OR agent severity === "system"
 * - Only one ticket per upload (tracked via escalation_ticket_id)
 * - Engineering ticket for system/infra, support for data/permission
 */
class UploadFailureEscalationService
{
    public function __construct(
        protected TicketCreationService $ticketService
    ) {}

    public function createTicketIfNeeded(UploadSession $upload, ?string $agentSummary): ?object
    {
        $upload->refresh();

        if ($upload->escalation_ticket_id) {
            return null;
        }

        $shouldCreate = $upload->failure_count >= 3 || $agentSummary !== null;

        if (! $shouldCreate) {
            return null;
        }

        $subject = "Upload failed: {$upload->id}";
        $description = $this->buildDescription($upload, $agentSummary);

        $creator = User::where('email', 'system@internal')->first() ?? User::find(1);

        try {
            $ticket = $this->ticketService->createInternalEngineeringTicket([
                'subject' => $subject,
                'description' => $description,
                'tenant_id' => $upload->tenant_id,
                'severity' => 'high',
                'metadata' => [
                    'upload_id' => $upload->id,
                    'failure_reason' => $this->getFailureReasonValue($upload->failure_reason),
                    'failure_count' => $upload->failure_count,
                    'agent_summary' => $agentSummary,
                ],
            ], $creator);

            $upload->update(['escalation_ticket_id' => $ticket->id]);

            Log::info('[UploadFailureEscalationService] Created ticket', [
                'upload_id' => $upload->id,
                'ticket_id' => $ticket->id,
            ]);

            return $ticket;
        } catch (\Throwable $e) {
            Log::error('[UploadFailureEscalationService] Failed to create ticket', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function getFailureReasonValue($reason): string
    {
        return $reason instanceof UploadFailureReason ? $reason->value : (string) $reason;
    }

    protected function buildDescription(UploadSession $upload, ?string $agentSummary): string
    {
        $lines = [
            "Upload ID: {$upload->id}",
            "Tenant ID: {$upload->tenant_id}",
            "Bytes uploaded: {$upload->uploaded_size}",
            "Expected size: {$upload->expected_size}",
            "Failure reason: " . $this->getFailureReasonValue($upload->failure_reason),
            "Failure count: {$upload->failure_count}",
            "Stage: " . ($upload->upload_options['upload_failure_stage'] ?? 'unknown'),
        ];
        if ($agentSummary) {
            $lines[] = "Agent summary: " . substr($agentSummary, 0, 2000);
        }
        $trace = $upload->upload_options['upload_failure_trace'] ?? '';
        if ($trace) {
            $lines[] = "Trace: " . substr($trace, 0, 3000);
        }

        return implode("\n", $lines);
    }
}
