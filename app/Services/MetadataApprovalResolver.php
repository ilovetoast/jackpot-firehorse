<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PlanService;

/**
 * Metadata Approval Resolver
 *
 * Phase 8: Determines if metadata approval is required and if a user can approve.
 *
 * Rules:
 * - Deterministic and side-effect free
 * - Never mutates data
 * - Plan-gated (Pro plans or feature flag)
 * - Permission-based (checks metadata.bypass_approval permission)
 * - Computed/system metadata NEVER requires approval
 */
class MetadataApprovalResolver
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Check if metadata approval workflow is enabled for a tenant.
     *
     * @param Tenant $tenant
     * @return bool True if approval is enabled, false otherwise
     */
    public function isApprovalEnabled(Tenant $tenant): bool
    {
        // Check feature flag first (allows overriding plan-based gating)
        if (config('features.metadata_approval_enabled', false)) {
            return true;
        }

        // Check plan: Pro plans and higher have approval workflow
        $planName = $this->planService->getCurrentPlan($tenant);
        $proPlans = ['pro', 'enterprise'];

        return in_array($planName, $proPlans);
    }

    /**
     * Check if a metadata value requires approval.
     *
     * @param string $source Metadata source ('user', 'ai', 'system', 'computed')
     * @param Tenant $tenant
     * @param User|null $user Optional user to check for bypass_approval permission
     * @return bool True if approval is required, false otherwise
     */
    public function requiresApproval(string $source, Tenant $tenant, ?User $user = null): bool
    {
        // Approval must be enabled first
        if (!$this->isApprovalEnabled($tenant)) {
            return false;
        }

        // Computed and system metadata NEVER require approval
        if (in_array($source, ['system', 'computed'])) {
            return false;
        }

        // If user has bypass_approval permission, no approval required
        if ($user && $user->hasPermissionForTenant($tenant, 'metadata.bypass_approval')) {
            return false;
        }

        // User edits and AI suggestions require approval when enabled
        return in_array($source, ['user', 'ai']);
    }

    /**
     * Check if a user can approve metadata.
     *
     * @param User $user User to check
     * @param Tenant $tenant Tenant context
     * @return bool True if user can approve, false otherwise
     */
    public function canApprove(User $user, Tenant $tenant): bool
    {
        // Check if user has bypass_approval permission (allows approving)
        return $user->hasPermissionForTenant($tenant, 'metadata.bypass_approval');
    }

    /**
     * Check if a user can propose metadata (create unapproved metadata).
     *
     * @param User $user User to check
     * @param Tenant $tenant Tenant context
     * @return bool True if user can propose, false otherwise
     */
    public function canPropose(User $user, Tenant $tenant): bool
    {
        // Check if user has metadata edit permissions
        return $user->hasPermissionForTenant($tenant, 'metadata.set_on_upload') ||
               $user->hasPermissionForTenant($tenant, 'metadata.edit_post_upload');
    }
}
