<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Mail\SupportTicketCreated;
use App\Mail\SupportTicketCreatorNotification;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TicketNotificationService
{
    /**
     * Email the assigned staff member when a support or internal (engineering) ticket is created.
     * Uses {@see \App\Mail\SupportTicketCreated}; delivery respects {@see \App\Services\EmailGate} (automation / system email).
     */
    public function notifyAssignedStaffOfNewTicket(Ticket $ticket): void
    {
        if (! in_array($ticket->type, [TicketType::TENANT, TicketType::TENANT_INTERNAL, TicketType::INTERNAL], true)) {
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

    /**
     * Confirm to the tenant user that their support ticket was received.
     */
    public function notifyCreatorOfReceipt(Ticket $ticket): void
    {
        if ($ticket->type !== TicketType::TENANT) {
            return;
        }

        $ticket->loadMissing(['tenant:id,name', 'createdBy:id,first_name,last_name,email']);

        $creator = $ticket->createdBy;
        if (! $creator || ! $creator->email) {
            return;
        }

        try {
            Mail::to($creator->email)->send(new SupportTicketCreatorNotification($ticket, 'receipt'));
        } catch (\Throwable $e) {
            Log::warning('Failed to send support_ticket_creator_receipt email', [
                'ticket_id' => $ticket->id,
                'creator_id' => $creator->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the ticket creator when someone else posts a public reply.
     */
    public function notifyCreatorOfReplyFromOtherUser(Ticket $ticket, TicketMessage $message): void
    {
        if ($ticket->type !== TicketType::TENANT) {
            return;
        }

        if ($message->is_internal) {
            return;
        }

        if ((int) $message->user_id === (int) $ticket->created_by_user_id) {
            return;
        }

        $ticket->loadMissing(['tenant:id,name', 'createdBy:id,first_name,last_name,email']);
        $message->loadMissing('user:id,first_name,last_name');

        $creator = $ticket->createdBy;
        if (! $creator || ! $creator->email) {
            return;
        }

        try {
            Mail::to($creator->email)->send(new SupportTicketCreatorNotification($ticket, 'reply', $message));
        } catch (\Throwable $e) {
            Log::warning('Failed to send support_ticket_creator_reply email', [
                'ticket_id' => $ticket->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the ticket creator when staff (or another user) sets the ticket to resolved or closed.
     */
    public function notifyCreatorOfTerminalStatus(Ticket $ticket, TicketStatus $status): void
    {
        if ($ticket->type !== TicketType::TENANT) {
            return;
        }

        if (! in_array($status, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true)) {
            return;
        }

        $ticket->loadMissing(['tenant:id,name', 'createdBy:id,first_name,last_name,email']);

        $creator = $ticket->createdBy;
        if (! $creator || ! $creator->email) {
            return;
        }

        $label = match ($status) {
            TicketStatus::RESOLVED => 'Resolved',
            TicketStatus::CLOSED => 'Closed',
            default => $status->value,
        };

        try {
            Mail::to($creator->email)->send(
                new SupportTicketCreatorNotification($ticket, 'terminal', null, $label)
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send support_ticket_creator_resolved email', [
                'ticket_id' => $ticket->id,
                'status' => $status->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
