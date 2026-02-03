<?php

namespace App\Services;

use App\Models\Download;
use App\Models\User;
use App\Services\TicketCreationService;
use Illuminate\Support\Facades\Log;

/**
 * Creates support tickets when download ZIP build failures escalate.
 *
 * Rules:
 * - Create ticket when failure_count >= 3 OR agent severity === "system"
 * - Only one ticket per download (tracked via escalation_ticket_id)
 * - Engineering ticket for timeout/infra, support for permissions/data
 */
class DownloadZipFailureEscalationService
{
    public function __construct(
        protected TicketCreationService $ticketService
    ) {}

    public function createTicketIfNeeded(Download $download, ?string $agentSummary): ?object
    {
        $download->refresh();

        if ($download->escalation_ticket_id) {
            return null;
        }

        $shouldCreate = $download->failure_count >= 3
            || $agentSummary !== null;

        if (! $shouldCreate) {
            return null;
        }

        $ticketType = $this->getTicketType($download);
        $subject = "Download ZIP build failed: {$download->id}";
        $description = $this->buildDescription($download, $agentSummary);

        $creator = User::where('email', 'system@internal')->first() ?? User::find(1);

        try {
            $ticket = $this->ticketService->createInternalEngineeringTicket([
                'subject' => $subject,
                'description' => $description,
                'tenant_id' => $download->tenant_id,
                'severity' => 'high',
                'metadata' => [
                    'download_id' => $download->id,
                    'failure_reason' => $download->failure_reason?->value,
                    'failure_count' => $download->failure_count,
                    'asset_count' => $download->assets()->count(),
                    'agent_summary' => $agentSummary,
                ],
            ], $creator);

            $download->update(['escalation_ticket_id' => $ticket->id]);

            Log::info('[DownloadZipFailureEscalationService] Created ticket', [
                'download_id' => $download->id,
                'ticket_id' => $ticket->id,
            ]);

            return $ticket;
        } catch (\Throwable $e) {
            Log::error('[DownloadZipFailureEscalationService] Failed to create ticket', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function getTicketType(Download $download): string
    {
        $reason = $download->failure_reason?->value ?? 'unknown';
        if (in_array($reason, ['timeout', 'disk_full', 's3_read_error'])) {
            return 'engineering';
        }
        return 'support';
    }

    protected function buildDescription(Download $download, ?string $agentSummary): string
    {
        $lines = [
            "Download ID: {$download->id}",
            "Tenant ID: {$download->tenant_id}",
            "ZIP size (bytes): " . ($download->download_options['estimated_bytes'] ?? 'unknown'),
            "Failure reason: " . ($download->failure_reason?->value ?? 'unknown'),
            "Failure count: {$download->failure_count}",
            "Asset count: " . $download->assets()->count(),
        ];
        if ($agentSummary) {
            $lines[] = "Agent summary: " . substr($agentSummary, 0, 2000);
        }
        $trace = $download->download_options['zip_failure_trace'] ?? '';
        if ($trace) {
            $lines[] = "Job trace: " . substr($trace, 0, 3000);
        }
        return implode("\n", $lines);
    }
}
