<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Services\CategoryService;
use App\Services\CategoryUpgradeService;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use App\Traits\HandlesFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use HandlesFlashMessages;
    public function __construct(
        protected CategoryService $categoryService,
        protected PlanService $planService,
        protected SystemCategoryService $systemCategoryService,
        protected CategoryUpgradeService $categoryUpgradeService
    ) {
    }

    /**
     * Display a listing of categories.
     * DISABLED: Category management moved to brands pages
     *
     * Filters categories based on:
     * - Brand and tenant scope (always enforced)
     * - Asset type (optional filter)
     * - System vs custom (optional filter)
     * - Hidden categories (filtered unless user has permission)
     */
    /*
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        // Check if user has permission to manage brand categories
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            abort(403, 'Only administrators, owners, and brand managers can access category settings.');
        }

        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id);

        // Filter by asset type
        if ($request->has('asset_type') && $request->asset_type) {
            $query->where('asset_type', $request->asset_type);
        }

        // Filter by system/custom
        if ($request->has('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }

        // Filter out hidden categories unless user has permission to view them
        // For now, we'll show hidden categories to users with 'manage categories' permission
        // This can be refined with a specific permission later
        if (! $user || ! $user->can('manage categories')) {
            $query->visible(); // Only show non-hidden categories
        }

        $categories = $query->orderBy('order')->orderBy('name')->get();

        // Get system category templates and merge with existing categories
        // This ensures all brands see system categories even if they don't have them yet
        $systemTemplates = $this->systemCategoryService->getAllTemplates();
        
        // Filter templates by asset type if filter is applied
        if ($request->has('asset_type') && $request->asset_type) {
            $systemTemplates = $systemTemplates->filter(function ($template) use ($request) {
                return $template->asset_type->value === $request->asset_type;
            });
        }

        // Create a collection of all categories (existing + templates that don't exist yet)
        $allCategories = $categories->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'icon' => $category->icon,
            'asset_type' => $category->asset_type->value,
            'is_system' => $category->is_system,
            'is_private' => $category->is_private,
            'is_locked' => $category->is_locked,
            'is_hidden' => $category->is_hidden,
            'order' => $category->order ?? 0,
            'is_template' => false, // Existing category
            'upgrade_available' => $category->upgrade_available ?? false,
            'system_version' => $category->system_version,
        ]);

        // Add system templates that don't have matching brand categories
        foreach ($systemTemplates as $template) {
            // Check if brand already has a category with this slug and asset_type
            $exists = $categories->contains(function ($category) use ($template) {
                return $category->slug === $template->slug && 
                       $category->asset_type->value === $template->asset_type->value;
            });

            if (! $exists) {
                // Add template as a virtual category (no ID, marked as template)
                $allCategories->push([
                    'id' => null, // No ID for templates
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'icon' => $template->icon ?? 'folder',
                    'asset_type' => $template->asset_type->value,
                    'is_system' => true,
                    'is_private' => $template->is_private,
                    'is_locked' => true, // Templates are locked
                    'is_hidden' => $template->is_hidden,
                    'is_template' => true, // This is a template, not an existing category
                    'upgrade_available' => false,
                    'system_version' => null,
                ]);
            }
        }

        // Count only custom (non-system) categories for plan limits
        $limits = $this->planService->getPlanLimits($tenant);
        $currentCount = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('is_system', false) // Only count custom categories
            ->count();
        $canCreate = $this->categoryService->canCreate($tenant, $brand);
        
        // Get plan information
        $currentPlan = $this->planService->getCurrentPlan($tenant);
        $planFeatures = $this->planService->getPlanFeatures($tenant);
        $canEditSystemCategories = $this->planService->hasFeature($tenant, 'edit_system_categories');

        return Inertia::render('Categories/Index', [
            'categories' => $allCategories->values(),
            'filters' => [
                'asset_type' => $request->asset_type,
                'is_system' => $request->is_system,
            ],
            'limits' => [
                'current' => $currentCount,
                'max' => $limits['max_categories'],
                'can_create' => $canCreate,
            ],
            'asset_types' => [
                ['value' => AssetType::ASSET->value, 'label' => 'Asset'],
                ['value' => AssetType::MARKETING->value, 'label' => 'Marketing'],
            ],
            'plan' => [
                'name' => $currentPlan,
                'features' => $planFeatures,
                'can_edit_system_categories' => $canEditSystemCategories,
            ],
        ]);
    }
    */

    /**
     * Store a newly created category.
     */
    public function store(Request $request, Brand $brand)
    {
        $tenant = app('tenant');
        $user = $request->user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check if user has permission to manage brand categories
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            abort(403, 'Only administrators, owners, and brand managers can create categories.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:255',
            'asset_type' => 'required|string|in:' . implode(',', array_column(AssetType::cases(), 'value')),
            'is_private' => 'nullable|boolean',
            'access_rules' => 'nullable|array',
            'access_rules.*.type' => 'required|string|in:role,user',
            'access_rules.*.role' => 'required_if:access_rules.*.type,role|nullable|string',
            'access_rules.*.user_id' => 'required_if:access_rules.*.type,user|nullable|integer|exists:users,id',
        ]);

        $validated['asset_type'] = AssetType::from($validated['asset_type']);

        try {
            $category = $this->categoryService->create($tenant, $brand, $validated);

            return redirect()->route('brands.edit', $brand)->with('success', 'Category created successfully.');
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return back()->withErrors([
                'plan_limit' => $e->getMessage(),
            ])->onlyInput('name', 'slug');
        }
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, Brand $brand, Category $category)
    {
        $tenant = app('tenant');
        $user = $request->user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Verify category belongs to tenant/brand
        if ($category->tenant_id !== $tenant->id) {
            abort(403, 'Category does not belong to this tenant.');
        }

        if ($category->brand_id !== $brand->id) {
            abort(403, 'Category does not belong to this brand.');
        }

        // Check if user has admin/owner role or manage categories/manage brands permission - using policy
        $this->authorize('update', $category);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:255',
            'is_private' => 'nullable|boolean',
            'access_rules' => 'nullable|array',
            'access_rules.*.type' => 'required|string|in:role,user',
            'access_rules.*.role' => 'required_if:access_rules.*.type,role|nullable|string',
            'access_rules.*.user_id' => 'required_if:access_rules.*.type,user|nullable|integer|exists:users,id',
        ]);

        try {
            $this->categoryService->update($category, $validated);

            return redirect()->route('brands.edit', $brand)->with('success', 'Category updated successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ])->onlyInput('name', 'slug');
        }
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Brand $brand, Category $category)
    {
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Verify category belongs to tenant/brand
        if ($category->tenant_id !== $tenant->id) {
            abort(403, 'Category does not belong to this tenant.');
        }

        if ($category->brand_id !== $brand->id) {
            abort(403, 'Category does not belong to this brand.');
        }

        // Check if category can be deleted (business logic check)
        if (!$category->canBeDeleted()) {
            if ($category->is_system && $category->systemTemplateExists()) {
                return back()->withErrors([
                    'error' => 'Cannot delete system categories while the template exists.',
                ]);
            }
            // Note: Locked system categories with deleted templates CAN be deleted
            // The canBeDeleted() method handles this logic
            return back()->withErrors([
                'error' => 'This category cannot be deleted.',
            ]);
        }

        // Check if user has admin/owner role or manage categories/manage brands permission - using policy
        $this->authorize('delete', $category);

        try {
            $this->categoryService->delete($category);

            return redirect()->route('brands.edit', $brand)->with('success', 'Category deleted successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the order of categories.
     */
    public function updateOrder(Request $request, Brand $brand)
    {
        $tenant = app('tenant');
        $user = $request->user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check if user has permission to manage brand categories
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            abort(403, 'Only administrators, owners, and brand managers can reorder categories.');
        }

        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|integer|exists:categories,id',
            'categories.*.order' => 'required|integer',
        ]);

        foreach ($validated['categories'] as $item) {
            $category = Category::find($item['id']);
            
            // Verify category belongs to tenant/brand
            if ($category && $category->tenant_id === $tenant->id && $category->brand_id === $brand->id) {
                $category->update(['order' => $item['order']]);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Preview what changes would be applied if upgrading a category.
     */
    public function previewUpgrade(Request $request, Brand $brand, Category $category)
    {
        $tenant = app('tenant');
        $user = $request->user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Verify category belongs to tenant/brand
        if ($category->tenant_id !== $tenant->id) {
            abort(403, 'Category does not belong to this tenant.');
        }

        if ($category->brand_id !== $brand->id) {
            abort(403, 'Category does not belong to this brand.');
        }

        // Check if user has permission to manage brand categories
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            abort(403, 'Only administrators, owners, and brand managers can preview category upgrades.');
        }

        try {
            $preview = $this->categoryUpgradeService->previewUpgrade($category);
            return response()->json($preview);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Add a system category template to a brand.
     */
    public function addSystemTemplate(Request $request, Brand $brand)
    {
        $tenant = app('tenant');
        $user = $request->user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check if user has permission to manage brand categories
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            abort(403, 'Only administrators, owners, and brand managers can add system categories.');
        }

        $validated = $request->validate([
            'system_category_id' => 'required|integer|exists:system_categories,id',
        ]);

        try {
            $systemCategory = \App\Models\SystemCategory::findOrFail($validated['system_category_id']);
            
            // Verify this is the latest version
            if (!$systemCategory->isLatestVersion()) {
                // Get the latest version instead
                $systemCategory = $systemCategory->getLatestVersion();
            }

            // Add the template to the brand
            $category = $this->systemCategoryService->addTemplateToBrand($brand, $systemCategory);

            if (!$category) {
                return back()->withErrors([
                    'error' => 'This system category already exists for this brand.',
                ]);
            }

            return redirect()->route('brands.edit', $brand)->with('success', 'System category added successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply an upgrade to a category.
     */
    public function applyUpgrade(Request $request, Brand $brand, Category $category)
    {
        $tenant = app('tenant');
        $user = $request->user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Verify category belongs to tenant/brand
        if ($category->tenant_id !== $tenant->id) {
            abort(403, 'Category does not belong to this tenant.');
        }

        if ($category->brand_id !== $brand->id) {
            abort(403, 'Category does not belong to this brand.');
        }

        // Check if user has permission to manage brand categories
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => 'Only administrators, owners, and brand managers can upgrade categories.',
                ], 403);
            }
            abort(403, 'Only administrators, owners, and brand managers can upgrade categories.');
        }

        try {
            $validated = $request->validate([
                'approved_fields' => 'nullable|array',
                'approved_fields.*' => 'string|in:name,icon,is_private,is_hidden',
            ]);
            
            // Ensure approved_fields is always an array (even if empty)
            $validated['approved_fields'] = $validated['approved_fields'] ?? [];
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => 'Validation failed: ' . implode(', ', array_merge(...array_values($e->errors()))),
                ], 422);
            }
            throw $e;
        }

        try {
            $this->categoryUpgradeService->applyUpgrade($category, $validated['approved_fields']);

            // Return JSON for AJAX requests, redirect for regular requests
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => true,
                    'message' => 'Category upgraded successfully.',
                ]);
            }

            return redirect()->route('brands.edit', $brand)->with('success', 'Category upgraded successfully.');
        } catch (\Exception $e) {
            // Return JSON for AJAX requests, redirect for regular requests
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => $e->getMessage(),
                ], 400);
            }

            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Accept deletion of a category marked for deletion.
     */
    public function acceptDeletion(Request $request, Brand $brand, Category $category)
    {
        $tenant = app('tenant');
        $user = $request->user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Verify category belongs to tenant/brand
        if ($category->tenant_id !== $tenant->id) {
            abort(403, 'Category does not belong to this tenant.');
        }

        if ($category->brand_id !== $brand->id) {
            abort(403, 'Category does not belong to this brand.');
        }

        // Check if user has permission to manage brand categories
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => 'Only administrators, owners, and brand managers can accept category deletions.',
                ], 403);
            }
            abort(403, 'Only administrators, owners, and brand managers can accept category deletions.');
        }

        try {
            $this->categoryUpgradeService->acceptDeletion($category);

            // Check if this is an Inertia request
            if ($request->header('X-Inertia')) {
                return back()->with('success', 'Category deleted successfully.');
            }

            // Return JSON for non-Inertia AJAX requests
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => true,
                    'message' => 'Category deleted successfully.',
                ]);
            }

            return redirect()->route('brands.edit', $brand)->with('success', 'Category deleted successfully.');
        } catch (\Exception $e) {
            // Check if this is an Inertia request
            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'error' => $e->getMessage(),
                ]);
            }

            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => $e->getMessage(),
                ], 400);
            }

            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }
    }
}
