<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Services\CategoryService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService,
        protected PlanService $planService
    ) {
    }

    /**
     * Display a listing of categories.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');

        $query = Category::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)
                    ->orWhereNull('brand_id'); // Include company-wide categories
            });

        // Filter by asset type
        if ($request->has('asset_type') && $request->asset_type) {
            $query->where('asset_type', $request->asset_type);
        }

        // Filter by system/custom
        if ($request->has('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }

        $categories = $query->orderBy('name')->get();

        $limits = $this->planService->getPlanLimits($tenant);
        $currentCount = Category::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)
                    ->orWhereNull('brand_id');
            })
            ->count();
        $canCreate = $this->categoryService->canCreate($tenant, $brand);

        return Inertia::render('Categories/Index', [
            'categories' => $categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'asset_type' => $category->asset_type->value,
                'is_system' => $category->is_system,
                'is_private' => $category->is_private,
                'is_locked' => $category->is_locked,
            ]),
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
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        $tenant = app('tenant');
        $brand = app('brand');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'asset_type' => 'required|string|in:' . implode(',', AssetType::values()),
            'is_private' => 'nullable|boolean',
        ]);

        $validated['asset_type'] = AssetType::from($validated['asset_type']);

        try {
            $category = $this->categoryService->create($tenant, $brand, $validated);

            return redirect()->route('categories.index')->with('success', 'Category created successfully.');
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

        // Verify category belongs to tenant/brand
        if ($category->tenant_id !== $tenant->id) {
            abort(403, 'Category does not belong to this tenant.');
        }

        if ($category->brand_id && $category->brand_id !== $brand->id) {
            abort(403, 'Category does not belong to this brand.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
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

        if ($category->brand_id && $category->brand_id !== $brand->id) {
            abort(403, 'Category does not belong to this brand.');
        }

        try {
            $this->categoryService->delete($category);

            return redirect()->route('categories.index')->with('success', 'Category deleted successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }
    }
}
