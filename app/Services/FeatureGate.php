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
        foreach (['pro', 'premium', 'enterprise'] as $planName) {
            $plan = config("plans.{$planName}");
            if (($plan['approval_features']['approvals.enabled'] ?? false) === true) {
                return ucfirst($planName);
            }
        }
        
        return 'Pro';
    }

    /**
     * C10: Check if tenant has Public Collections feature (plan-gated; e.g. Enterprise).
     * When false: public toggle is hidden/disabled; public routes return 404.
     *
     * @param Tenant $tenant
     * @return bool
     */
    public function publicCollectionsEnabled(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));
        return (bool) ($plan['public_collections_enabled'] ?? false);
    }

    /**
     * D6: Check if tenant can create downloads from public collection page (Download collection as ZIP).
     */
    public function publicCollectionDownloadsEnabled(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));
        return (bool) ($plan['public_collection_downloads_enabled'] ?? false);
    }

    /**
     * Brand Portal: Check if tenant can customize portal entry experience, tagline, invite branding.
     * Available on Pro+.
     */
    public function brandPortalCustomization(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_portal']['customization'] ?? false);
    }

    /**
     * Brand Portal: Check if tenant can enable public portal access (subdomain, public links).
     * Available on Premium+.
     */
    public function brandPortalPublicAccess(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_portal']['public_access'] ?? false);
    }

    /**
     * Brand Portal: Check if tenant has advanced sharing (external collections, expiring links).
     * Available on Enterprise only.
     */
    public function brandPortalAdvancedSharing(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_portal']['sharing'] ?? false);
    }

    /**
     * Brand Portal: Check if tenant can use agency portal templates.
     * Available on Enterprise only.
     */
    public function brandPortalAgencyTemplates(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_portal']['agency_templates'] ?? false);
    }

    /**
     * Brand Guidelines: Check if tenant can customize guidelines presentation (sidebar editor).
     * Available on Pro+.
     */
    public function guidelinesCustomization(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_guidelines']['customization'] ?? false);
    }

    /**
     * Phase M-2: Whether user-entered metadata edits go through an approval queue.
     *
     * Primary switch: Company Settings → “Require metadata approval” (`enable_metadata_approval`).
     * When that is on, all brands use the workflow unless a brand explicitly opts out.
     *
     * Optional per-brand opt-out: `brand.settings.metadata_approval_enabled === false` (must be explicitly false).
     * Missing key = follow company (workflow on when company enabled).
     *
     * @param Tenant $company
     * @param Brand $brand
     * @return bool
     */
    public function metadataApprovalEnabled(Tenant $company, Brand $brand): bool
    {
        $companySettings = $company->settings ?? [];
        $companyEnabled = $companySettings['enable_metadata_approval'] ?? false;

        if (! $companyEnabled || ($companyEnabled !== true && $companyEnabled !== '1' && $companyEnabled !== 1)) {
            return false;
        }

        $brandSettings = $brand->settings ?? [];
        if (array_key_exists('metadata_approval_enabled', $brandSettings)) {
            $be = $brandSettings['metadata_approval_enabled'];
            if ($be === false || $be === '0' || $be === 0) {
                return false;
            }
        }

        return true;
    }
}
