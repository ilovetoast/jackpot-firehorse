<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * TicketSLAEscalation Event
 *
 * Domain event emitted when SLA escalation conditions are met.
 * Used for internal operational alerts and future notification integration.
 *
 * Escalation Types:
 * - approaching_breach: SLA is approaching breach threshold (e.g., 80% of time elapsed)
 * - breached_first_response: First response SLA has been breached
 * - breached_resolution: Resolution SLA has been breached
 *
 * Future Phase 4: Notification listeners will subscribe to this event
 * to send emails, Slack messages, or other alerts to support staff.
 */
class TicketSLAEscalation
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Ticket $ticket The ticket with SLA escalation
     * @param string $escalationType Type of escalation (approaching_breach, breached_first_response, breached_resolution)
     */
    public function __construct(
        public Ticket $ticket,
        public string $escalationType
    ) {
    }
}
