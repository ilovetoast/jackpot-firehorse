<?php

namespace App\Services;

use App\Enums\TicketComponent;
use App\Enums\TicketEnvironment;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Enums\TicketTeam;
use App\Enums\TicketType;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * TicketCreationService
 * 
 * Service for programmatic ticket creation (automation readiness).
 * 
 * This service allows creating internal engineering tickets without requiring
 * a human user, making it suitable for future automation and AI agents.
 * 
 * Features:
 * - Validates engineering fields
 * - Handles system user assignment
 * - Supports structured metadata
 * - Ensures proper ticket number generation
 */
class TicketCreationService
{
    public function __construct(
        protected TicketAssignmentService $assignmentService
    ) {
    }

    /**
     * Create an internal engineering ticket programmatically.
     * 
     * @param array $data Ticket data including:
     *   - subject (string, required)
     *   - description (string, required)
     *   - severity (TicketSeverity, optional)
     *   - environment (TicketEnvironment, optional)
     *   - component (TicketComponent, optional)
     *   - error_fingerprint (string, optional)
     *   - tenant_id (int, optional)
     *   - metadata (array, optional)
     * @param User|null $creator Optional user creating the ticket (defaults to system user)
     * @return Ticket The created ticket
     * @throws \Illuminate\Validation\ValidationException
     */
    public function createInternalEngineeringTicket(array $data, ?User $creator = null): Ticket
    {
        // Validate input
        $validator = Validator::make($data, [
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:10000',
            'severity' => 'nullable|string|in:' . implode(',', array_column(TicketSeverity::cases(), 'value')),
            'environment' => 'nullable|string|in:' . implode(',', array_column(TicketEnvironment::cases(), 'value')),
            'component' => 'nullable|string|in:' . implode(',', array_column(TicketComponent::cases(), 'value')),
            'error_fingerprint' => 'nullable|string|max:255',
            'tenant_id' => 'nullable|exists:tenants,id',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        // Get system user if no creator provided
        if (!$creator) {
            $creator = User::where('email', 'system@internal')->first();
            if (!$creator) {
                // Fallback: use user ID 1 if system user doesn't exist
                $creator = User::find(1);
            }
        }

        return DB::transaction(function () use ($data, $creator) {
            // Prepare metadata
            $metadata = $data['metadata'] ?? [];
            $metadata['subject'] = $data['subject'];
            $metadata['description'] = $data['description'];
            
            if (isset($data['error_fingerprint'])) {
                $metadata['error_fingerprint'] = $data['error_fingerprint'];
            }

            // Create ticket
            $ticket = Ticket::create([
                'type' => TicketType::INTERNAL,
                'status' => TicketStatus::OPEN,
                'tenant_id' => $data['tenant_id'] ?? null,
                'created_by_user_id' => $creator->id,
                'assigned_team' => TicketTeam::ENGINEERING,
                'severity' => isset($data['severity']) ? TicketSeverity::from($data['severity']) : null,
                'environment' => isset($data['environment']) ? TicketEnvironment::from($data['environment']) : null,
                'component' => isset($data['component']) ? TicketComponent::from($data['component']) : null,
                'metadata' => $metadata,
            ]);

            // Assign to engineering team/user
            $this->assignmentService->assignTicket($ticket);

            return $ticket;
        });
    }
}
