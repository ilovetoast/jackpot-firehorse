<?php

namespace App\Services;

use App\Models\AlertCandidate;
use App\Models\AlertSummary;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 5A Step 1 — Support Ticket Service
 * 
 * Manages creation and management of support tickets.
 * Phase 4 is LOCKED - this service consumes alerts only, does not modify them.
 * 
 * SupportTicketService
 * 
 * Purpose:
 * - Create tickets from alert candidates
 * - Create manual tickets
 * - Manage ticket lifecycle
 * - Phase 5A Step 3: Integrate with external ticket adapters
 */
class SupportTicketService
{
    public function __construct(
        protected ExternalTicketService $externalTicketService
    ) {
    }

    /**
     * Create a support ticket from an alert candidate.
     * 
     * Phase 5A Step 3: If external driver is configured, calls adapter to create external ticket.
     * 
     * @param AlertCandidate $alertCandidate
     * @param bool $forceCreate Force creation even if ticket already exists
     * @return SupportTicket
     */
    public function createTicketFromAlert(AlertCandidate $alertCandidate, bool $forceCreate = false): SupportTicket
    {
        // Idempotency check: return existing ticket if one exists
        if (!$forceCreate && $alertCandidate->supportTicket) {
            Log::debug('[SupportTicketService] Ticket already exists for alert, returning existing', [
                'alert_candidate_id' => $alertCandidate->id,
                'ticket_id' => $alertCandidate->supportTicket->id,
            ]);
            return $alertCandidate->supportTicket;
        }

        // Load alert summary if available for richer ticket content
        $alertSummary = $alertCandidate->summary;

        // Build ticket summary
        $summary = $alertSummary?->summary_text
            ?? sprintf('%s: %d events detected', $alertCandidate->rule->name, $alertCandidate->observed_count);

        // Build ticket description
        $description = $this->buildTicketDescription($alertCandidate, $alertSummary);

        // Create the ticket
        $ticket = SupportTicket::create([
            'alert_candidate_id' => $alertCandidate->id,
            'summary' => $summary,
            'description' => $description,
            'severity' => $alertCandidate->severity,
            'status' => 'open',
            'source' => 'system',
            'external_reference' => null, // Will be set by adapter if configured
        ]);

        Log::info('[SupportTicketService] Created ticket from alert', [
            'ticket_id' => $ticket->id,
            'alert_candidate_id' => $alertCandidate->id,
            'severity' => $ticket->severity,
        ]);

        // Phase 5A Step 3: Integrate with external ticket adapter
        // If driver is not 'null', create ticket in external system
        $driver = config('tickets.driver', 'null');
        if ($driver !== 'null') {
            try {
                $externalResult = $this->externalTicketService->createTicket($ticket);
                
                // Store external reference
                $ticket->update([
                    'external_reference' => $externalResult->externalReference,
                ]);

                Log::info('[SupportTicketService] Created external ticket via adapter', [
                    'ticket_id' => $ticket->id,
                    'driver' => $driver,
                    'external_reference' => $externalResult->externalReference,
                ]);
            } catch (\Throwable $e) {
                // Log error but don't fail ticket creation
                Log::error('[SupportTicketService] Failed to create external ticket, continuing with internal ticket', [
                    'ticket_id' => $ticket->id,
                    'driver' => $driver,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ticket->fresh();
    }

    /**
     * Create a manual support ticket (not linked to an alert).
     * 
     * @param string $summary
     * @param string|null $description
     * @param string $severity
     * @return SupportTicket
     */
    public function createManualTicket(string $summary, ?string $description, string $severity = 'warning'): SupportTicket
    {
        $ticket = SupportTicket::create([
            'alert_candidate_id' => null,
            'summary' => $summary,
            'description' => $description,
            'severity' => $severity,
            'status' => 'open',
            'source' => 'manual',
            'external_reference' => null,
        ]);

        Log::info('[SupportTicketService] Created manual ticket', [
            'ticket_id' => $ticket->id,
            'severity' => $severity,
        ]);

        // Phase 5A Step 3: Integrate with external ticket adapter
        $driver = config('tickets.driver', 'null');
        if ($driver !== 'null') {
            try {
                $externalResult = $this->externalTicketService->createTicket($ticket);
                
                $ticket->update([
                    'external_reference' => $externalResult->externalReference,
                ]);

                Log::info('[SupportTicketService] Created external ticket for manual ticket', [
                    'ticket_id' => $ticket->id,
                    'driver' => $driver,
                    'external_reference' => $externalResult->externalReference,
                ]);
            } catch (\Throwable $e) {
                Log::error('[SupportTicketService] Failed to create external ticket for manual ticket', [
                    'ticket_id' => $ticket->id,
                    'driver' => $driver,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ticket->fresh();
    }

    /**
     * Update ticket status.
     * 
     * @param SupportTicket $ticket
     * @param string $status
     * @return SupportTicket
     */
    public function updateStatus(SupportTicket $ticket, string $status): SupportTicket
    {
        $oldStatus = $ticket->status;
        
        $ticket->update(['status' => $status]);

        Log::info('[SupportTicketService] Updated ticket status', [
            'ticket_id' => $ticket->id,
            'old_status' => $oldStatus,
            'new_status' => $status,
        ]);

        // Phase 5A Step 3: Update status in external system if configured
        $driver = config('tickets.driver', 'null');
        if ($driver !== 'null' && $ticket->external_reference) {
            try {
                $this->externalTicketService->updateTicketStatus($ticket);
            } catch (\Throwable $e) {
                Log::error('[SupportTicketService] Failed to update external ticket status', [
                    'ticket_id' => $ticket->id,
                    'external_reference' => $ticket->external_reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ticket->fresh();
    }

    /**
     * Set external reference for a ticket.
     * 
     * @param SupportTicket $ticket
     * @param string $externalReference
     * @return SupportTicket
     */
    public function setExternalReference(SupportTicket $ticket, string $externalReference): SupportTicket
    {
        $ticket->update(['external_reference' => $externalReference]);

        Log::info('[SupportTicketService] Set external reference', [
            'ticket_id' => $ticket->id,
            'external_reference' => $externalReference,
        ]);

        return $ticket->fresh();
    }

    /**
     * Build ticket description from alert candidate and summary.
     * 
     * @param AlertCandidate $alertCandidate
     * @param AlertSummary|null $alertSummary
     * @return string
     */
    protected function buildTicketDescription(AlertCandidate $alertCandidate, ?AlertSummary $alertSummary): string
    {
        $parts = [];

        // Impact summary from AI summary if available
        if ($alertSummary?->impact_summary) {
            $parts[] = "Impact: " . $alertSummary->impact_summary;
        }

        // Alert details
        $parts[] = "\nAlert Details:";
        $parts[] = "- Rule: " . $alertCandidate->rule->name;
        $parts[] = "- Event Type: " . $alertCandidate->rule->event_type;
        $parts[] = "- Scope: " . $alertCandidate->scope;
        $parts[] = "- Observed Count: " . $alertCandidate->observed_count . " / " . $alertCandidate->threshold_count;
        $parts[] = "- Window: " . $alertCandidate->window_minutes . " minutes";
        $parts[] = "- First Detected: " . $alertCandidate->first_detected_at->toDateTimeString();
        $parts[] = "- Last Detected: " . $alertCandidate->last_detected_at->toDateTimeString();
        $parts[] = "- Detection Count: " . $alertCandidate->detection_count;

        // Context metadata if available
        if ($alertCandidate->context && !empty($alertCandidate->context)) {
            $parts[] = "\nContext:";
            $parts[] = json_encode($alertCandidate->context, JSON_PRETTY_PRINT);
        }

        // Suggested actions from AI summary if available
        if ($alertSummary?->suggested_actions && !empty($alertSummary->suggested_actions)) {
            $parts[] = "\nSuggested Actions:";
            if (is_array($alertSummary->suggested_actions)) {
                foreach ($alertSummary->suggested_actions as $action) {
                    $parts[] = "- " . $action;
                }
            } else {
                $parts[] = $alertSummary->suggested_actions;
            }
        }

        return implode("\n", $parts);
    }
}
