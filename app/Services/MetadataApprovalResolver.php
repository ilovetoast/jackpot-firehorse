<?php

namespace App\Services;

use App\Models\Tenant;
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
 * - Role-based (Manager/Admin can approve)
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
     * @return bool True if approval is required, false otherwise
     */
    public function requiresApproval(string $source, Tenant $tenant): bool
    {
        // Approval must be enabled first
        if (!$this->isApprovalEnabled($tenant)) {
            return false;
        }

        // Computed and system metadata NEVER require approval
        if (in_array($source, ['system', 'computed'])) {
            return false;
        }

        // User edits and AI suggestions require approval when enabled
        return in_array($source, ['user', 'ai']);
    }

    /**
     * Check if a user can approve metadata.
     *
     * @param string $role User's role (e.g., 'owner', 'admin', 'manager', 'editor', 'viewer')
     * @return bool True if user can approve, false otherwise
     */
    public function canApprove(string $role): bool
    {
        // Manager and Admin can approve
        $approverRoles = ['owner', 'admin', 'manager'];

        return in_array(strtolower($role), array_map('strtolower', $approverRoles));
    }

    /**
     * Check if a user can propose metadata (create unapproved metadata).
     *
     * This uses existing edit permission logic.
     *
     * @param string $role User's role
     * @return bool True if user can propose, false otherwise
     */
    public function canPropose(string $role): bool
    {
        // Viewers cannot propose
        if (strtolower($role) === 'viewer') {
            return false;
        }

        // All other roles can propose (they can edit, so they can propose)
        return true;
    }
}
