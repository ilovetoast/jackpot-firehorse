<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureGate;
use App\Services\PlanService;

/**
 * Metadata Approval Resolver
 *
 * Phase 8: Determines if metadata approval is required and if a user can approve.
 *
 * Phase M-2: Extended with company + brand level gating.
 *
 * Rules:
 * - Deterministic and side-effect free
 * - Never mutates data
 * - User edits: gated by {@see FeatureGate::metadataApprovalEnabled()} (company setting; optional per-brand opt-out)
 * - Permission-based (checks metadata.bypass_approval permission)
 * - Computed/system metadata NEVER requires approval
 * - AI proposals always require review (source = ai)
 */
class MetadataApprovalResolver
{
    public function __construct(
        protected PlanService $planService,
        protected FeatureGate $featureGate
    ) {
    }

    /**
     * Check if metadata approval workflow is enabled for a tenant.
     *
     * @param Tenant $tenant
     * @return bool True if approval is enabled, false otherwise
     * @deprecated Use isApprovalEnabledForBrand() instead (Phase M-2)
     */
    public function isApprovalEnabled(Tenant $tenant): bool
    {
        // Check feature flag first (allows overriding plan-based gating)
        if (config('features.metadata_approval_enabled', false)) {
            return true;
        }

        // Check plan: Pro plans and higher have approval workflow
        $planName = $this->planService->getCurrentPlan($tenant);
        $proPlans = ['pro', 'premium', 'enterprise'];

        return in_array($planName, $proPlans);
    }

    /**
     * Phase M-2: Check if metadata approval workflow is enabled for company and brand.
     *
     * @param Tenant $company
     * @param Brand $brand
     * @return bool True if approval is enabled, false otherwise
     */
    public function isApprovalEnabledForBrand(Tenant $company, Brand $brand): bool
    {
        return $this->featureGate->metadataApprovalEnabled($company, $brand);
    }

    /**
     * Check if a metadata value requires approval.
     *
     * @param string $source Metadata source ('user', 'ai', 'system', 'computed')
     * @param Tenant $tenant
     * @param User|null $user Optional user to check for bypass_approval permission
     * @param Brand|null $brand Optional brand for Phase M-2 gating (required for user edits)
     * @return bool True if approval is required, false otherwise
     */
    public function requiresApproval(string $source, Tenant $tenant, ?User $user = null, ?Brand $brand = null): bool
    {
        // Phase M-1: System, automatic, and computed metadata NEVER require approval
        if (in_array($source, ['system', 'automatic', 'computed'])) {
            return false;
        }

        // AI metadata always requires review (proposal-based, regardless of settings)
        if ($source === 'ai') {
            return true;
        }

        // Phase M-2: For user edits, use company (and optional brand opt-out) via FeatureGate
        if ($source === 'user') {
            // Never infer approval from plan when brand is missing (avoids Pro tenants getting pending metadata unexpectedly).
            if (! $brand) {
                return false;
            }

            if (! $this->featureGate->metadataApprovalEnabled($tenant, $brand)) {
                return false;
            }

            if ($user && $user->hasPermissionForTenant($tenant, 'metadata.bypass_approval')) {
                return false;
            }

            return true;
        }

        return false;
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
