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
        // Check if tenant has an active subscription
        if (! $tenant->subscribed()) {
            return 'free';
        }

        $subscription = $tenant->subscription();
        $priceId = $subscription->stripe_price ?? null;

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
}
