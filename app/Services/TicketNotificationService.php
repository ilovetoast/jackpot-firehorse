<?php

namespace App\Services;

use App\Enums\TicketType;
use App\Mail\SupportTicketCreated;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TicketNotificationService
{
    /**
     * Email the assigned staff member when a tenant-facing support ticket is created.
     */
    public function notifyAssignedStaffOfNewTicket(Ticket $ticket): void
    {
        if (! in_array($ticket->type, [TicketType::TENANT, TicketType::TENANT_INTERNAL], true)) {
            return;
        }

        if (! $ticket->assigned_to_user_id) {
            return;
        }

        $ticket->loadMissing([
            'tenant:id,name',
            'createdBy:id,first_name,last_name,email',
            'assignedTo:id,first_name,last_name,email',
        ]);

        $assignee = $ticket->assignedTo;
        if (! $assignee || ! $assignee->email) {
            return;
        }

        try {
            Mail::to($assignee->email)->send(new SupportTicketCreated($ticket));
        } catch (\Throwable $e) {
            Log::warning('Failed to send support_ticket_created email', [
                'ticket_id' => $ticket->id,
                'assignee_id' => $assignee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
