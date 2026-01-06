<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Services\BrandService;
use App\Services\CategoryService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function __construct(
        protected BrandService $brandService,
        protected PlanService $planService,
        protected CategoryService $categoryService
    ) {
    }

    /**
     * Display a listing of brands.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Check if user has permission to view brand settings
        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to view brand settings.');
        }

        // If user has brand_settings.manage permission, they can see all brands for the tenant
        $brands = $tenant->brands;

        $limits = $this->planService->getPlanLimits($tenant);
        $currentCount = $brands->count();
        $canCreate = $this->brandService->canCreate($tenant);

        return Inertia::render('Brands/Index', [
            'brands' => $brands->load(['categories', 'users'])->map(fn ($brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'is_default' => $brand->is_default,
                'show_in_selector' => $brand->show_in_selector ?? true,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'settings' => $brand->settings,
                'categories' => $brand->categories->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'asset_type' => $category->asset_type->value,
                    'is_system' => $category->is_system,
                    'is_private' => $category->is_private,
                    'is_locked' => $category->is_locked,
                ]),
                'users' => $brand->users->map(fn ($user) => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                ]),
            ]),
            'limits' => [
                'current' => $currentCount,
                'max' => $limits['max_brands'],
                'can_create' => $canCreate,
            ],
        ]);
    }

    /**
     * Switch to a different brand.
     */
    public function switch(Request $request, Brand $brand)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Verify user has access to this brand (via brand_user pivot table)
        if (! $user->brands()->where('brands.id', $brand->id)->exists()) {
            abort(403, 'You do not have access to this brand.');
        }

        session(['brand_id' => $brand->id]);

        return redirect()->intended('/app/dashboard');
    }

    /**
     * Show the form for creating a new brand.
     */
    public function create(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Check if user has permission to manage brand settings
        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to create brands.');
        }

        $canCreate = $this->brandService->canCreate($tenant);

        if (! $canCreate) {
            return redirect()->route('brands.index')->withErrors([
                'plan_limit' => 'You have reached the maximum number of brands for your plan.',
            ]);
        }

        return Inertia::render('Brands/Create');
    }

    /**
     * Store a newly created brand.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Check if user has permission to manage brand settings
        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to create brands.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'logo' => 'nullable|mimes:png,webp,svg,avif|max:2048',
            'primary_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'accent_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'nav_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo_filter' => 'nullable|string|in:none,white,black',
            'settings' => 'nullable|array',
        ]);

        // Handle logo file upload
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store("brands/{$tenant->id}", 'public');
            $validated['logo_path'] = Storage::url($logoPath);
            unset($validated['logo']); // Remove the file from validated data
        } else {
            // No logo uploaded, set to null
            $validated['logo_path'] = null;
        }

        try {
            $brand = $this->brandService->create($tenant, $validated);

            return redirect()->route('brands.index')->with('success', 'Brand created successfully.');
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return back()->withErrors([
                'plan_limit' => $e->getMessage(),
            ])->onlyInput('name', 'slug');
        }
    }

    /**
     * Display the specified brand.
     */
    public function show(Brand $brand): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Verify user has access to this brand (via brand_user pivot table)
        if (! $user->brands()->where('brands.id', $brand->id)->exists()) {
            abort(403, 'You do not have access to this brand.');
        }

        return Inertia::render('Brands/Show', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'is_default' => $brand->is_default,
            ],
        ]);
    }

    /**
     * Show the form for editing the specified brand.
     */
    public function edit(Brand $brand): Response
    {
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check if user has admin or owner role (not member) - using policy check
        // The policy checks for 'manage brands' permission which only admin and owner have
        $this->authorize('update', $brand);

        // Get categories for this brand
        $categories = $brand->categories()->orderBy('asset_type')->orderBy('name')->get();

        // Get plan limits for categories
        $limits = $this->planService->getPlanLimits($tenant);
        // Only count custom (non-system) categories against the limit
        $currentCategoryCount = $brand->categories()->custom()->count();
        $canCreateCategory = $this->categoryService->canCreate($tenant, $brand);

        return Inertia::render('Brands/Edit', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'is_default' => $brand->is_default,
                'show_in_selector' => $brand->show_in_selector ?? true,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'nav_color' => $brand->nav_color,
                'logo_filter' => $brand->logo_filter ?? 'none',
                'settings' => $brand->settings,
            ],
            'categories' => $categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'asset_type' => $category->asset_type->value,
                'is_system' => $category->is_system,
                'is_private' => $category->is_private,
                'is_locked' => $category->is_locked,
            ]),
            'category_limits' => [
                'current' => $currentCategoryCount,
                'max' => $limits['max_categories'],
                'can_create' => $canCreateCategory,
            ],
        ]);
    }

    /**
     * Update the specified brand.
     */
    public function update(Request $request, Brand $brand)
    {
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check if user has admin or owner role (not member) - using policy check
        $this->authorize('update', $brand);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'logo' => 'nullable|mimes:png,webp,svg,avif|max:2048',
            'show_in_selector' => 'nullable|boolean',
            'primary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'accent_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'nav_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'logo_filter' => 'nullable|string|in:none,white,black',
            'settings' => 'nullable|array',
        ]);

        // Handle logo file upload
        if ($request->hasFile('logo')) {
            // Delete old logo if it exists and is stored locally
            if ($brand->logo_path && str_starts_with($brand->logo_path, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $brand->logo_path);
                Storage::disk('public')->delete($oldPath);
            }
            
            $logoPath = $request->file('logo')->store("brands/{$brand->tenant_id}", 'public');
            $validated['logo_path'] = Storage::url($logoPath);
            unset($validated['logo']); // Remove the file from validated data
        } else {
            // Keep existing logo_path if no new file is uploaded
            $validated['logo_path'] = $brand->logo_path;
        }

        try {
            $this->brandService->update($brand, $validated);

            return redirect()->route('brands.index')->with('success', 'Brand updated successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ])->onlyInput('name', 'slug');
        }
    }

    /**
     * Remove the specified brand.
     */
    public function destroy(Brand $brand)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check if user has permission to manage brand settings
        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to delete brands.');
        }

        try {
            $this->brandService->delete($brand);

            return redirect()->route('brands.index')->with('success', 'Brand deleted successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }
    }
}
