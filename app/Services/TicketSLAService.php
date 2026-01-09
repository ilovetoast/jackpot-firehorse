<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Events\TicketSLAEscalation;
use App\Models\SLAPlan;
use App\Models\Ticket;
use App\Models\TicketSLAState;
use Illuminate\Support\Facades\Log;

/**
 * TicketSLAService
 *
 * Main service for SLA operations on tickets.
 *
 * IMPORTANT: SLA targets are internal operational goals, NOT customer-facing guarantees.
 * These are used for internal team performance tracking and escalation triggers.
 * Do NOT expose SLA internals (deadlines, breach status) to tenants.
 *
 * Pause/Resume Logic:
 * - SLA timers pause automatically when ticket status is waiting_on_user or blocked
 * - When paused, we track paused_at timestamp and accumulate total_paused_minutes
 * - When resumed, deadlines are adjusted by the paused duration
 * - This ensures SLA targets only count active work time
 *
 * Future Phase 4: Notifications will be added based on escalation events emitted here.
 * Listeners can subscribe to TicketSLAEscalation events to send emails, Slack messages, etc.
 */
class TicketSLAService
{
    public function __construct(
        protected PlanService $planService,
        protected BusinessHoursCalculator $businessHoursCalculator
    ) {
    }

    /**
     * Assign SLA plan to ticket on creation.
     * Resolves tenant's subscription plan and assigns corresponding SLA plan.
     *
     * @param Ticket $ticket
     * @return TicketSLAState
     */
    public function assignSLAToTicket(Ticket $ticket): TicketSLAState
    {
        // Get tenant's subscription plan
        $planName = 'free';
        if ($ticket->tenant_id) {
            $planName = $this->planService->getCurrentPlan($ticket->tenant);
        }

        // Get SLA plan (config + database override)
        $slaPlan = $this->getSLAPlan($planName);

        // Get tenant timezone (default to UTC if no tenant)
        $tenantTimezone = $ticket->tenant?->timezone ?? 'UTC';

        // Calculate deadlines
        $deadlines = $this->calculateDeadlines($ticket, $slaPlan, $tenantTimezone);

        // Create SLA state record
        $slaState = TicketSLAState::create([
            'ticket_id' => $ticket->id,
            'sla_plan_id' => $planName,
            'first_response_target_minutes' => $slaPlan['first_response_target_minutes'],
            'resolution_target_minutes' => $slaPlan['resolution_target_minutes'],
            'first_response_deadline' => $deadlines['first_response_deadline'],
            'resolution_deadline' => $deadlines['resolution_deadline'],
        ]);

        // Update ticket with SLA plan ID
        $ticket->update(['sla_plan_id' => $planName]);

        return $slaState;
    }

    /**
     * Calculate SLA deadlines based on plan and support hours.
     * Respects tenant timezone and only counts business hours.
     *
     * @param Ticket $ticket
     * @param array $slaPlan SLA plan configuration
     * @param string $tenantTimezone Tenant timezone identifier
     * @return array{first_response_deadline: \DateTime|null, resolution_deadline: \DateTime|null}
     */
    public function calculateDeadlines(Ticket $ticket, array $slaPlan, string $tenantTimezone): array
    {
        $startTime = $ticket->created_at->toDateTime();
        $supportHours = $slaPlan['support_hours'] ?? [
            'days' => [1, 2, 3, 4, 5],
            'start' => '09:00',
            'end' => '17:00',
            'timezone' => 'UTC',
        ];

        // Use timezone from support_hours if specified, otherwise use tenant timezone
        $timezone = $supportHours['timezone'] ?? $tenantTimezone;

        // Calculate first response deadline
        $firstResponseDeadline = null;
        if (isset($slaPlan['first_response_target_minutes'])) {
            $firstResponseDeadline = $this->businessHoursCalculator->addBusinessMinutes(
                $startTime,
                $slaPlan['first_response_target_minutes'],
                $supportHours,
                $timezone
            );
        }

        // Calculate resolution deadline
        $resolutionDeadline = null;
        if (isset($slaPlan['resolution_target_minutes'])) {
            $resolutionDeadline = $this->businessHoursCalculator->addBusinessMinutes(
                $startTime,
                $slaPlan['resolution_target_minutes'],
                $supportHours,
                $timezone
            );
        }

        return [
            'first_response_deadline' => $firstResponseDeadline,
            'resolution_deadline' => $resolutionDeadline,
        ];
    }

