<?php

namespace App\Policies;

use App\Models\User;

/**
 * AI Dashboard Policy
 *
 * Authorization rules for AI Dashboard access.
 * Uses permission-based checks (not role-based).
 *
 * Permissions:
 * - ai.dashboard.view: View AI Dashboard (read-only)
 * - ai.dashboard.manage: Edit AI configuration overrides
 *
 * Default assignments (from PermissionSeeder):
 * - Site Owner: ai.dashboard.manage
 * - Site Admin: ai.dashboard.manage
 * - Compliance: ai.dashboard.view (read-only)
 */
class AIDashboardPolicy
{
    /**
     * Determine if the user can view any AI dashboard pages.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('ai.dashboard.view');
    }

    /**
     * Determine if the user can view the AI dashboard.
     */
    public function view(User $user): bool
    {
        return $user->can('ai.dashboard.view');
    }

    /**
     * Determine if the user can manage AI configuration.
     */
    public function manage(User $user): bool
    {
        return $user->can('ai.dashboard.manage');
    }

    /**
     * Determine if the user can update model overrides.
     */
    public function updateModelOverride(User $user): bool
    {
        return $user->can('ai.dashboard.manage');
    }

    /**
     * Determine if the user can update agent overrides.
     */
    public function updateAgentOverride(User $user): bool
    {
        return $user->can('ai.dashboard.manage');
    }

    /**
     * Determine if the user can update automation overrides.
     */
    public function updateAutomationOverride(User $user): bool
    {
        return $user->can('ai.dashboard.manage');
    }
}
