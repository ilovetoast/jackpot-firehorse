<?php

namespace App\Policies;

use App\Models\User;

/**
 * Metadata Registry Policy
 *
 * Authorization rules for System Metadata Registry access.
 * Uses permission-based checks (not role-based).
 *
 * Permissions:
 * - metadata.registry.view: View System Metadata Registry (read-only)
 *
 * Default assignments (from PermissionSeeder):
 * - Site Owner: metadata.registry.view
 * - Site Admin: metadata.registry.view
 * - Compliance: metadata.registry.view (read-only)
 */
class MetadataRegistryPolicy
{
    /**
     * Determine if the user can view the metadata registry.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('metadata.registry.view');
    }

    /**
     * Determine if the user can view the metadata registry.
     */
    public function view(User $user): bool
    {
        return $user->can('metadata.registry.view');
    }
}
