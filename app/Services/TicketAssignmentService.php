<?php

namespace App\Services;

use App\Enums\TicketTeam;
use App\Enums\TicketType;
use App\Models\EngineeringRoundRobinUser;
use App\Models\SupportRoundRobinUser;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * TicketAssignmentService
 *
 * Handles automatic ticket assignment logic.
 * Determines assigned team and user based on ticket type and available support staff.
 *
 * Support Assignment (Round-Robin):
 * - Uses support_round_robin_users bucket (users with support tag added via Support page)
 * - When bucket is empty, falls back to config('tickets.round_robin_default_user_ids', [1])
 * - Round-robin cycles through bucket; last assigned user tracked in cache
 *
 * Engineering Assignment (round-robin):
 * - engineering_round_robin_users bucket (Site Engineering, Admin, or Owner — managed on Engineering queue UI)
 * - When empty, falls back to config('tickets.engineering_round_robin_default_user_ids', [1])
 * - Then fall back to first user with site_engineering, then site_admin
 *
 * Important:
 * - Support roles are global (not tenant-scoped)
 * - A user may hold both tenant and site roles
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
     * Find support user via round-robin from bucket.
     * Bucket: support_round_robin_users table. When empty, uses config default [1].
     *
     * @return User|null
     */
    protected function findSupportUser(): ?User
    {
        $userIds = SupportRoundRobinUser::getBucketUserIds();
        if (empty($userIds)) {
            $userIds = config('tickets.round_robin_default_user_ids', [1]);
        }
        if (empty($userIds)) {
            return $this->findSupportUserByRole();
        }

        $lastUserId = Cache::get('support_round_robin_last_user_id');
        $currentIndex = $lastUserId ? array_search((int) $lastUserId, array_map('intval', $userIds), true) : false;
        $nextIndex = ($currentIndex !== false && $currentIndex < count($userIds) - 1)
            ? $currentIndex + 1
            : 0;
        $nextUserId = $userIds[$nextIndex];

        Cache::put('support_round_robin_last_user_id', $nextUserId, now()->addYear());

        $user = User::find($nextUserId);

        return $user ?? $this->findSupportUserByRole();
    }

    /**
     * Fallback: find support user by role when bucket/config yields no valid user.
     *
     * @return User|null
     */
    protected function findSupportUserByRole(): ?User
    {
        foreach (['site_support', 'site_admin', 'site_owner'] as $roleName) {
            $users = $this->getUsersWithRole($roleName);
            if ($users->isNotEmpty()) {
                return $users->first();
            }
        }

        return null;
    }

    /**
     * Find engineering user via round-robin from the engineering bucket, then config, then role order.
     *
     * @return User|null
     */
    protected function findEngineeringUser(): ?User
    {
        $userIds = EngineeringRoundRobinUser::getBucketUserIds();
        if (empty($userIds)) {
            $userIds = config('tickets.engineering_round_robin_default_user_ids', [1]);
        }
        if (empty($userIds)) {
            return $this->findEngineeringUserByRole();
        }

        $userIds = array_map('intval', $userIds);
        $lastUserId = Cache::get('engineering_round_robin_last_user_id');
        $currentIndex = $lastUserId ? array_search((int) $lastUserId, $userIds, true) : false;
        $nextIndex = ($currentIndex !== false && $currentIndex < count($userIds) - 1)
            ? $currentIndex + 1
            : 0;
        $nextUserId = $userIds[$nextIndex];

        Cache::put('engineering_round_robin_last_user_id', $nextUserId, now()->addYear());

        $user = User::find($nextUserId);

        return $user ?? $this->findEngineeringUserByRole();
    }

    /**
     * Legacy fallback: first Site Engineering, then first Site Admin.
     */
    protected function findEngineeringUserByRole(): ?User
    {
        $users = $this->getUsersWithRole('site_engineering');
        if ($users->isNotEmpty()) {
            return $users->first();
        }

        $users = $this->getUsersWithRole('site_admin');
        if ($users->isNotEmpty()) {
            return $users->first();
        }

        $users = $this->getUsersWithRole('site_owner');
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
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    protected function getUsersWithRole(string $roleName): \Illuminate\Database\Eloquent\Collection
    {
        $role = app(SpatieRoleLookup::class)->roleByName($roleName);
        if (!$role) {
            return new \Illuminate\Database\Eloquent\Collection([]);
        }

        return \Illuminate\Database\Eloquent\Collection::make($role->users->all());
    }
}
