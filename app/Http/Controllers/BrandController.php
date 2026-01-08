<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Mail\InviteMember;
use App\Models\Brand;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\BrandService;
use App\Services\CategoryService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
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
        $maxBrands = $limits['max_brands'] ?? PHP_INT_MAX;
        $brandLimitExceeded = $currentCount > $maxBrands;
        $canCreate = $this->brandService->canCreate($tenant);

        // Get available users (company users not yet on each brand) and pending invitations
        $tenantUsers = $tenant->users;
        
        // Order brands: default first, then by name (same as in HandleInertiaRequests)
        $orderedBrands = $brands->load(['categories', 'users', 'invitations'])
            ->sortBy([['is_default', 'desc'], ['name', 'asc']])
            ->values();
        
        return Inertia::render('Brands/Index', [
            'brands' => $orderedBrands->map(function ($brand, $index) use ($tenantUsers, $maxBrands, $brandLimitExceeded) {
                // Mark brands beyond plan limit as disabled
                // Index is 0-based, so index >= maxBrands means it's beyond the limit
                $isDisabled = $brandLimitExceeded && ($index >= $maxBrands);
                
                // Get users not yet assigned to this brand
                // Get users not yet assigned to this brand
                $assignedUserIds = $brand->users->pluck('id')->toArray();
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
                    ]),
                    'users' => $brand->users->map(fn ($user) => [
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
        if (! $isTenantOwnerOrAdmin && ! $user->brands()->where('brands.id', $brand->id)->exists()) {
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
        $categories = $brand->categories()->orderBy('asset_type')->orderBy('order')->orderBy('name')->get();

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
                'icon' => $category->icon,
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
        
        // Get users already assigned to this brand
        $assignedUserIds = $brand->users->pluck('id')->toArray();
        
        // Filter to available users
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

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => 'nullable|string|in:owner,admin,member,brand_manager',
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
            'role' => $validated['role'] ?? 'member',
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
                'role' => $validated['role'] ?? 'member',
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

        // Check if user is already on the brand
        if ($brand->users()->where('users.id', $user->id)->exists()) {
            return back()->withErrors([
                'user' => 'This user is already a member of this brand.',
            ]);
        }

        $validated = $request->validate([
            'role' => 'nullable|string|in:owner,admin,member,brand_manager',
        ]);

        // Add user to brand with role
        $user->setRoleForBrand($brand, $validated['role'] ?? 'member');

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
                'role' => $validated['role'] ?? 'member',
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

        $validated = $request->validate([
            'role' => 'required|string|in:owner,admin,member,brand_manager',
        ]);

        // Update role
        $brand->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        // Log activity
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_ROLE_UPDATED,
            subject: $user,
            actor: $authUser,
            brand: $brand,
            metadata: [
                'role' => $validated['role'],
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

        // Verify user is on the brand
        if (! $brand->users()->where('users.id', $user->id)->exists()) {
            abort(404, 'User is not a member of this brand.');
        }

        // Remove user from brand
        $brand->users()->detach($user->id);

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
}
