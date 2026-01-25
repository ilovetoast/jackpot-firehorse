<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * Phase AF-5: Feature Gate Service
 * 
 * Gates access to approval workflow features based on tenant plan.
 * Non-destructive: preserves data on downgrade, only gates access.
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
}
