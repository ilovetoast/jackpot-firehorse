<?php

namespace App\Services;

use App\Enums\TicketComponent;
use App\Enums\TicketEnvironment;
use App\Enums\TicketSeverity;
use App\Enums\TicketTeam;
use App\Enums\TicketType;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TicketConversionService
 *
 * Handles conversion of tenant tickets to internal engineering tickets.
 *
 * Conversion Process:
 * 1. Creates a new internal ticket linked to the original tenant ticket
 * 2. Copies relevant metadata from the original ticket
 * 3. Links tickets bi-directionally via converted_from_ticket_id
 * 4. Assigns new ticket to engineering team
 * 5. Original ticket remains visible to tenant
 *
 * Rationale:
 * - Allows engineering to track technical issues separately from customer-facing tickets
 * - Maintains audit trail linking original customer request to internal work
 * - Original ticket remains accessible to tenant for status updates
 */
class TicketConversionService
{
    public function __construct(
        protected TicketAssignmentService $assignmentService
    ) {
    }

    /**
     * Convert a tenant ticket to an internal engineering ticket.
     *
     * @param Ticket $tenantTicket The original tenant ticket
     * @param User $converter The user performing the conversion
     * @param TicketSeverity|null $severity Optional severity level for the engineering ticket
     * @param TicketEnvironment|null $environment Optional environment where issue occurs
     * @param TicketComponent|null $component Optional component affected
     * @return Ticket The newly created internal ticket
     * @throws \Exception
     */
    public function convertToInternal(
        Ticket $tenantTicket,
        User $converter,
        ?TicketSeverity $severity = null,
        ?TicketEnvironment $environment = null,
        ?TicketComponent $component = null
    ): Ticket {
        // Validate ticket type
        if ($tenantTicket->type !== TicketType::TENANT) {
            throw new \InvalidArgumentException('Only tenant tickets can be converted to internal tickets.');
        }

        return DB::transaction(function () use ($tenantTicket, $converter, $severity, $environment, $component) {
            // Prepare metadata with error fingerprint if present
            $metadata = [
                'converted_from' => $tenantTicket->ticket_number,
                'original_subject' => $tenantTicket->metadata['subject'] ?? null,
                'original_category' => $tenantTicket->metadata['category'] ?? null,
            ];
            
            // Copy error fingerprint if present
            if (isset($tenantTicket->metadata['error_fingerprint'])) {
                $metadata['error_fingerprint'] = $tenantTicket->metadata['error_fingerprint'];
            }

            // Create new internal ticket
            $internalTicket = Ticket::create([
                'type' => TicketType::INTERNAL,
                'status' => $tenantTicket->status,
                'tenant_id' => $tenantTicket->tenant_id, // Keep tenant association for context
                'created_by_user_id' => $converter->id, // Staff member who converted
                'assigned_team' => TicketTeam::ENGINEERING,
                'severity' => $severity,
                'environment' => $environment,
                'component' => $component,
                'metadata' => $metadata,
                'converted_from_ticket_id' => $tenantTicket->id,
                'converted_at' => now(),
                'converted_by_user_id' => $converter->id,
            ]);

            // Copy brand associations
            if ($tenantTicket->brands()->exists()) {
                $internalTicket->brands()->sync($tenantTicket->brands()->pluck('brands.id'));
            }

            // Copy attachments with is_internal=true flag
            foreach ($tenantTicket->attachments as $attachment) {
                TicketAttachment::create([
                    'ticket_id' => $internalTicket->id,
                    'ticket_message_id' => null, // Attached to ticket, not message
                    'user_id' => $attachment->user_id,
                    'file_path' => $attachment->file_path,
                    'file_name' => $attachment->file_name,
                    'file_size' => $attachment->file_size,
                    'mime_type' => $attachment->mime_type,
                    'is_internal' => true, // Mark as internal attachment
                ]);
            }

            // Preserve diagnostic links from original ticket
            foreach ($tenantTicket->ticketLinks as $link) {
                TicketLink::create([
                    'ticket_id' => $internalTicket->id,
                    'linkable_type' => $link->linkable_type,
                    'linkable_id' => $link->linkable_id,
                    'link_type' => $link->link_type,
                    'designation' => $link->designation ?? \App\Enums\LinkDesignation::RELATED,
                    'metadata' => $link->metadata,
                ]);
            }

            // Assign to engineering team/user
            $this->assignmentService->assignTicket($internalTicket);

            return $internalTicket;
        });
    }
}
