<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;

/**
 * Phase AF-5: Feature Gate Service
 * 
 * Gates access to approval workflow features based on tenant plan.
 * Non-destructive: preserves data on downgrade, only gates access.
 * 
 * Phase M-2: Extended with metadata approval gating (company + brand level).
 */
class FeatureGate
{
    protected PlanService $planService;

    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }

    /**
     * Check if tenant has access to a feature.
     * 
     * @param Tenant $tenant
     * @param string $feature Feature key (e.g., 'approvals.enabled', 'notifications.enabled')
     * @return bool
     */
    public function allows(Tenant $tenant, string $feature): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));
        
        // Check approval_features array
        $approvalFeatures = $plan['approval_features'] ?? [];
        
        return $approvalFeatures[$feature] ?? false;
    }

    /**
     * Check if approvals are enabled for tenant.
     * 
     * @param Tenant $tenant
     * @return bool
     */
    public function approvalsEnabled(Tenant $tenant): bool
    {
        return $this->allows($tenant, 'approvals.enabled');
    }

    /**
     * Check if approval notifications are enabled for tenant.
     * 
     * @param Tenant $tenant
     * @return bool
     */
    public function notificationsEnabled(Tenant $tenant): bool
    {
        return $this->allows($tenant, 'notifications.enabled');
    }

    /**
     * Check if approval summaries are enabled for tenant.
     * 
     * @param Tenant $tenant
     * @return bool
     */
    public function approvalSummariesEnabled(Tenant $tenant): bool
    {
        return $this->allows($tenant, 'approval_summaries.enabled');
    }

    /**
     * Get human-readable plan name for feature gating messages.
     * 
     * @param Tenant $tenant
     * @return string
     */
    public function getRequiredPlanName(Tenant $tenant): string
    {
        // Find the lowest plan that has approvals enabled
        foreach (['pro', 'enterprise'] as $planName) {
            $plan = config("plans.{$planName}");
            if (($plan['approval_features']['approvals.enabled'] ?? false) === true) {
                return ucfirst($planName);
            }
        }
        
        return 'Pro';
    }

    /**
     * Phase M-2: Check if metadata approval is enabled for company and brand.
     * 
     * Returns true ONLY if:
     * - company.settings.enable_metadata_approval === true
     * - brand.settings.metadata_approval_enabled === true
     * 
     * @param Tenant $company
     * @param Brand $brand
     * @return bool
     */
    public function metadataApprovalEnabled(Tenant $company, Brand $brand): bool
    {
        // Check company setting
        $companySettings = $company->settings ?? [];
        $companyEnabled = $companySettings['enable_metadata_approval'] ?? false;
        
        // Handle both boolean true and truthy values (including string "1")
        if (!$companyEnabled || ($companyEnabled !== true && $companyEnabled !== '1' && $companyEnabled !== 1)) {
            return false;
        }
        
        // Check brand setting
        $brandSettings = $brand->settings ?? [];
        $brandEnabled = $brandSettings['metadata_approval_enabled'] ?? false;
        
        // Handle both boolean true and truthy values (including string "1")
        // This prevents issues where settings are stored as strings from JSON/forms
        return $brandEnabled === true || $brandEnabled === '1' || $brandEnabled === 1;
    }
}
