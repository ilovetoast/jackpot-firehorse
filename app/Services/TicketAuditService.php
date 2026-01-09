<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\ActivityEvent;
use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * TicketAuditService
 *
 * Retrieves and formats audit logs for tickets.
 * Provides a formatted view of all staff actions on a ticket.
 *
 * Audit Events Tracked:
 * - Assignment changes (TICKET_ASSIGNED)
 * - Status changes (TICKET_STATUS_CHANGED)
 * - Ticket conversion (TICKET_CONVERTED)
 * - Internal note creation (TICKET_INTERNAL_NOTE_ADDED)
 * - Ticket linking (TICKET_LINKED)
 * - Standard model events (TICKET_CREATED, TICKET_UPDATED, etc.)
 */
class TicketAuditService
{
    /**
     * Get formatted audit log for a ticket.
     *
     * @param Ticket $ticket
     * @return Collection Collection of formatted audit entries
     */
    public function getAuditLog(Ticket $ticket): Collection
    {
        // Get all activity events related to this ticket
        $events = ActivityEvent::where('subject_type', Ticket::class)
            ->where('subject_id', $ticket->id)
            ->orWhere(function ($query) use ($ticket) {
                // Also include events where ticket is mentioned in metadata (e.g., conversion)
                $query->where('event_type', EventType::TICKET_CONVERTED)
                    ->whereJsonContains('metadata->original_ticket_id', $ticket->id);
            })
            ->with(['actor', 'subject'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $events->map(function ($event) {
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => $event->actor->name,
                    'email' => $event->actor->email,
                ] : null,
                'timestamp' => $event->created_at,
                'metadata' => $event->metadata,
                'description' => $this->formatEventDescription($event),
            ];
        });
    }

    /**
     * Format event description for display.
     *
     * @param ActivityEvent $event
     * @return string
     */
    protected function formatEventDescription(ActivityEvent $event): string
    {
        $actor = $event->actor ? $event->actor->name : 'System';
        $metadata = $event->metadata ?? [];

        return match ($event->event_type) {
            EventType::TICKET_CREATED => "Ticket created by {$actor}",
            EventType::TICKET_UPDATED => "Ticket updated by {$actor}",
            EventType::TICKET_ASSIGNED => $this->formatAssignmentDescription($actor, $metadata),
            EventType::TICKET_STATUS_CHANGED => $this->formatStatusChangeDescription($actor, $metadata),
            EventType::TICKET_CONVERTED => $this->formatConversionDescription($actor, $metadata),
            EventType::TICKET_INTERNAL_NOTE_ADDED => "Internal note added by {$actor}",
            EventType::TICKET_LINKED => $this->formatLinkingDescription($actor, $metadata),
            default => "Event: {$event->event_type} by {$actor}",
        };
    }

    /**
     * Format assignment change description.
     */
    protected function formatAssignmentDescription(string $actor, array $metadata): string
    {
        $parts = [];
        
        if (isset($metadata['old_assigned_team']) || isset($metadata['new_assigned_team'])) {
            $oldTeam = $metadata['old_assigned_team'] ?? 'Unassigned';
            $newTeam = $metadata['new_assigned_team'] ?? 'Unassigned';
            if ($oldTeam !== $newTeam) {
                $parts[] = "Team: {$oldTeam} → {$newTeam}";
            }
        }
        
        if (isset($metadata['old_assigned_user']) || isset($metadata['new_assigned_user'])) {
            $oldUser = $metadata['old_assigned_user'] ?? 'Unassigned';
            $newUser = $metadata['new_assigned_user'] ?? 'Unassigned';
            if ($oldUser !== $newUser) {
                $parts[] = "User: {$oldUser} → {$newUser}";
            }
        }
        
        if (empty($parts)) {
            return "Assignment updated by {$actor}";
        }
        
        return "Assignment updated by {$actor}: " . implode(', ', $parts);
    }

    /**
     * Format status change description.
     */
    protected function formatStatusChangeDescription(string $actor, array $metadata): string
    {
        $oldStatus = $metadata['old_status'] ?? 'Unknown';
        $newStatus = $metadata['new_status'] ?? 'Unknown';
        return "Status changed by {$actor}: {$oldStatus} → {$newStatus}";
    }

    /**
     * Format conversion description.
     */
    protected function formatConversionDescription(string $actor, array $metadata): string
    {
        $newTicketNumber = $metadata['new_ticket_number'] ?? 'Unknown';
        return "Ticket converted to internal ticket {$newTicketNumber} by {$actor}";
    }

    /**
     * Format linking description.
     */
    protected function formatLinkingDescription(string $actor, array $metadata): string
    {
        $linkType = $metadata['link_type'] ?? 'item';
        $linkableType = $metadata['linkable_type'] ?? 'Unknown';
        return "Linked to {$linkType} ({$linkableType}) by {$actor}";
    }
}
