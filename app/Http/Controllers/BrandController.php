<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Mail\InviteMember;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CollectionUser;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\AssetEligibilityService;
use App\Services\BrandService;
use App\Services\CategoryService;
use App\Services\FeatureGate;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use App\Support\Roles\PermissionMap;
use App\Support\Roles\RoleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function __construct(
        protected BrandService $brandService,
        protected PlanService $planService,
        protected CategoryService $categoryService,
        protected SystemCategoryService $systemCategoryService,
        protected AssetEligibilityService $assetEligibilityService
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
        $maxBrands = $limits['max_brands'] ?? PHP_INT_MAX;
        $brandLimitExceeded = $currentCount > $maxBrands;
        $canCreate = $this->brandService->canCreate($tenant);

        // Get available users (company users not yet on each brand) and pending invitations
        $tenantUsers = $tenant->users;
        
        // Order brands: default first, then by name (same as in HandleInertiaRequests)
        // Phase MI-1: Load users but we'll filter to active memberships in the map
        $orderedBrands = $brands->load(['categories', 'invitations'])
            ->sortBy([['is_default', 'desc'], ['name', 'asc']])
            ->values();
        
        return Inertia::render('Brands/Index', [
            'brands' => $orderedBrands->map(function ($brand, $index) use ($tenantUsers, $maxBrands, $brandLimitExceeded) {
                // Mark brands beyond plan limit as disabled
                // Index is 0-based, so index >= maxBrands means it's beyond the limit
                $isDisabled = $brandLimitExceeded && ($index >= $maxBrands);
                
                // Phase MI-1: Get users with active membership only
                $assignedUserIds = $brand->users()
                    ->wherePivotNull('removed_at')
                    ->pluck('users.id')
                    ->toArray();
                $availableUsers = $tenantUsers->reject(fn ($user) => in_array($user->id, $assignedUserIds))->map(fn ($user) => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ]);
                
                // Get pending invitations
                $pendingInvitations = $brand->invitations()->whereNull('accepted_at')->get()->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'sent_at' => $invitation->sent_at?->toISOString(),
                    'created_at' => $invitation->created_at->toISOString(),
                ]);
                
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'logo_path' => $brand->logo_path,
                    'icon_path' => $brand->icon_path,
                    'icon' => $brand->icon,
                    'icon_bg_color' => $brand->icon_bg_color,
                    'is_default' => $brand->is_default,
                    'show_in_selector' => $brand->show_in_selector ?? true,
                    'is_disabled' => $isDisabled, // Mark as disabled if beyond plan limit
                    'primary_color' => $brand->primary_color,
                    'secondary_color' => $brand->secondary_color,
                    'accent_color' => $brand->accent_color,
                    'settings' => $brand->settings,
                    'categories' => $brand->categories->map(fn ($category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'icon' => $category->icon,
                        'asset_type' => $category->asset_type->value,
                        'is_system' => $category->is_system,
                        'is_private' => $category->is_private,
                        'is_locked' => $category->is_locked,
                        'upgrade_available' => $category->upgrade_available ?? false,
                        'system_version' => $category->system_version,
                    ]),
                    // Phase MI-1: Filter to only active memberships (removed_at IS NULL)
                    'users' => $brand->users()
                        ->wherePivotNull('removed_at')
                        ->get()
                        ->map(fn ($user) => [
                            'id' => $user->id,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'name' => $user->name,
                            'email' => $user->email,
                            'avatar_url' => $user->avatar_url,
                            'role' => $user->pivot->role,
                        ]),
                    'available_users' => $availableUsers->values(),
                    'pending_invitations' => $pendingInvitations,
                ];
            }),
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

        // Check plan limits - prevent switching to disabled brands even for admins
        $planService = app(PlanService::class);
        $limits = $planService->getPlanLimits($tenant);
        $currentBrandCount = $tenant->brands()->count();
        $maxBrands = $limits['max_brands'] ?? PHP_INT_MAX;
        $brandLimitExceeded = $currentBrandCount > $maxBrands;

        if ($brandLimitExceeded) {
            // Get all brands for tenant ordered the same way as in HandleInertiaRequests
            $allBrands = $tenant->brands()
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();
            
            // Find the index of this brand
            $brandIndex = $allBrands->search(function ($b) use ($brand) {
                return $b->id === $brand->id;
            });
            
            // Check if this brand is beyond the limit
            $activeBrand = app('brand');
            $isActive = $activeBrand && $brand->id === $activeBrand->id;
            
            // Only allow if it's the active brand (can't switch away from active) or if index < maxBrands
            if (!$isActive && $brandIndex !== false && $brandIndex >= $maxBrands) {
                return back()->withErrors([
                    'brand' => "This brand is not accessible on your current plan. Your plan allows {$maxBrands} brand(s), but you have {$currentBrandCount}. Please upgrade your plan to access all brands.",
                ]);
            }
        }

        // Check if user is tenant owner/admin - they can switch to any accessible brand
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        // Verify user has access to this brand (via brand_user pivot table) OR is tenant owner/admin
        // Phase MI-1: Check active membership
        if (! $isTenantOwnerOrAdmin && ! $user->activeBrandMembership($brand)) {
            abort(403, 'You do not have access to this brand.');
        }

        // Update session with new brand
        session(['brand_id' => $brand->id]);

        // For Inertia requests, return back to allow client-side navigation without full page reload
        // The frontend will reload only the auth props to update activeBrand
        return back();
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
            'icon' => 'nullable|mimes:png,webp,svg,avif|max:2048',
            'icon_id' => 'nullable|string|max:255',
            'icon_bg_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'primary_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'accent_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'nav_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
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

        // Handle icon file upload
        if ($request->hasFile('icon')) {
            $iconPath = $request->file('icon')->store("brands/{$tenant->id}", 'public');
            $validated['icon_path'] = Storage::url($iconPath);
            unset($validated['icon']); // Remove the file from validated data
            // Clear icon when uploading a file
            $validated['icon'] = null;
        } else {
            // Keep existing icon_path if no new file is uploaded
            $validated['icon_path'] = null; // Will be set from existing brand if updating
        }
        
        // Handle icon_bg_color
        if ($request->has('icon_bg_color')) {
            $validated['icon_bg_color'] = $request->input('icon_bg_color') ?: null;
        }

        try {
            $brand = $this->brandService->create($tenant, $validated, $user);

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

        \Log::info('BrandController::show() called', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'brand_id' => $brand->id,
            'brand_name' => $brand->name,
            'tenant_id' => $tenant->id,
        ]);

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            \Log::warning('BrandController::show() - Brand does not belong to tenant', [
                'brand_id' => $brand->id,
                'brand_tenant_id' => $brand->tenant_id,
                'current_tenant_id' => $tenant->id,
            ]);
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Use policy to check access (policy handles owner/admin vs regular users)
        \Log::info('BrandController::show() - Calling authorize()', [
            'user_id' => $user->id,
            'brand_id' => $brand->id,
        ]);
        
        try {
            $this->authorize('view', $brand);
            \Log::info('BrandController::show() - Authorization passed', [
                'user_id' => $user->id,
                'brand_id' => $brand->id,
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            \Log::warning('BrandController::show() - Authorization failed', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return Inertia::render('Brands/Show', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'icon_path' => $brand->icon_path,
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
        $categories = $brand->categories()->orderBy('asset_type')->orderBy('order')->orderBy('name')->get();

        // Get system category templates and find ones that don't exist yet for this brand
        $systemTemplates = $this->systemCategoryService->getAllTemplates();
        $availableTemplates = collect();

        foreach ($systemTemplates as $template) {
            // Check if brand already has a category with this slug and asset_type
            $exists = $categories->contains(function ($category) use ($template) {
                return $category->slug === $template->slug && 
                       $category->asset_type->value === $template->asset_type->value;
            });

            if (! $exists) {
                // Add template as available for this brand
                $availableTemplates->push([
                    'id' => null, // No ID for templates
                    'system_category_id' => $template->id,
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'icon' => $template->icon ?? 'folder',
                    'asset_type' => $template->asset_type->value,
                    'is_system' => true,
                    'is_private' => $template->is_private,
                    'is_locked' => true, // Templates are locked
                    'is_hidden' => $template->is_hidden,
                    'is_template' => true, // This is a template, not an existing category
                    'system_version' => $template->version,
                ]);
            }
        }

        // Get plan limits for categories
        $limits = $this->planService->getPlanLimits($tenant);
        // Only count custom (non-system) categories against the limit
        $currentCategoryCount = $brand->categories()->custom()->count();
        $canCreateCategory = $this->categoryService->canCreate($tenant, $brand);

        // Phase MI-1: Get brand users with active membership only
        $brandUsers = $brand->users()
            ->wherePivotNull('removed_at')
            ->get()
            ->map(function ($user) use ($brand) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->getRoleForBrand($brand) ?? 'viewer',
                ];
            });

        // Get all valid brand roles dynamically (not hardcoded)
        // Show all valid brand roles so they can be assigned even if no users have them yet
        $brandRoles = \App\Models\User::getValidBrandRoles();

        // Get plan info for private categories
        $currentPlan = $this->planService->getCurrentPlan($tenant);
        $canCreatePrivateCategory = $this->planService->canCreatePrivateCategory($tenant, $brand);
        $maxPrivateCategories = $this->planService->getMaxPrivateCategories($tenant);
        $currentPrivateCount = $brand->categories()->custom()->where('is_private', true)->count();
        $canEditSystemCategories = $this->planService->hasFeature($tenant, 'edit_system_categories');

        // D10: Logo assets for download landing branding (category slug = logos)
        $logoCategory = Category::where('brand_id', $brand->id)->where('slug', 'logos')->first();
        $logoAssets = [];
        if ($logoCategory) {
            $logoAssets = Asset::where('brand_id', $brand->id)
                ->where('metadata->category_id', $logoCategory->id)
                ->get(['id', 'original_filename'])
                ->map(fn (Asset $a) => [
                    'id' => $a->id,
                    'thumbnail_url' => route('assets.thumbnail.final', ['asset' => $a->id, 'style' => 'thumb']),
                    'original_filename' => $a->original_filename ?? '',
                ])
                ->values()
                ->all();
        }

        // D10: Thumbnail URLs for selected background assets
        $brandSettings = $brand->download_landing_settings ?? [];
        $backgroundIds = $brandSettings['background_asset_ids'] ?? [];
        $backgroundAssetDetails = [];
        if (! empty($backgroundIds)) {
            $backgroundAssetDetails = Asset::where('brand_id', $brand->id)
                ->whereIn('id', $backgroundIds)
                ->get(['id', 'original_filename'])
                ->map(fn (Asset $a) => [
                    'id' => $a->id,
                    'thumbnail_url' => route('assets.thumbnail.final', ['asset' => $a->id, 'style' => 'thumb']),
                    'original_filename' => $a->original_filename ?? '',
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Brands/Edit', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'icon_path' => $brand->icon_path,
                'icon' => $brand->icon,
                'icon_bg_color' => $brand->icon_bg_color,
                'is_default' => $brand->is_default,
                'show_in_selector' => $brand->show_in_selector ?? true,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'nav_color' => $brand->nav_color,
                'logo_filter' => $brand->logo_filter ?? 'none',
                'settings' => $brand->settings,
                'download_landing_settings' => $brand->download_landing_settings ?? [],
                'logo_assets' => $logoAssets,
                'background_asset_details' => $backgroundAssetDetails,
            ],
            'categories' => $categories->map(function ($category) {
                // Get access rules for private categories
                $accessRules = [];
                if ($category->is_private && !$category->is_system) {
                    $accessRules = $category->accessRules()->get()->map(function ($rule) {
                        if ($rule->access_type === 'role') {
                            return ['type' => 'role', 'role' => $rule->role];
                        } elseif ($rule->access_type === 'user') {
                            return ['type' => 'user', 'user_id' => $rule->user_id];
                        }
                        return null;
                    })->filter()->values()->toArray();
                }

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'icon' => $category->icon,
                    'asset_type' => $category->asset_type->value,
                    'is_system' => $category->is_system,
                    'is_private' => $category->is_private,
                    'is_locked' => $category->is_locked,
                    'is_hidden' => $category->is_hidden,
                    'upgrade_available' => $category->upgrade_available ?? false,
                    'deletion_available' => $category->deletion_available ?? false,
                    'system_version' => $category->system_version,
                    'order' => $category->order ?? 0,
                    'template_exists' => $category->systemTemplateExists(),
                    'can_be_deleted' => $category->canBeDeleted(),
                    'access_rules' => $accessRules,
                ];
            }),
            'available_system_templates' => $availableTemplates->values(),
            'category_limits' => [
                'current' => $currentCategoryCount,
                'max' => $limits['max_categories'],
                'can_create' => $canCreateCategory,
            ],
            'brand_users' => $brandUsers,
            'brand_roles' => $brandRoles,
            'private_category_limits' => [
                'current' => $currentPrivateCount,
                'max' => $maxPrivateCategories,
                'can_create' => $canCreatePrivateCategory,
                'plan_allows' => in_array($currentPlan, ['pro', 'enterprise']),
            ],
            'can_edit_system_categories' => $canEditSystemCategories,
            // Phase M-2: Pass tenant settings to check if company metadata approval is enabled
            'tenant_settings' => $tenant->settings ?? [],
            'current_plan' => $currentPlan,
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
            'icon' => 'nullable|mimes:png,webp,svg,avif|max:2048',
            'icon_bg_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'show_in_selector' => 'nullable|boolean',
            'primary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'accent_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'nav_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings' => 'nullable|array',
            'settings.metadata_approval_enabled' => 'nullable|boolean', // Phase M-2
            'settings.contributor_upload_requires_approval' => 'nullable|boolean', // Phase J.3.1
            'download_landing_settings' => 'nullable|array', // D10: brand-level download branding
            'download_landing_settings.enabled' => 'nullable|boolean',
            'download_landing_settings.logo_asset_id' => 'nullable|uuid',
            'download_landing_settings.color_role' => 'nullable|string|in:primary,secondary,accent',
            'download_landing_settings.default_headline' => 'nullable|string|max:200',
            'download_landing_settings.default_subtext' => 'nullable|string|max:500',
            'download_landing_settings.background_asset_ids' => 'nullable|array',
            'download_landing_settings.background_asset_ids.*' => 'uuid',
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

        // Handle icon file upload
        if ($request->hasFile('icon')) {
            // Delete old icon if it exists and is stored locally
            if ($brand->icon_path && str_starts_with($brand->icon_path, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $brand->icon_path);
                Storage::disk('public')->delete($oldPath);
            }
            
            $iconPath = $request->file('icon')->store("brands/{$brand->tenant_id}", 'public');
            $validated['icon_path'] = Storage::url($iconPath);
            unset($validated['icon']); // Remove the file from validated data
            // Clear icon when uploading a file
            $validated['icon'] = null;
        } else {
            // Keep existing icon_path if no new file is uploaded
            $validated['icon_path'] = $brand->icon_path ?? null;
            // Clear icon when not uploading
            $validated['icon'] = null;
        }
        
        // Handle icon_bg_color
        if ($request->has('icon_bg_color')) {
            $validated['icon_bg_color'] = $request->input('icon_bg_color') ?: null;
        } else {
            // Keep existing icon_bg_color if not provided
            $validated['icon_bg_color'] = $brand->icon_bg_color;
        }

        // Phase M-2: Handle settings separately (merge with existing)
        $settings = $validated['settings'] ?? [];
        unset($validated['settings']);
        
        // Always merge settings to ensure all settings are preserved
        $currentSettings = $brand->settings ?? [];
        if (!empty($settings)) {
            // Merge new settings with existing settings
            $mergedSettings = array_merge($currentSettings, $settings);
        } else {
            // If no settings provided, keep existing settings
            $mergedSettings = $currentSettings;
        }
        
        // Always set settings (even if empty) to ensure the column is updated
        $validated['settings'] = $mergedSettings;

        // D10: Download landing settings (replace, not merge — frontend sends full object)
        if ($request->has('download_landing_settings')) {
            $raw = $request->input('download_landing_settings');
            $validated['download_landing_settings'] = is_array($raw) ? $this->sanitizeDownloadLandingSettings($raw, $brand) : [];
        } else {
            $validated['download_landing_settings'] = $brand->download_landing_settings ?? [];
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
     * D10: Sanitize download landing settings — no raw URL/hex; logo from asset (category logo); color from palette role; backgrounds from brand assets.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function sanitizeDownloadLandingSettings(array $input, Brand $brand): array
    {
        // Reject raw URL/hex — D10: visuals from brand system only
        if (! empty($input['logo_url']) || ! empty($input['accent_color'])) {
            throw ValidationException::withMessages([
                'download_landing_settings' => ['Logo and accent must be selected from brand assets and palette, not entered as URL or hex.'],
            ]);
        }

        $out = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'logo_asset_id' => null,
            'color_role' => 'primary',
            'background_asset_ids' => [],
            'default_headline' => null,
            'default_subtext' => null,
        ];

        if (isset($input['logo_asset_id']) && is_string($input['logo_asset_id']) && preg_match('/^[0-9a-f-]{36}$/i', $input['logo_asset_id'])) {
            $asset = Asset::where('id', $input['logo_asset_id'])->where('brand_id', $brand->id)->first();
            if (! $asset) {
                throw ValidationException::withMessages([
                    'download_landing_settings.logo_asset_id' => ['Selected logo asset does not belong to this brand.'],
                ]);
            }
            $logoCategory = Category::where('brand_id', $brand->id)->where('slug', 'logos')->first();
            if (! $logoCategory) {
                throw ValidationException::withMessages([
                    'download_landing_settings.logo_asset_id' => ['This brand has no logo category.'],
                ]);
            }
            $assetCategoryId = $asset->metadata['category_id'] ?? null;
            if ((string) $assetCategoryId !== (string) $logoCategory->id) {
                throw ValidationException::withMessages([
                    'download_landing_settings.logo_asset_id' => ['Logo must be an asset in the Logos category.'],
                ]);
            }
            $out['logo_asset_id'] = $asset->id;
        }

        if (isset($input['color_role']) && in_array($input['color_role'], ['primary', 'secondary', 'accent'], true)) {
            $out['color_role'] = $input['color_role'];
        }

        if (isset($input['default_headline']) && is_string($input['default_headline'])) {
            $out['default_headline'] = substr(trim(strip_tags($input['default_headline'])), 0, 200) ?: null;
        }
        if (isset($input['default_subtext']) && is_string($input['default_subtext'])) {
            $out['default_subtext'] = substr(trim(strip_tags($input['default_subtext'])), 0, 500) ?: null;
        }

        if (isset($input['background_asset_ids']) && is_array($input['background_asset_ids'])) {
            $ids = array_values(array_filter(array_slice($input['background_asset_ids'], 0, 5), fn ($id) => is_string($id) && preg_match('/^[0-9a-f-]{36}$/i', $id)));
            foreach ($ids as $id) {
                $asset = Asset::where('id', $id)->where('brand_id', $brand->id)->first();
                if (! $asset) {
                    throw ValidationException::withMessages([
                        'download_landing_settings.background_asset_ids' => ['All background assets must belong to this brand.'],
                    ]);
                }
                if (! $this->assetEligibilityService->isEligibleForDownloadBackground($asset)) {
                    throw ValidationException::withMessages([
                        'download_landing_settings.background_asset_ids' => ['Background images must be at least 1920×1080.'],
                    ]);
                }
            }
            $out['background_asset_ids'] = $ids;
        }

        return $out;
    }

    /**
     * D10: List brand assets for download landing (generic picker; no category filter).
     */
    public function downloadBrandingAssets(Brand $brand): \Illuminate\Http\JsonResponse
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $assets = Asset::where('brand_id', $brand->id)
            ->whereNotNull('published_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'original_filename'])
            ->map(fn (Asset $a) => [
                'id' => $a->id,
                'thumbnail_url' => route('assets.thumbnail.final', ['asset' => $a->id, 'style' => 'thumb']),
                'original_filename' => $a->original_filename ?? '',
            ])
            ->values()
            ->all();

        return response()->json(['assets' => $assets]);
    }

    /**
     * D10.1: List brand assets for download landing background picker — Photography or Graphics (image categories), with dimensions.
     */
    public function downloadBackgroundCandidates(Brand $brand): \Illuminate\Http\JsonResponse
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $imageCategoryIds = Category::where('brand_id', $brand->id)
            ->whereIn('slug', ['photography', 'graphics'])
            ->pluck('id')
            ->all();

        if (empty($imageCategoryIds)) {
            return response()->json(['assets' => []]);
        }

        $query = Asset::where('brand_id', $brand->id)
            ->whereNotNull('published_at')
            ->whereNull('archived_at')
            ->where(function ($q) use ($imageCategoryIds) {
                foreach ($imageCategoryIds as $cid) {
                    $q->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = ?', [(string) $cid]);
                }
            });

        $assets = $query->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (Asset $a) {
                $w = null;
                $h = null;
                $meta = $a->metadata ?? [];
                if (isset($meta['image_width'], $meta['image_height'])) {
                    $w = (int) $meta['image_width'];
                    $h = (int) $meta['image_height'];
                } elseif ($a->video_width !== null && $a->video_height !== null) {
                    $w = (int) $a->video_width;
                    $h = (int) $a->video_height;
                }
                $qualityRating = $meta['fields']['quality_rating'] ?? $meta['quality_rating'] ?? null;
                return [
                    'id' => $a->id,
                    'thumbnail_url' => route('assets.thumbnail.final', ['asset' => $a->id, 'style' => 'thumb']),
                    'original_filename' => $a->original_filename ?? '',
                    'width' => $w,
                    'height' => $h,
                    'quality_rating' => $qualityRating,
                ];
            })
            ->values()
            ->all();

        return response()->json(['assets' => $assets]);
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

    /**
     * Get available users (company users not on this brand).
     */
    public function availableUsers(Brand $brand)
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check permission
        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to manage brand users.');
        }

        // Get users in the company
        $tenantUsers = $tenant->users;
        
        // Phase MI-1: Get users with active membership only
        $assignedUserIds = $brand->users()
            ->wherePivotNull('removed_at')
            ->pluck('users.id')
            ->toArray();
        
        // Phase MI-1: Filter to available users (exclude those with active membership)
        $availableUsers = $tenantUsers->reject(fn ($user) => in_array($user->id, $assignedUserIds))->map(fn ($user) => [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
        ]);

        return response()->json($availableUsers->values());
    }

    /**
     * Invite a user to the brand by email.
     */
    public function inviteUser(Request $request, Brand $brand)
    {
        $tenant = app('tenant');
        $authUser = Auth::user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check permission
        if (! $authUser->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to invite users to brands.');
        }

        // Validate using RoleRegistry
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return; // Nullable, skip validation
                    }
                    try {
                        RoleRegistry::validateBrandRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        // Check if user is already on the brand
        $existingUser = User::where('email', $validated['email'])->first();
        if ($existingUser && $brand->users()->where('users.id', $existingUser->id)->exists()) {
            return back()->withErrors([
                'email' => 'This user is already a member of this brand.',
            ]);
        }

        // Check if there's already a pending invitation
        $existingInvitation = $brand->invitations()
            ->where('email', $validated['email'])
            ->whereNull('accepted_at')
            ->first();

        if ($existingInvitation) {
            return back()->withErrors([
                'email' => 'An invitation has already been sent to this email address.',
            ]);
        }

        // Create invitation
        $token = Str::random(64);
        $invitation = BrandInvitation::create([
            'brand_id' => $brand->id,
            'email' => $validated['email'],
            'role' => $validated['role'] ?? 'viewer',
            'token' => $token,
            'invited_by' => $authUser->id,
            'sent_at' => now(),
        ]);

        // Generate invite URL (for now, redirect to login - can be enhanced later)
        $inviteUrl = route('invite.accept', [
            'token' => $token,
            'tenant' => $tenant->id,
        ]);

        // Send invitation email (using tenant invite for now - can create brand-specific later)
        Mail::to($validated['email'])->send(new InviteMember($tenant, $authUser, $inviteUrl));

        // Log activity
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_INVITED,
            subject: $existingUser ?? null,
            actor: $authUser,
            brand: $brand,
            metadata: [
                'email' => $validated['email'],
                'role' => $validated['role'] ?? 'viewer',
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
            ]
        );

        return back()->with('success', 'Invitation sent successfully.');
    }

    /**
     * Add an existing company user to the brand.
     */
    public function addUser(Request $request, Brand $brand, User $user)
    {
        $tenant = app('tenant');
        $authUser = Auth::user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check permission
        if (! $authUser->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to manage brand users.');
        }

        // Verify user belongs to tenant
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'User does not belong to this company.');
        }

        // Phase MI-1: Check for existing pivot (including soft-deleted)
        $existingPivot = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->first();
        
        // Check if user already has active membership
        $activeMembership = $user->activeBrandMembership($brand);
        if ($activeMembership) {
            return back()->withErrors([
                'user' => 'This user is already an active member of this brand.',
            ]);
        }

        // Validate using RoleRegistry
        $validated = $request->validate([
            'role' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return; // Nullable, skip validation
                    }
                    try {
                        RoleRegistry::validateBrandRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        // Phase MI-1: Add user to brand with role (default to viewer)
        // setRoleForBrand handles soft-deleted pivot restoration
        $brandRole = $validated['role'] ?? 'viewer';
        $user->setRoleForBrand($brand, $brandRole);

        // Mark any pending invitations as accepted
        $brand->invitations()
            ->where('email', $user->email)
            ->whereNull('accepted_at')
            ->update(['accepted_at' => now()]);

        // Log activity
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_ADDED_TO_BRAND,
            subject: $user,
            actor: $authUser,
            brand: $brand,
            metadata: [
                'role' => $validated['role'] ?? 'viewer',
            ]
        );

        return back()->with('success', 'User added to brand successfully.');
    }

    /**
     * Update a user's role for the brand.
     */
    public function updateUserRole(Request $request, Brand $brand, User $user)
    {
        $tenant = app('tenant');
        $authUser = Auth::user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check permission
        if (! $authUser->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to manage brand users.');
        }

        // Verify user is on the brand
        if (! $brand->users()->where('users.id', $user->id)->exists()) {
            abort(404, 'User is not a member of this brand.');
        }

        // Validate using RoleRegistry - no automatic conversion
        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    try {
                        RoleRegistry::validateBrandRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        $brandRole = $validated['role'];

        // Update role
        $brand->users()->updateExistingPivot($user->id, ['role' => $brandRole]);

        // Log activity
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_ROLE_UPDATED,
            subject: $user,
            actor: $authUser,
            brand: $brand,
            metadata: [
                'role' => $validated['role'] ?? 'viewer',
            ]
        );

        return back()->with('success', 'User role updated successfully.');
    }

    /**
     * Remove a user from the brand.
     */
    public function removeUser(Brand $brand, User $user)
    {
        $tenant = app('tenant');
        $authUser = Auth::user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Check permission
        if (! $authUser->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to manage brand users.');
        }

        // Phase MI-1: Verify user has active membership
        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            abort(404, 'User is not an active member of this brand.');
        }

        // Phase MI-1: Soft delete - set removed_at instead of deleting pivot
        DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->whereNull('removed_at') // Only update active memberships
            ->update([
                'removed_at' => now(),
                'updated_at' => now(),
            ]);

        // C12: Revoke collection-only access for this brand's collections (remove from brand = no viewer, no collection access)
        $brandCollectionIds = \App\Models\Collection::where('brand_id', $brand->id)->pluck('id')->toArray();
        if (! empty($brandCollectionIds)) {
            CollectionUser::where('user_id', $user->id)
                ->whereIn('collection_id', $brandCollectionIds)
                ->delete();
        }

        // Log activity
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_REMOVED_FROM_BRAND,
            subject: $user,
            actor: $authUser,
            brand: $brand,
            metadata: []
        );

        return back()->with('success', 'User removed from brand successfully.');
    }

    /**
     * Resend a brand invitation.
     */
    public function resendInvitation(Brand $brand, BrandInvitation $invitation)
    {
        $tenant = app('tenant');
        $authUser = Auth::user();

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Verify invitation belongs to brand
        if ($invitation->brand_id !== $brand->id) {
            abort(403, 'Invitation does not belong to this brand.');
        }

        // Check permission
        if (! $authUser->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to resend invitations.');
        }

        // Check if already accepted
        if ($invitation->accepted_at) {
            return back()->withErrors([
                'invitation' => 'This invitation has already been accepted.',
            ]);
        }

        // Update sent_at
        $invitation->update(['sent_at' => now()]);

        // Generate invite URL
        $inviteUrl = route('invite.accept', [
            'token' => $invitation->token,
            'tenant' => $tenant->id,
        ]);

        // Resend email
        Mail::to($invitation->email)->send(new InviteMember($tenant, $authUser, $inviteUrl));

        return back()->with('success', 'Invitation resent successfully.');
    }

    /**
     * Show the approval queue page.
     * 
     * Phase AF-1: Approval workflow page for pending assets.
     */
    public function approvals(Brand $brand): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Phase AF-5: Gate approval queue access based on plan feature
        $featureGate = app(FeatureGate::class);
        if (!$featureGate->approvalsEnabled($tenant)) {
            $requiredPlan = $featureGate->getRequiredPlanName($tenant);
            abort(403, "Approval workflows require {$requiredPlan} plan or higher. Please upgrade your plan to access approval features.");
        }

        // Check if user is approval_capable for this brand
        $brandRole = $user->getRoleForBrand($brand);
        if (!$brandRole || !PermissionMap::canApproveAssets($brandRole)) {
            abort(403, 'You do not have permission to view the approval queue.');
        }

        return Inertia::render('Brands/Approvals', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
            ],
        ]);
    }
}
