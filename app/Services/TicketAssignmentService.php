<?php

namespace App\Services;

use App\Enums\TicketTeam;
use App\Enums\TicketType;
use App\Models\Ticket;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * TicketAssignmentService
 *
 * Handles automatic ticket assignment logic.
 * Determines assigned team and user based on ticket type and available support staff.
 *
 * Assignment Rules:
 * 1. For tenant tickets:
 *    - Attempt assignment in this order:
 *      a) Users with global role: Site Support
 *      b) Users with global role: Site Admin
 *      c) Users with global role: Site Owner
 * 2. For internal tickets:
 *    - Assign to assigned_team = engineering
 *    - Attempt assignment to users with role: Site Engineering
 *    - Fallback to Site Admin if none exist
 * 3. For tenant_internal tickets:
 *    - Assign to assigned_team = support
 *    - Same assignment logic as tenant tickets
 *
 * Important:
 * - Support roles are global (not tenant-scoped)
 * - A user may hold both tenant and site roles
 * - Assignment logic is deterministic and testable
 * - If no eligible user exists, assigned_to_user_id is left null but assigned_team is still set
 */
class TicketAssignmentService
{
    /**
     * Assign ticket to team and user.
     * Main entry point for ticket assignment.
     *
     * @param Ticket $ticket
     * @return void
     */
    public function assignTicket(Ticket $ticket): void
    {
        // Determine team
        $team = $this->determineTeam($ticket);
        $ticket->assigned_team = $team;

        // Find eligible user
        $user = $this->findEligibleUser($ticket, $team);
        if ($user) {
            $ticket->assigned_to_user_id = $user->id;
        }

        $ticket->save();
    }

    /**
     * Determine team assignment based on ticket type.
     *
     * @param Ticket $ticket
     * @return TicketTeam
     */
    public function determineTeam(Ticket $ticket): TicketTeam
    {
        return match ($ticket->type) {
            TicketType::TENANT => TicketTeam::SUPPORT,
            TicketType::TENANT_INTERNAL => TicketTeam::SUPPORT,
            TicketType::INTERNAL => TicketTeam::ENGINEERING,
        };
    }

    /**
     * Find eligible user to assign based on ticket type and team.
     *
     * @param Ticket $ticket
     * @param TicketTeam $team
     * @return User|null
     */
    public function findEligibleUser(Ticket $ticket, TicketTeam $team): ?User
    {
        // For tenant or tenant_internal tickets, use support assignment logic
        if (in_array($ticket->type, [TicketType::TENANT, TicketType::TENANT_INTERNAL])) {
            return $this->findSupportUser();
        }

        // For internal tickets, use engineering assignment logic
        if ($ticket->type === TicketType::INTERNAL) {
            return $this->findEngineeringUser();
        }

        return null;
    }

    /**
     * Find support user (Site Support → Site Admin → Site Owner).
     *
     * @return User|null
     */
    protected function findSupportUser(): ?User
    {
        // Try Site Support first
        $users = $this->getUsersWithRole('site_support');
        if ($users->isNotEmpty()) {
            return $users->first();
        }

        // Try Site Admin
        $users = $this->getUsersWithRole('site_admin');
        if ($users->isNotEmpty()) {
            return $users->first();
        }

        // Try Site Owner
        $users = $this->getUsersWithRole('site_owner');
        if ($users->isNotEmpty()) {
            return $users->first();
        }

        return null;
    }

    /**
     * Find engineering user (Site Engineering → Site Admin).
     *
     * @return User|null
     */
    protected function findEngineeringUser(): ?User
    {
        // Try Site Engineering first
        $users = $this->getUsersWithRole('site_engineering');
        if ($users->isNotEmpty()) {
            return $users->first();
        }

        // Fallback to Site Admin
        $users = $this->getUsersWithRole('site_admin');
        if ($users->isNotEmpty()) {
            return $users->first();
        }

        return null;
    }

    /**
     * Get users with a specific global site role.
     * Uses Spatie permissions to query users with the role.
     *
     * @param string $roleName Role name (e.g., 'site_support', 'site_admin')
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getUsersWithRole(string $roleName): \Illuminate\Database\Eloquent\Collection
    {
        $role = Role::where('name', $roleName)->first();
        if (!$role) {
            return collect();
        }

        return $role->users;
    }
}
