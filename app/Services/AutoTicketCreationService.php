<?php

namespace App\Services;

use App\Models\AlertCandidate;
use App\Models\TicketCreationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 5A Step 2 — Auto Ticket Creation Service
 * 
 * Defines when alert candidates should automatically generate support tickets.
 * Phase 4 and Phase 5A Step 1 are LOCKED - this phase consumes alerts and tickets only.
 * 
 * AutoTicketCreationService
 * 
 * Evaluates alert candidates against ticket creation rules and automatically
 * creates support tickets when rules match.
 * 
 * Ensures idempotency: no duplicate tickets for the same alert.
 * 
 * NO UI — ticket creation is automatic and programmatic.
 * NO NOTIFICATIONS — tickets are created silently.
 */
class AutoTicketCreationService
{
    protected SupportTicketService $ticketService;

    public function __construct(SupportTicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    /**
     * Evaluate alert candidates and create tickets for those that match enabled rules.
     * 
     * @param Collection<AlertCandidate>|null $alertCandidates If null, evaluates all open alerts
     * @return Collection<\App\Models\SupportTicket> Created tickets
     */
    public function evaluateAndCreateTickets(?Collection $alertCandidates = null): Collection
    {
        // Get alerts to evaluate
        if ($alertCandidates === null) {
            $alertCandidates = AlertCandidate::open()->get();
        }

        Log::debug('[AutoTicketCreationService] Evaluating alert candidates for ticket creation', [
            'alert_count' => $alertCandidates->count(),
        ]);

        $createdTickets = collect();

        // Get enabled ticket creation rules
        $rules = TicketCreationRule::enabled()->with('rule')->get();

        if ($rules->isEmpty()) {
            Log::debug('[AutoTicketCreationService] No enabled ticket creation rules found');
            return $createdTickets;
        }

        foreach ($alertCandidates as $alertCandidate) {
            try {
                // Find matching ticket creation rule for this alert's detection rule
                $ticketRule = $rules->firstWhere('rule_id', $alertCandidate->rule_id);

                if (!$ticketRule) {
                    continue; // No rule configured for this detection rule
                }

                // Check if ticket should be created
                if (!$ticketRule->shouldCreateTicket($alertCandidate)) {
                    Log::debug('[AutoTicketCreationService] Alert does not meet ticket creation requirements', [
                        'alert_candidate_id' => $alertCandidate->id,
                        'rule_id' => $ticketRule->id,
                        'severity' => $alertCandidate->severity,
                        'detection_count' => $alertCandidate->detection_count,
                    ]);
                    continue;
                }

                // Check if ticket already exists (idempotency)
                if ($alertCandidate->supportTicket) {
                    Log::debug('[AutoTicketCreationService] Ticket already exists for alert', [
                        'alert_candidate_id' => $alertCandidate->id,
                        'ticket_id' => $alertCandidate->supportTicket->id,
                    ]);
                    continue;
                }

                // Create ticket
                $ticket = $this->ticketService->createTicketFromAlert($alertCandidate);

                $createdTickets->push($ticket);

                Log::info('[AutoTicketCreationService] Ticket automatically created from alert', [
                    'alert_candidate_id' => $alertCandidate->id,
                    'ticket_id' => $ticket->id,
                    'ticket_creation_rule_id' => $ticketRule->id,
                    'severity' => $alertCandidate->severity,
                    'detection_count' => $alertCandidate->detection_count,
                ]);

            } catch (\Throwable $e) {
                Log::error('[AutoTicketCreationService] Error evaluating alert for ticket creation', [
                    'alert_candidate_id' => $alertCandidate->id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue with other alerts
            }
        }

        Log::info('[AutoTicketCreationService] Ticket creation evaluation completed', [
            'evaluated_count' => $alertCandidates->count(),
            'created_count' => $createdTickets->count(),
        ]);

        return $createdTickets;
    }

    /**
     * Evaluate a single alert candidate and create ticket if rule matches.
     * 
     * @param AlertCandidate $alertCandidate
     * @return \App\Models\SupportTicket|null
     */
    public function evaluateAlert(AlertCandidate $alertCandidate): ?\App\Models\SupportTicket
    {
        $created = $this->evaluateAndCreateTickets(collect([$alertCandidate]));

        return $created->first();
    }

    /**
     * Check if an alert candidate should have a ticket created based on enabled rules.
     * 
     * @param AlertCandidate $alertCandidate
     * @return bool
     */
    public function shouldCreateTicket(AlertCandidate $alertCandidate): bool
    {
        $ticketRule = TicketCreationRule::enabled()
            ->where('rule_id', $alertCandidate->rule_id)
            ->first();

        if (!$ticketRule) {
            return false;
        }

        return $ticketRule->shouldCreateTicket($alertCandidate);
    }
}
