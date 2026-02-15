<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Enums\EventType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryAccess;
use App\Models\Tenant;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Category Service
 *
 * Handles business logic for category creation, updates, and deletion.
 * Ensures plan limits are enforced and system categories are protected.
 *
 * Important: is_locked is site admin only
 * - Tenants cannot set or change is_locked
 * - Only site administrators can manage lock status
 * - This field is hidden from tenant UI and rejected in validation
 */
class CategoryService
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Check if tenant can create a category for a brand.
     */
    public function canCreate(Tenant $tenant, Brand $brand): bool
    {
        return $this->planService->canCreateCategory($tenant, $brand);
    }

    /**
     * Create a category with plan check.
     *
     * Creates a custom (non-system) category for a brand.
     * System categories should only be created via SystemCategorySeeder.
     *
     * @param Tenant $tenant The tenant/company
     * @param Brand $brand The brand to create the category for
     * @param array $data Category data (name, slug, asset_type, is_private, etc.)
     * @return Category The created category
     * @throws PlanLimitExceededException If plan limit is exceeded
     */
    public function create(Tenant $tenant, Brand $brand, array $data): Category
    {
        // Check plan limit
        $this->planService->checkLimit('categories', $tenant, $brand);

        // Generate slug if not provided
        if (! isset($data['slug']) || empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Ensure slug is unique within tenant/brand/asset_type scope
        // Check including soft-deleted records to avoid unique constraint violations
        $baseSlug = $data['slug'];
        $slug = $baseSlug;
        $counter = 1;
        while (Category::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', $data['asset_type'])
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        $data['slug'] = $slug;

        // Set required fields
        $data['tenant_id'] = $tenant->id;
        $data['brand_id'] = $brand->id;
        $data['is_system'] = false; // User-created categories are never system
        $data['is_locked'] = false; // User-created categories are never locked (is_locked is site admin only)
        $data['is_hidden'] = $data['is_hidden'] ?? false; // Default to visible
        
        // Prevent tenants from setting is_locked (site admin only)
        if (isset($data['is_locked']) && $data['is_locked'] === true) {
            throw new \Exception('Lock status can only be managed by site administrators.');
        }
        
        // Validate private category requirements
        $isPrivate = $data['is_private'] ?? false;
        if ($isPrivate) {
            // Only custom categories can be private
            if ($data['is_system'] ?? false) {
                throw new \Exception('System categories cannot be private.');
            }

            // Check plan allows private categories
            if (!$this->planService->canCreatePrivateCategory($tenant, $brand)) {
                $maxPrivate = $this->planService->getMaxPrivateCategories($tenant);
                if ($maxPrivate === 0) {
                    throw new \Exception('Private categories require Pro or Enterprise plan.');
                } else {
                    throw new PlanLimitExceededException(
                        'private_categories',
                        $brand->categories()->custom()->where('is_private', true)->count(),
                        $maxPrivate
                    );
                }
            }

            // Validate access rules are provided
            $accessRules = $data['access_rules'] ?? [];
            if (empty($accessRules)) {
                throw new \Exception('Private categories must have at least one access rule (role or user).');
            }
        }
        
        // Auto-assign icon if not provided
        if (!isset($data['icon']) || empty($data['icon'])) {
            $data['icon'] = \App\Helpers\CategoryIcons::getDefaultIcon($data['name'], $data['slug']);
        }

        // Remove access_rules from data before creating category
        $accessRules = $data['access_rules'] ?? [];
        unset($data['access_rules']);

        $category = Category::create($data);

        // Apply minimal field visibility for custom categories: only collection and tags enabled
        try {
            app(\App\Services\TenantMetadataVisibilityService::class)
                ->applyMinimalDefaultsForCustomCategory($tenant, $category);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('CategoryService: failed to apply minimal defaults for new custom category', [
                'category_id' => $category->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Create access rules if category is private
        if ($isPrivate && !empty($accessRules)) {
            $this->syncAccessRules($category, $accessRules);
        }

        return $category;
    }

    /**
     * Update a category.
     *
     * Updates a custom category. System categories can only be updated if plan allows.
     *
     * @param Category $category The category to update
     * @param array $data Updated data
     * @return Category The updated category
     * @throws \Exception If category is locked or system (without plan permission)
     */
    public function update(Category $category, array $data): Category
    {
        // System categories can only be updated if plan has edit_system_categories feature
        if ($category->is_system) {
            $tenant = $category->tenant;
            if (! $this->planService->hasFeature($tenant, 'edit_system_categories')) {
                throw new \Exception('Cannot update system categories. Upgrade to Pro or Enterprise plan to edit system categories.');
            }
            
            // System categories are immutable - only allow hide changes
            // is_locked is site admin only and cannot be changed by tenants
            // Prevent changing name, slug, icon, or other immutable fields
            $allowedFieldsForSystem = ['is_hidden'];
            $requestedFields = array_keys($data);
            $disallowedFields = array_diff($requestedFields, $allowedFieldsForSystem);
            
            if (!empty($disallowedFields)) {
                throw new \Exception('System categories are immutable. Only hide settings can be changed. Lock status is managed by site administrators only.');
            }
            
            // Explicitly prevent tenants from setting is_locked
            if (isset($data['is_locked'])) {
                throw new \Exception('Lock status can only be managed by site administrators.');
            }
        }

        // For system categories with edit permission, allow hide updates even if locked
        // is_locked cannot be changed by tenants (site admin only)
        // For custom locked categories, only allow is_hidden updates
        if ($category->is_locked) {
            if ($category->is_system && $this->planService->hasFeature($category->tenant, 'edit_system_categories')) {
                // System categories: only allow is_hidden updates (is_locked is site admin only)
                $allowedFieldsForLockedSystem = ['is_hidden'];
                $isUpdatingOnlyAllowedFields = empty(array_diff(array_keys($data), $allowedFieldsForLockedSystem));
                if (!$isUpdatingOnlyAllowedFields) {
                    throw new \Exception('Cannot update locked system categories. Only hide settings can be changed. Lock status is managed by site administrators only.');
                }
            } else {
                // Custom locked categories: only allow is_hidden updates
                $allowedFieldsForLocked = ['is_hidden'];
                $isUpdatingOnlyAllowedFields = empty(array_diff(array_keys($data), $allowedFieldsForLocked));
                if (!$isUpdatingOnlyAllowedFields) {
                    throw new \Exception('Cannot update locked categories.');
                }
            }
        }

        // Prevent changing is_system flag
        if (isset($data['is_system'])) {
            unset($data['is_system']);
        }
        
        // Prevent tenants from setting is_locked (site admin only)
        // This check applies to both system and custom categories
        if (isset($data['is_locked'])) {
            throw new \Exception('Lock status can only be managed by site administrators.');
        }

        // Validate private category requirements if is_private is being set/changed
        $isPrivate = $data['is_private'] ?? $category->is_private;
        $wasPrivate = $category->is_private;
        $isChangingToPrivate = !$wasPrivate && $isPrivate;
        $isChangingFromPrivate = $wasPrivate && !$isPrivate;

        // Validate private category requirements
        if ($isPrivate) {
            // Only custom categories can be private
            if ($category->is_system) {
                throw new \Exception('System categories cannot be private.');
            }

            // Check plan allows private categories (only when changing to private)
            if ($isChangingToPrivate) {
                $tenant = $category->tenant;
                $brand = $category->brand;
                if (!$this->planService->canCreatePrivateCategory($tenant, $brand)) {
                    $maxPrivate = $this->planService->getMaxPrivateCategories($tenant);
                    if ($maxPrivate === 0) {
                        throw new \Exception('Private categories require Pro or Enterprise plan.');
                    } else {
                        throw new PlanLimitExceededException(
                            'private_categories',
                            $brand->categories()->custom()->where('is_private', true)->count(),
                            $maxPrivate
                        );
                    }
                }
            }

            // Validate access rules are provided (when setting to private or keeping it private)
            $accessRules = $data['access_rules'] ?? null;
            // If explicitly setting to private with empty access rules, validate
            if ($isChangingToPrivate && (empty($accessRules) || $accessRules === [])) {
                throw new \Exception('Private categories must have at least one access rule (role or user).');
            }
            // If updating an existing private category and access_rules is explicitly provided as empty, validate
            if ($wasPrivate && $accessRules !== null && empty($accessRules)) {
                throw new \Exception('Private categories must have at least one access rule (role or user).');
            }
        }

        // Generate slug if name changed and slug not provided
        if (isset($data['name']) && (! isset($data['slug']) || empty($data['slug']))) {
            $data['slug'] = Str::slug($data['name']);

            // Ensure slug is unique within tenant/brand/asset_type scope (excluding current category)
            $baseSlug = $data['slug'];
            $slug = $baseSlug;
            $counter = 1;
            while (Category::where('tenant_id', $category->tenant_id)
                ->where('brand_id', $category->brand_id)
                ->where('asset_type', $category->asset_type)
                ->where('slug', $slug)
                ->where('id', '!=', $category->id)
                ->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $data['slug'] = $slug;
        }

        // Extract access rules before updating
        $accessRules = $data['access_rules'] ?? null;
        unset($data['access_rules']);

        $category->update($data);

        // Sync access rules if provided or if changing to/from private
        if ($accessRules !== null || $isChangingToPrivate || $isChangingFromPrivate) {
            if ($isChangingFromPrivate) {
                // Remove all access rules if changing from private
                $category->accessRules()->delete();
            } elseif ($isPrivate) {
                // If access_rules is provided, sync them
                if ($accessRules !== null) {
                    if (empty($accessRules)) {
                        // If explicitly set to empty array and category is private, remove rules
                        // But this should have been caught by validation above
                        $category->accessRules()->delete();
                    } else {
                        $this->syncAccessRules($category, $accessRules);
                    }
                }
                // If access_rules is null and category stays private, keep existing rules (no change)
            }
        }

        return $category->fresh();
    }

    /**
     * Delete a category (soft delete with versioning).
     *
     * Soft deletes a custom category by setting deleted_at timestamp.
     * System categories can only be deleted if their system template no longer exists.
     * This allows for potential restoration and maintains version history.
     *
     * @param Category $category The category to delete
     * @return void
     * @throws \Exception If category is locked or system (and template still exists)
     */
    public function delete(Category $category): void
    {
        // Use the category's helper method to check if it can be deleted
        if (!$category->canBeDeleted()) {
            if ($category->is_system && $category->systemTemplateExists()) {
                throw new \Exception('Cannot delete system categories while the template exists.');
            }
            if ($category->is_locked && !$category->is_system) {
                throw new \Exception('Cannot delete locked categories.');
            }
            throw new \Exception('This category cannot be deleted.');
        }

        // Soft delete the category (sets deleted_at timestamp)
        $category->delete();
    }

    /**
     * Toggle the hidden state of a category.
     *
     * Allows hiding/showing categories. System categories can be hidden
     * but this should be plan/permission gated in the controller.
     *
     * @param Category $category The category to toggle
     * @param bool $hidden Whether to hide or show the category
     * @return Category The updated category
     * @throws \Exception If category is locked and trying to change hidden state
     */
    public function setHidden(Category $category, bool $hidden): Category
    {
        // System categories can be hidden, but locked categories cannot be modified
        if ($category->is_locked && $category->is_hidden !== $hidden) {
            throw new \Exception('Cannot change hidden state of locked categories.');
        }

        $category->update(['is_hidden' => $hidden]);

        return $category->fresh();
    }

    /**
     * Sync access rules for a category.
     *
     * @param Category $category
     * @param array $accessRules Array of access rules: [['type' => 'role', 'role' => 'admin'], ['type' => 'user', 'user_id' => 1]]
     * @return void
     */
    public function syncAccessRules(Category $category, array $accessRules): void
    {
        // Get existing rules for logging
        $oldRules = $category->accessRules()->get()->map(function ($rule) {
            if ($rule->access_type === 'role') {
                return ['type' => 'role', 'role' => $rule->role];
            } elseif ($rule->access_type === 'user') {
                return ['type' => 'user', 'user_id' => $rule->user_id];
            }
            return null;
        })->filter()->values()->toArray();

        // Delete existing access rules
        $category->accessRules()->delete();

        // Create new access rules
        $newRules = [];
        foreach ($accessRules as $rule) {
            if ($rule['type'] === 'role' && !empty($rule['role'])) {
                CategoryAccess::create([
                    'category_id' => $category->id,
                    'brand_id' => $category->brand_id,
                    'access_type' => 'role',
                    'role' => $rule['role'],
                ]);
                $newRules[] = ['type' => 'role', 'role' => $rule['role']];
            } elseif ($rule['type'] === 'user' && !empty($rule['user_id'])) {
                CategoryAccess::create([
                    'category_id' => $category->id,
                    'brand_id' => $category->brand_id,
                    'access_type' => 'user',
                    'user_id' => $rule['user_id'],
                ]);
                $newRules[] = ['type' => 'user', 'user_id' => $rule['user_id']];
            }
        }

        // Log activity if rules changed
        if ($oldRules !== $newRules) {
            $user = Auth::user();
            ActivityRecorder::record(
                $category->tenant,
                EventType::CATEGORY_ACCESS_UPDATED,
                $category,
                $user,
                $category->brand,
                [
                    'roles' => array_filter($newRules, fn($r) => $r['type'] === 'role'),
                    'users' => array_filter($newRules, fn($r) => $r['type'] === 'user'),
                ]
            );
        }
    }
}
