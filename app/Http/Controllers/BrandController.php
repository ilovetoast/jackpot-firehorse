<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Services\BrandService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function __construct(
        protected BrandService $brandService,
        protected PlanService $planService
    ) {
    }

    /**
     * Display a listing of brands.
     */
    public function index(): Response
    {
        $tenant = app('tenant');
        $brands = $tenant->brands;
        $limits = $this->planService->getPlanLimits($tenant);
        $currentCount = $brands->count();
        $canCreate = $this->brandService->canCreate($tenant);

        return Inertia::render('Brands/Index', [
            'brands' => $brands->map(fn ($brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'is_default' => $brand->is_default,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'settings' => $brand->settings,
            ]),
            'limits' => [
                'current' => $currentCount,
                'max' => $limits['max_brands'],
                'can_create' => $canCreate,
            ],
        ]);
    }

    /**
     * Show the form for creating a new brand.
     */
    public function create(): Response
    {
        $tenant = app('tenant');
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
        $tenant = app('tenant');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'logo_path' => 'nullable|string|max:255',
            'primary_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'accent_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'settings' => 'nullable|array',
        ]);

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
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
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

        return Inertia::render('Brands/Edit', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'is_default' => $brand->is_default,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'settings' => $brand->settings,
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

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'logo_path' => 'nullable|string|max:255',
        ]);

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
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
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
