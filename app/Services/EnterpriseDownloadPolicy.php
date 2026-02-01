<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * D11: Enterprise Download Policy — resolve tenant-level delivery rules from config.
 * Enterprise plan only; non-enterprise tenants have no policy (all methods return false / null).
 *
 * NOTE:
 * Download policy enforces organizational delivery rules.
 * It is not DRM, legal compliance, or access control.
 * Customers remain responsible for legal agreements (NDAs, licenses).
 */
class EnterpriseDownloadPolicy
{
    public function __construct(
        protected PlanService $planService
    ) {}

    /**
     * Effective policy array for tenant (null if not enterprise or no policy).
     * D12: Tenant-level overrides from settings['download_policy'] merge over plan config.
     */
    protected function getPolicy(Tenant $tenant): ?array
    {
        $planName = $this->planService->getCurrentPlan($tenant);
        if ($planName !== 'enterprise') {
            return null;
        }
        $plan = config("plans.{$planName}");
        $base = $plan['download_policy'] ?? null;
        if ($base === null) {
            return null;
        }
        $overrides = $tenant->settings['download_policy'] ?? [];
        if (! is_array($overrides) || empty($overrides)) {
            return $base;
        }
        return array_merge($base, $overrides);
    }

    /**
     * Whether single-asset (drawer) downloads are disabled by policy.
     */
    public function disableSingleAssetDownloads(Tenant $tenant): bool
    {
        $policy = $this->getPolicy($tenant);

        return ($policy['disable_single_asset_downloads'] ?? false) === true;
    }

    /**
     * Whether public downloads must use a landing page.
     */
    public function requireLandingPageForPublic(Tenant $tenant): bool
    {
        $policy = $this->getPolicy($tenant);

        return ($policy['require_landing_page_for_public'] ?? false) === true;
    }

    /**
     * Forced expiration days (overrides user input). Null if not forced.
     */
    public function forceExpirationDays(Tenant $tenant): ?int
    {
        $policy = $this->getPolicy($tenant);
        $days = $policy['force_expiration_days'] ?? null;
        if ($days === null || $days === '') {
            return null;
        }

        return (int) $days;
    }

    /**
     * Whether non-expiring downloads are disallowed by policy.
     */
    public function disallowNonExpiring(Tenant $tenant): bool
    {
        $policy = $this->getPolicy($tenant);

        return ($policy['disallow_non_expiring'] ?? false) === true;
    }

    /**
     * Whether public downloads must have a password.
     */
    public function requirePasswordForPublic(Tenant $tenant): bool
    {
        $policy = $this->getPolicy($tenant);

        return ($policy['require_password_for_public'] ?? false) === true;
    }
}
