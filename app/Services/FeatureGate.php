<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantModule;

/**
 * Feature Gate Service
 *
 * Gates access to features based on tenant plan.
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
     * Check if tenant has access to an approval feature.
     *
     * @param  string  $feature  Feature key (e.g., 'approvals.enabled', 'notifications.enabled')
     */
    public function allows(Tenant $tenant, string $feature): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        $approvalFeatures = $plan['approval_features'] ?? [];

        return $approvalFeatures[$feature] ?? false;
    }

    public function approvalsEnabled(Tenant $tenant): bool
    {
        return $this->allows($tenant, 'approvals.enabled');
    }

    public function notificationsEnabled(Tenant $tenant): bool
    {
        return $this->allows($tenant, 'notifications.enabled');
    }

    public function approvalSummariesEnabled(Tenant $tenant): bool
    {
        return $this->allows($tenant, 'approval_summaries.enabled');
    }

    /**
     * Human-readable plan name for upgrade messaging.
     */
    public function getRequiredPlanName(Tenant $tenant): string
    {
        foreach (['pro', 'business', 'premium', 'enterprise'] as $planName) {
            $plan = config("plans.{$planName}");
            if (($plan['approval_features']['approvals.enabled'] ?? false) === true) {
                $display = $plan['name'] ?? ucfirst($planName);
                if (str_contains($display, 'Legacy')) {
                    continue;
                }

                return $display;
            }
        }

        return 'Pro';
    }

    // -------------------------------------------------------------------------
    // SSO
    // -------------------------------------------------------------------------

    public function ssoEnabled(Tenant $tenant): bool
    {
        return $this->planService->ssoEnabled($tenant);
    }

    // -------------------------------------------------------------------------
    // Upload protection (Free plan email verification)
    // -------------------------------------------------------------------------

    /**
     * Whether the tenant can upload assets.
     *
     * Free plan tenants require the owner to have a verified email before any
     * user on the tenant can upload. Paid plans bypass entirely.
     */
    public function canUploadAssets(Tenant $tenant): bool
    {
        if (! $this->planService->requiresEmailVerificationForUploads($tenant)) {
            return true;
        }

        $owner = $tenant->owner();

        return $owner && $owner->hasVerifiedEmail();
    }

    // -------------------------------------------------------------------------
    // Collections
    // -------------------------------------------------------------------------

    public function publicCollectionsEnabled(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['public_collections_enabled'] ?? false);
    }

    public function publicCollectionDownloadsEnabled(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['public_collection_downloads_enabled'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Brand Portal
    // -------------------------------------------------------------------------

    public function brandPortalCustomization(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_portal']['customization'] ?? false);
    }

    public function brandPortalPublicAccess(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_portal']['public_access'] ?? false);
    }

    public function brandPortalAdvancedSharing(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_portal']['sharing'] ?? false);
    }

    public function brandPortalAgencyTemplates(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_portal']['agency_templates'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Brand Guidelines
    // -------------------------------------------------------------------------

    public function guidelinesCustomization(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        return (bool) ($plan['brand_guidelines']['customization'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Metadata Approval
    // -------------------------------------------------------------------------

    /**
     * Whether user-entered metadata edits go through an approval queue.
     * Requires plan-level approval support AND company/brand settings enabled.
     */
    public function metadataApprovalEnabled(Tenant $company, Brand $brand): bool
    {
        if (! $this->approvalsEnabled($company)) {
            return false;
        }

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

    // -------------------------------------------------------------------------
    // Creator (Prostaff) Module
    // -------------------------------------------------------------------------

    /**
     * Creator module is enabled if:
     * 1. Plan includes it (business/enterprise), OR
     * 2. Tenant has an active/trial tenant_modules row for creator_module
     */
    public function creatorModuleEnabled(Tenant $tenant): bool
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        if (($plan['creator_module_included'] ?? false) === true) {
            return true;
        }

        return TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->whereIn('status', ['active', 'trial'])
            ->where(function ($q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($q): void {
                $q->where('granted_by_admin', false)
                    ->orWhere(function ($q2): void {
                        $q2->where('granted_by_admin', true)
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '>', now());
                    });
            })
            ->exists();
    }

    /**
     * Total creator seats available: plan-included + module add-on + seat packs.
     */
    public function getCreatorSeatsLimit(Tenant $tenant): ?int
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));

        $planSeats = (int) ($plan['creator_module_included_seats'] ?? 0);

        $module = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->whereIn('status', ['active', 'trial'])
            ->first();

        if ($module) {
            $moduleSeats = (int) ($module->seats_limit ?? 0);

            return max($planSeats, $moduleSeats);
        }

        return $planSeats > 0 ? $planSeats : null;
    }
}
