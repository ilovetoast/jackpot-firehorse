<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Services\CategoryService;
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
        protected SystemCategoryService $systemCategoryService
    ) {
    }

    /**
     * Display a listing of categories.
     *
     * Filters categories based on:
     * - Brand and tenant scope (always enforced)
     * - Asset type (optional filter)
     * - System vs custom (optional filter)
     * - Hidden categories (filtered unless user has permission)
     */
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
                ['value' => AssetType::BASIC->value, 'label' => 'Basic'],
                ['value' => AssetType::MARKETING->value, 'label' => 'Marketing'],
            ],
            'plan' => [
                'name' => $currentPlan,
                'features' => $planFeatures,
                'can_edit_system_categories' => $canEditSystemCategories,
            ],
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        // Check if user has permission to manage brand categories
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            abort(403, 'Only administrators, owners, and brand managers can create categories.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:255',
            'asset_type' => 'required|string|in:' . implode(',', AssetType::values()),
            'is_private' => 'nullable|boolean',
        ]);

        $validated['asset_type'] = AssetType::from($validated['asset_type']);

        try {
            $category = $this->categoryService->create($tenant, $brand, $validated);

            return $this->redirectWithSuccess('categories.index', 'Category created successfully.');
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return back()->withErrors([
                'plan_limit' => $e->getMessage(),
            ])->onlyInput('name', 'slug');
        }
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, Category $category)
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

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
        ]);

        try {
            $this->categoryService->update($category, $validated);

            return redirect()->route('categories.index')->with('success', 'Category updated successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ])->onlyInput('name', 'slug');
        }
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Category $category)
    {
        $tenant = app('tenant');
        $brand = app('brand');

        // Verify category belongs to tenant/brand
        if ($category->tenant_id !== $tenant->id) {
            abort(403, 'Category does not belong to this tenant.');
        }

        if ($category->brand_id !== $brand->id) {
            abort(403, 'Category does not belong to this brand.');
        }

        // Check if user has admin/owner role or manage categories/manage brands permission - using policy
        $this->authorize('delete', $category);

        try {
            $this->categoryService->delete($category);

            return $this->redirectWithSuccess('categories.index', 'Category deleted successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the order of categories.
     */
    public function updateOrder(Request $request)
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

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
}