    /**
     * Pause SLA timer.
     * Only pauses if status is waiting_on_user or blocked.
     *
     * @param Ticket $ticket
     * @return void
     */
    public function pauseSLA(Ticket $ticket): void
    {
        $slaState = $ticket->slaState;
        if (!$slaState) {
            return;
        }

        // Only pause for specific statuses
        if (in_array($ticket->status, [TicketStatus::WAITING_ON_USER, TicketStatus::BLOCKED])) {
            $slaState->pause();
        }
    }

    /**
     * Resume SLA timer.
     * Only resumes if previously paused and status is now active.
     *
     * @param Ticket $ticket
     * @return void
     */
    public function resumeSLA(Ticket $ticket): void
    {
        $slaState = $ticket->slaState;
        if (!$slaState || $slaState->paused_at === null) {
            return;
        }

        // Resume if status is no longer waiting_on_user or blocked
        if (!in_array($ticket->status, [TicketStatus::WAITING_ON_USER, TicketStatus::BLOCKED])) {
            $slaState->resume();
        }
    }

    /**
     * Check for SLA breaches.
     * Updates breach flags and returns breach status.
     *
     * @param Ticket $ticket
     * @return array{breached_first_response: bool, breached_resolution: bool}
     */
    public function checkBreaches(Ticket $ticket): array
    {
        $slaState = $ticket->slaState;
        if (!$slaState) {
            return [
                'breached_first_response' => false,
                'breached_resolution' => false,
            ];
        }

        $breaches = $slaState->checkBreaches();

        // Check for escalation conditions (approaching breach, etc.)
        $this->checkEscalations($ticket, $slaState, $breaches);

        return $breaches;
    }

    /**
     * Update first response time.
     * Called when first message is sent on the ticket.
     *
     * @param Ticket $ticket
     * @return void
     */
    public function updateResponseTime(Ticket $ticket): void
    {
        $slaState = $ticket->slaState;
        if (!$slaState) {
            return;
        }

        $slaState->updateResponseTime();

        // Update ticket's first_response_at if not set
        if ($ticket->first_response_at === null) {
            $ticket->update(['first_response_at' => now()]);
        }
    }

    /**
     * Update resolution time.
     * Called when ticket status changes to resolved.
     *
     * @param Ticket $ticket
     * @return void
     */
    public function updateResolutionTime(Ticket $ticket): void
    {
        $slaState = $ticket->slaState;
        if (!$slaState) {
            return;
        }

        $slaState->updateResolutionTime();

        // Update ticket's resolved_at if not set
        if ($ticket->resolved_at === null) {
            $ticket->update(['resolved_at' => now()]);
        }
    }

    /**
     * Get SLA plan configuration (config + database override).
     *
     * @param string $planName Subscription plan name
     * @return array SLA plan configuration
     */
    protected function getSLAPlan(string $planName): array
    {
        // Get default from config
        $configPlan = config("sla_plans.{$planName}", config('sla_plans.free'));

        // Check for database override
        $dbPlan = SLAPlan::getForPlan($planName);
        if ($dbPlan) {
            return $dbPlan->mergeWithConfig($configPlan);
        }

        return $configPlan;
    }

    /**
     * Check for escalation conditions and emit events.
     * This is where escalation hooks are implemented.
     * Future Phase 4: Notification listeners will subscribe to these events.
     *
     * @param Ticket $ticket
     * @param TicketSLAState $slaState
     * @param array $breaches Current breach status
     * @return void
     */
    protected function checkEscalations(Ticket $ticket, TicketSLAState $slaState, array $breaches): void
    {
        $now = now();

        // Check for approaching breach (80% of time elapsed)
        if ($slaState->first_response_deadline && $slaState->first_response_at === null) {
            $elapsed = $now->diffInMinutes($ticket->created_at);
            $target = $slaState->first_response_target_minutes;
            $threshold = $target * 0.8;

            if ($elapsed >= $threshold && $elapsed < $target) {
                event(new TicketSLAEscalation($ticket, 'approaching_breach'));
                Log::info('SLA approaching breach', [
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'type' => 'first_response',
                    'elapsed_minutes' => $elapsed,
                    'target_minutes' => $target,
                ]);
            }
        }

        // Check for first response breach
        if ($breaches['breached_first_response'] && !$slaState->breached_first_response) {
            event(new TicketSLAEscalation($ticket, 'breached_first_response'));
            Log::warning('SLA breached: first response', [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
            ]);
        }

        // Check for resolution breach
        if ($breaches['breached_resolution'] && !$slaState->breached_resolution) {
            event(new TicketSLAEscalation($ticket, 'breached_resolution'));
            Log::warning('SLA breached: resolution', [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
            ]);
        }
    }
}
