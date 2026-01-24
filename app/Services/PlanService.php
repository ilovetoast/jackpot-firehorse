<?php

namespace App\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

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
     * Check if tenant can create a private category for a brand.
     * Requires Pro or Enterprise plan, and checks private category limit.
     */
    public function canCreatePrivateCategory(Tenant $tenant, Brand $brand): bool
    {
        $planName = $this->getCurrentPlan($tenant);
        
        // Only Pro and Enterprise plans can create private categories
        if (!in_array($planName, ['pro', 'enterprise'])) {
            return false;
        }

        $limits = $this->getPlanLimits($tenant);
        $maxPrivateCategories = $limits['max_private_categories'] ?? 0;
        
        // Count only private custom (non-system) categories for the brand
        $currentPrivateCount = $brand->categories()
            ->custom()
            ->where('is_private', true)
            ->count();

        return $currentPrivateCount < $maxPrivateCategories;
    }

    /**
     * Get the maximum number of private categories allowed for a tenant.
     */
    public function getMaxPrivateCategories(Tenant $tenant): int
    {
        $planName = $this->getCurrentPlan($tenant);
        
        // Only Pro and Enterprise plans can create private categories
        if (!in_array($planName, ['pro', 'enterprise'])) {
            return 0;
        }

        $limits = $this->getPlanLimits($tenant);
        return $limits['max_private_categories'] ?? 0;
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
     * Get current storage usage in bytes for a tenant.
     */
    public function getCurrentStorageUsage(Tenant $tenant): int
    {
        // Sum up size_bytes for all visible assets in the tenant
        // Only count completed assets (not failed/processing uploads)
        $totalBytes = DB::table('assets')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'visible') // Only count visible assets
            ->whereNull('deleted_at')
            ->sum('size_bytes');

        return (int) $totalBytes;
    }

    /**
     * Get storage usage as a percentage of the plan limit.
     */
    public function getStorageUsagePercentage(Tenant $tenant): float
    {
        $maxStorage = $this->getMaxStorage($tenant);
        $currentUsage = $this->getCurrentStorageUsage($tenant);

        // Handle unlimited plans (very large numbers)
        if ($maxStorage >= 999999 * 1024 * 1024) {
            return 0.0; // Unlimited
        }

        if ($maxStorage === 0) {
            return 100.0; // No storage allowed
        }

        return ($currentUsage / $maxStorage) * 100;
    }

    /**
     * Check if adding a file would exceed storage limits.
     */
    public function canAddFile(Tenant $tenant, int $fileSizeBytes): bool
    {
        $maxStorage = $this->getMaxStorage($tenant);
        $currentUsage = $this->getCurrentStorageUsage($tenant);

        // Handle unlimited plans (very large numbers)
        if ($maxStorage >= 999999 * 1024 * 1024) {
            return true; // Unlimited
        }

        return ($currentUsage + $fileSizeBytes) <= $maxStorage;
    }

    /**
     * Get storage usage information for display.
     */
    public function getStorageInfo(Tenant $tenant): array
    {
        $maxStorage = $this->getMaxStorage($tenant);
        $currentUsage = $this->getCurrentStorageUsage($tenant);
        $usagePercentage = $this->getStorageUsagePercentage($tenant);

        $isUnlimited = $maxStorage >= 999999 * 1024 * 1024;

        return [
            'current_usage_bytes' => $currentUsage,
            'max_storage_bytes' => $maxStorage,
            'current_usage_mb' => round($currentUsage / 1024 / 1024, 2),
            'max_storage_mb' => round($maxStorage / 1024 / 1024, 2),
            'usage_percentage' => round($usagePercentage, 2),
            'remaining_bytes' => $isUnlimited ? PHP_INT_MAX : max(0, $maxStorage - $currentUsage),
            'remaining_mb' => $isUnlimited ? PHP_INT_MAX : round(max(0, $maxStorage - $currentUsage) / 1024 / 1024, 2),
            'is_unlimited' => $isUnlimited,
            'is_near_limit' => !$isUnlimited && $usagePercentage >= 80,
            'is_at_limit' => !$isUnlimited && $usagePercentage >= 95,
        ];
    }

    /**
     * Enforce storage limits before upload.
     * 
     * @param Tenant $tenant
     * @param int $additionalBytes Additional bytes to be added
     * @throws PlanLimitExceededException
     */
    public function enforceStorageLimit(Tenant $tenant, int $additionalBytes): void
    {
        if (!$this->canAddFile($tenant, $additionalBytes)) {
            $storageInfo = $this->getStorageInfo($tenant);
            
            throw new PlanLimitExceededException(
                'storage',
                $storageInfo['current_usage_bytes'] + $additionalBytes,
                $storageInfo['max_storage_bytes'],
                "Adding this file would exceed your storage limit. Current usage: {$storageInfo['current_usage_mb']} MB, Plan limit: {$storageInfo['max_storage_mb']} MB"
            );
        }
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

    /**
     * Get maximum tags allowed per asset for tenant's plan.
     */
    public function getMaxTagsPerAsset(Tenant $tenant): int
    {
        $limits = $this->getPlanLimits($tenant);
        return $limits['max_tags_per_asset'] ?? 1; // Default to 1 if not set
    }

    /**
     * Get current tag count for an asset.
     */
    public function getCurrentTagCount(Asset $asset): int
    {
        return DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->count();
    }

    /**
     * Check if tenant can add a tag to an asset.
     */
    public function canAddTag(Asset $asset): bool
    {
        $tenant = $asset->tenant;
        $maxTags = $this->getMaxTagsPerAsset($tenant);
        $currentTags = $this->getCurrentTagCount($asset);

        return $currentTags < $maxTags;
    }

    /**
     * Check if tenant can add multiple tags to an asset.
     */
    public function canAddTags(Asset $asset, int $tagCount): bool
    {
        $tenant = $asset->tenant;
        $maxTags = $this->getMaxTagsPerAsset($tenant);
        $currentTags = $this->getCurrentTagCount($asset);

        return ($currentTags + $tagCount) <= $maxTags;
    }

    /**
     * Throw exception if adding tag would exceed plan limit.
     */
    public function enforceTagLimit(Asset $asset, int $additionalTags = 1): void
    {
        if (!$this->canAddTags($asset, $additionalTags)) {
            $tenant = $asset->tenant;
            $maxTags = $this->getMaxTagsPerAsset($tenant);
            $currentTags = $this->getCurrentTagCount($asset);

            throw new PlanLimitExceededException(
                'tags_per_asset',
                $currentTags,
                $maxTags,
                "This asset has reached the maximum of {$maxTags} tags allowed on your plan. Current tags: {$currentTags}."
            );
        }
    }
}
