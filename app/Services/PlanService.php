<?php

namespace App\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Brand;
use App\Models\Tenant;

class PlanService
{
    /**
     * Get the current plan name for a tenant.
     */
    public function getCurrentPlan(Tenant $tenant): string
    {
        // If manual plan override is set, use it
        if ($tenant->manual_plan_override) {
            $overridePlan = $tenant->manual_plan_override;
            // Validate that the plan exists in config
            if (config("plans.{$overridePlan}")) {
                return $overridePlan;
            }
        }
        
        // Get the most recent active subscription with name 'default'
        // Don't rely on Cashier's subscribed() method as it may not work correctly
        // with multiple subscriptions or when using Tenant instead of User
        $subscription = $tenant->subscriptions()
            ->where('name', 'default')
            ->where('stripe_status', 'active')
            ->orderBy('created_at', 'desc')
            ->first();
        
        // Fallback to Cashier's method if direct query doesn't work
        if (! $subscription) {
            $subscription = $tenant->subscription('default');
        }
        
        if (! $subscription) {
            return 'free';
        }

        // First try to get price from subscription
        $priceId = $subscription->stripe_price;
        
        // If not found, try to get from subscription items
        if (! $priceId && $subscription->items->count() > 0) {
            $priceId = $subscription->items->first()->stripe_price;
        }

        if (! $priceId) {
            return 'free';
        }

        // Find plan by Stripe price ID
        foreach (config('plans') as $planName => $planConfig) {
            if ($planConfig['stripe_price_id'] === $priceId) {
                return $planName;
            }
        }

        // Default to free if price ID not found
        return 'free';
    }
    
    /**
     * Check if plan is externally managed (Shopify, etc.) and cannot be adjusted from backend.
     */
    public function isExternallyManaged(Tenant $tenant): bool
    {
        $source = $tenant->plan_management_source;
        
        // If explicitly set to shopify, it's externally managed
        if ($source === 'shopify') {
            return true;
        }
        
        // Auto-detect: if no plan_management_source is set but they have stripe_id, 
        // we can manage it (Stripe allows admin updates)
        // Only Shopify is considered externally managed
        return false;
    }
    
    /**
     * Get the plan management source, auto-detecting if not set.
     */
    public function getPlanManagementSource(Tenant $tenant): string
    {
        if ($tenant->plan_management_source) {
            return $tenant->plan_management_source;
        }
        
        // Auto-detect based on available integrations
        if ($tenant->stripe_id) {
            return 'stripe';
        }
        
        // Default to manual if no integration found
        return 'manual';
    }

    /**
     * Get plan limits for a tenant.
     */
    public function getPlanLimits(Tenant $tenant): array
    {
        $planName = $this->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}");

        return $plan['limits'] ?? config('plans.free.limits');
    }

    /**
     * Check if tenant can create a brand.
     */
    public function canCreateBrand(Tenant $tenant): bool
    {
        $limits = $this->getPlanLimits($tenant);
        $currentCount = $tenant->brands()->count();

        return $currentCount < $limits['max_brands'];
    }

    /**
     * Check if tenant can create a category for a brand.
     * Only counts custom (non-system) categories against the limit.
     */
    public function canCreateCategory(Tenant $tenant, Brand $brand): bool
    {
        $limits = $this->getPlanLimits($tenant);
        
        // Count only custom (non-system) categories for the brand
        $currentCount = $brand->categories()->custom()->count();

        return $currentCount < $limits['max_categories'];
    }

    /**
     * Get maximum upload size in bytes.
     */
    public function getMaxUploadSize(Tenant $tenant): int
    {
        $limits = $this->getPlanLimits($tenant);

        return $limits['max_upload_size_mb'] * 1024 * 1024; // Convert MB to bytes
    }

    /**
     * Get maximum storage in bytes.
     */
    public function getMaxStorage(Tenant $tenant): int
    {
        $limits = $this->getPlanLimits($tenant);

        return $limits['max_storage_mb'] * 1024 * 1024; // Convert MB to bytes
    }

    /**
     * Generic limit checker.
     *
     * @throws PlanLimitExceededException
     */
    public function checkLimit(string $limitType, Tenant $tenant, ?Brand $brand = null): bool
    {
        $limits = $this->getPlanLimits($tenant);
        $maxAllowed = $limits["max_{$limitType}"] ?? PHP_INT_MAX;

        $currentCount = match ($limitType) {
            'brands' => $tenant->brands()->count(),
            'categories' => $brand
                ? $brand->categories()->custom()->count() // Only count custom (non-system) categories
                : throw new \InvalidArgumentException('Brand is required for category limit checks'),
            default => 0,
        };

        if ($currentCount >= $maxAllowed) {
            throw new PlanLimitExceededException($limitType, $currentCount, $maxAllowed);
        }

        return true;
    }

    /**
     * Get current plan name.
     */
    public static function getCurrentPlanName(Tenant $tenant): string
    {
        return (new self)->getCurrentPlan($tenant);
    }

    /**
     * Check if tenant has access to brand_manager role.
     * Only Pro and Enterprise plans have access to brand_manager role.
     */
    public function hasAccessToBrandManagerRole(Tenant $tenant): bool
    {
        $planName = $this->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));
        
        return in_array('access_to_more_roles', $plan['features'] ?? []);
    }

    /**
     * Get plan features for a tenant.
     */
    public function getPlanFeatures(Tenant $tenant): array
    {
        $planName = $this->getCurrentPlan($tenant);
        $plan = config("plans.{$planName}", config('plans.free'));
        
        return $plan['features'] ?? [];
    }

    /**
     * Check if tenant has a specific plan feature.
     */
    public function hasFeature(Tenant $tenant, string $feature): bool
    {
        $features = $this->getPlanFeatures($tenant);
        
        return in_array($feature, $features);
    }
}
