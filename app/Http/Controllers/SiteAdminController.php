<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Mail\AccountCanceled;
use App\Mail\AccountDeleted;
use App\Mail\AccountSuspended;
use App\Mail\PlanChangedTenant;
use App\Mail\PlanChangedAdmin;
use App\Enums\TicketStatus;
use App\Models\ActivityEvent;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Stripe\Stripe;
use Stripe\Account;
use Stripe\Price;

class SiteAdminController extends Controller
{
    /**
     * Display the site admin dashboard.
     */
    public function index(Request $request): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }
        
        // MINIMAL INITIAL LOAD - Only load basic company info
        // Heavy data (users, costs, etc.) will be loaded via AJAX
        $perPage = (int) $request->get('per_page', 10);
        $searchQuery = $request->get('search', '');
        
        $companiesQuery = Tenant::select(['id', 'name', 'slug', 'created_at', 'stripe_id', 'manual_plan_override']);
        
        // Apply search filter if provided
        if (!empty($searchQuery)) {
            $companiesQuery->where(function ($query) use ($searchQuery) {
                $query->where('name', 'like', "%{$searchQuery}%")
                    ->orWhere('slug', 'like', "%{$searchQuery}%");
            });
        }
        
        $companies = $companiesQuery
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->appends($request->except('page'));

        $planService = app(\App\Services\PlanService::class);
        
        // Load only minimal company data - details loaded via API on expand
        return Inertia::render('Admin/Index', [
            'companies' => $companies->map(function ($company) use ($planService) {
                // Quick plan lookup without heavy queries
                $planName = $planService->getCurrentPlan($company);
                
                $stripeConnected = !empty($company->stripe_id);
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                    'created_at' => $company->created_at?->format('M d, Y'),
                    'plan_name' => $planName,
                    'stripe_connected' => $stripeConnected,
                    'plan_management' => [
                        'source' => $planService->getPlanManagementSource($company),
                        'is_externally_managed' => $planService->isExternallyManaged($company),
                    ],
                    // Allow non-Stripe plans to be managed in all environments
                    // Stripe-connected companies must use Stripe billing portal
                    'can_manage_plan' => !$stripeConnected,
                    // All other data loaded via API on demand
                    'details_loaded' => false,
                ];
            }),
            'pagination' => [
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
            ],
            'stats' => null, // Loaded via API
            'users' => [], // Loaded via API
            'all_users' => [], // Loaded via API
        ]);
    }

    /**
     * Get admin stats (AJAX endpoint).
     */
    public function stats(): \Illuminate\Http\JsonResponse
    {
        if (Auth::id() !== 1) {
            abort(403);
        }

        // Cache stats for 5 minutes
        $stats = cache()->remember('admin_stats', 300, function () {
            return [
                'total_companies' => Tenant::count(),
                'total_brands' => Brand::count(),
                'total_users' => User::count(),
                'active_subscriptions' => Subscription::where('stripe_status', 'active')->count(),
                'stripe_accounts' => Tenant::whereNotNull('stripe_id')->count(),
                'support_tickets' => Ticket::count(),
                'waiting_on_support' => Ticket::whereIn('status', [
                    TicketStatus::OPEN->value,
                    TicketStatus::WAITING_ON_SUPPORT->value,
                ])->count(),
            ];
        });

        return response()->json($stats);
    }

    /**
     * Get company details (AJAX endpoint - loaded on expand).
     */
    public function companyDetails(Tenant $tenant): \Illuminate\Http\JsonResponse
    {
        if (Auth::id() !== 1) {
            abort(403);
        }

        $planService = app(\App\Services\PlanService::class);
        
        // Load owner efficiently
        // Query pivot table directly to find owner
        $ownerPivot = DB::table('tenant_user')
            ->where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->first();
        
        $owner = null;
        if ($ownerPivot) {
            $owner = User::where('id', $ownerPivot->user_id)
                ->select('id', 'first_name', 'last_name', 'email')
                ->first();
        }
        
        $planName = $planService->getCurrentPlan($tenant);
        $limits = $planService->getPlanLimits($tenant);
        
        // Get counts efficiently
        $brandCount = $tenant->brands()->count();
        $userCount = $tenant->users()->count();
        
        $stripeConnected = !empty($tenant->stripe_id);
        $latestSubscription = $tenant->subscriptions()
            ->where('name', 'default')
            ->orderBy('created_at', 'desc')
            ->first();
        
        $stripeStatus = $latestSubscription 
            ? $latestSubscription->stripe_status 
            : ($stripeConnected ? 'inactive' : 'not_connected');
        
        // Load brands - convert to array for JSON response
        $brands = $tenant->brands()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default'])
            ->map(fn ($brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'is_default' => $brand->is_default,
            ])
            ->values() // Reset keys for proper array serialization
            ->toArray(); // Convert to array for JSON
        
        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'brands_count' => $brandCount,
            'users_count' => $userCount,
            'plan' => ucfirst($planName),
            'plan_name' => $planName,
            'stripe_connected' => $stripeConnected,
            'stripe_status' => $stripeStatus,
            'has_access_to_brand_manager' => $planService->hasAccessToBrandManagerRole($tenant),
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')),
                'email' => $owner->email,
            ] : null,
            'created_at' => $tenant->created_at?->format('M d, Y'),
            'plan_limit_info' => [
                'brand_limit_exceeded' => $brandCount > ($limits['max_brands'] ?? PHP_INT_MAX),
                'current_brand_count' => $brandCount,
                'max_brands' => $limits['max_brands'] ?? PHP_INT_MAX,
                'user_limit_exceeded' => $userCount > ($limits['max_users'] ?? PHP_INT_MAX),
                'current_user_count' => $userCount,
                'max_users' => $limits['max_users'] ?? PHP_INT_MAX,
            ],
            'plan_management' => [
                'source' => $planService->getPlanManagementSource($tenant),
                'is_externally_managed' => $planService->isExternallyManaged($tenant),
                'manual_plan_override' => $tenant->manual_plan_override,
            ],
            // Allow non-Stripe plans to be managed in all environments
            // Stripe-connected companies must use Stripe billing portal
            'can_manage_plan' => !$stripeConnected,
            'brands' => $brands,
            // Users loaded separately via companyUsers endpoint
            'costs' => null,
            'income' => null,
            'profitability' => null,
        ]);
    }

    /**
     * Get company users (AJAX endpoint - loaded on expand).
     */
    public function companyUsers(Tenant $tenant): \Illuminate\Http\JsonResponse
    {
        if (Auth::id() !== 1) {
            abort(403);
        }

        $planService = app(\App\Services\PlanService::class);
        
        // Load users with minimal data
        $users = $tenant->users()
            ->orderBy('tenant_user.created_at')
            ->get(['users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.avatar_url'])
            ->map(function ($user, $index) use ($tenant, $planService) {
                $tenantRole = $user->getRoleForTenant($tenant);
                $isOwner = $tenant->isOwner($user);
                $isDisabledByPlanLimit = $isOwner ? false : $user->isDisabledByPlanLimit($tenant);
                
                // Load brand assignments efficiently
                $brandIds = $tenant->brands()->pluck('id');
                $userBrandRoles = DB::table('brand_user')
                    ->where('user_id', $user->id)
                    ->whereIn('brand_id', $brandIds)
                    ->pluck('role', 'brand_id')
                    ->toArray();
                
                $brands = $tenant->brands()
                    ->orderBy('is_default', 'desc')
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_default'])
                    ->map(function ($brand) use ($userBrandRoles) {
                        $role = $userBrandRoles[$brand->id] ?? null;
                        if ($role === 'owner') {
                            $role = 'admin';
                        }
                        return [
                            'id' => $brand->id,
                            'name' => $brand->name,
                            'is_default' => $brand->is_default,
                            'role' => $role,
                        ];
                    });
                
                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'tenant_role' => strtolower($tenantRole ?? 'member'),
                    'is_owner' => $isOwner,
                    'is_disabled_by_plan_limit' => $isDisabledByPlanLimit,
                    'join_order' => $index + 1,
                    'brand_assignments' => $brands,
                ];
            });
        
        return response()->json($users->values());
    }

    /**
     * Get all users for admin (AJAX endpoint).
     */
    public function allUsers(Request $request): \Illuminate\Http\JsonResponse
    {
        if (Auth::id() !== 1) {
            abort(403);
        }

        $perPage = (int) $request->get('per_page', 50);
        $search = $request->get('search', '');
        
        $query = User::with(['tenants', 'brands.tenant']);
        
        // Add search filter if provided
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        $users = $query->paginate($perPage)
            ->through(function ($user) {
                $userRoles = $user->getRoleNames()->toArray();
                $siteRoles = array_unique(array_filter($userRoles, fn($role) => str_contains(strtolower($role), 'site')));
                
                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'companies_count' => $user->tenants->count(),
                    'brands_count' => $user->brands->count(),
                    'is_suspended' => $user->isSuspended(),
                    'suspended_at' => $user->suspended_at?->toISOString(),
                    'site_roles' => array_values($siteRoles),
                    'companies' => $user->tenants->map(function ($tenant) {
                        $role = $tenant->pivot->role ?? null;
                        return [
                            'id' => $tenant->id,
                            'name' => $tenant->name,
                            'slug' => $tenant->slug,
                            'role' => $role ? ucfirst($role) : null,
                        ];
                    }),
                    'brands' => $user->brands->map(function ($brand) {
                        $brandRole = $brand->pivot->role ?? null;
                        if ($brandRole === 'owner') {
                            $brandRole = 'admin';
                        }
                        return [
                            'id' => $brand->id,
                            'name' => $brand->name,
                            'slug' => $brand->slug,
                            'tenant_id' => $brand->tenant_id,
                            'tenant_name' => $brand->tenant->name ?? null,
                            'role' => $brandRole,
                        ];
                    }),
                ];
            });
        
        return response()->json($users);
    }

    /**
     * Get users for selector (AJAX endpoint).
     * Standardized method to query users, avoiding orphan issues.
     */
    public function usersForSelector(Request $request): \Illuminate\Http\JsonResponse
    {
        if (Auth::id() !== 1) {
            abort(403);
        }

        $search = $request->get('search', '');
        $excludeTenantId = $request->get('exclude_tenant_id');
        
        $query = User::select(['id', 'first_name', 'last_name', 'email']);
        
        // Exclude users already in the specified tenant (if provided)
        if ($excludeTenantId) {
            $query->whereDoesntHave('tenants', function ($q) use ($excludeTenantId) {
                $q->where('tenants.id', $excludeTenantId);
            });
        }
        
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        $users = $query->limit(100)->get()->map(fn ($user) => [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ]);
        
        return response()->json($users);
    }
    
    /**
     * Add a user to a company.
     */

    /**
     * Add a user to a company.
     */
    public function addUserToCompany(Request $request, Tenant $tenant)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Check if company has access to brand_manager role (Pro/Enterprise plans only)
        $planService = app(\App\Services\PlanService::class);
        $hasAccessToBrandManager = $planService->hasAccessToBrandManagerRole($tenant);
        
        // Tenant roles can include owner
        $allowedTenantRoles = ['owner', 'admin', 'member'];
        if ($hasAccessToBrandManager) {
            $allowedTenantRoles[] = 'brand_manager';
        }
        
        // Brand roles cannot include owner
        $allowedBrandRoles = ['admin', 'member'];
        if ($hasAccessToBrandManager) {
            $allowedBrandRoles[] = 'brand_manager';
        }
        
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => ['nullable', 'string', 'in:' . implode(',', $allowedTenantRoles)],
            'brands' => 'required|array|min:1',
            'brands.*.brand_id' => 'required|exists:brands,id',
            'brands.*.role' => 'required|string|in:' . implode(',', $allowedBrandRoles),
        ]);
        
        // Prevent owner from being assigned as a brand role - convert to admin
        foreach ($validated['brands'] as &$brandAssignment) {
            if ($brandAssignment['role'] === 'owner') {
                $brandAssignment['role'] = 'admin';
            }
        }
        unset($brandAssignment);

        $user = User::findOrFail($validated['user_id']);

        // Check if user is already in this company
        if ($tenant->users()->where('users.id', $user->id)->exists()) {
            return back()->withErrors([
                'user' => 'User is already a member of this company.',
            ]);
        }

        // Verify all brands belong to this tenant
        $brandIds = collect($validated['brands'])->pluck('brand_id')->unique();
        $tenantBrandIds = $tenant->brands()->pluck('id')->toArray();
        $invalidBrands = $brandIds->diff($tenantBrandIds);
        
        if ($invalidBrands->isNotEmpty()) {
            return back()->withErrors([
                'brands' => 'One or more selected brands do not belong to this company.',
            ]);
        }

        // Determine tenant role (use provided role or default to first brand role or 'member')
        $tenantRole = $validated['role'] ?? ($validated['brands'][0]['role'] ?? 'member');

        // Add user to company with role in pivot table
        $tenant->users()->attach($user->id, ['role' => $tenantRole]);

        // Assign user to brands with roles
        foreach ($validated['brands'] as $brandAssignment) {
            $brand = $tenant->brands()->find($brandAssignment['brand_id']);
            if ($brand) {
                $user->setRoleForBrand($brand, $brandAssignment['role']);
            }
        }

        // Log activity - tenant sees "system" as actor, but metadata contains admin info
        $admin = Auth::user();
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_ADDED_TO_COMPANY,
            subject: $user,
            actor: 'system', // Tenant sees "system"
            brand: null,
            metadata: [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'role' => $tenantRole,
                'brands' => $validated['brands'],
            ]
        );

        return back()->with('success', 'User added to company successfully.');
    }

    /**
     * Update a user's role in a company.
     */
    public function updateUserRole(Request $request, Tenant $tenant, User $user)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Verify user belongs to this company
        if (!$tenant->users()->where('users.id', $user->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        // Check if company has access to brand_manager role (Pro/Enterprise plans only)
        $planService = app(\App\Services\PlanService::class);
        $hasAccessToBrandManager = $planService->hasAccessToBrandManagerRole($tenant);
        
        $allowedRoles = ['owner', 'admin', 'member'];
        if ($hasAccessToBrandManager) {
            $allowedRoles[] = 'brand_manager';
        }
        
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:' . implode(',', $allowedRoles)],
        ]);

        // Check if trying to assign owner role
        $isBecomingOwner = $validated['role'] === 'owner';
        
        // Only platform super-owner (user ID 1) can directly assign owner role
        if ($isBecomingOwner && Auth::id() !== 1) {
            throw new \App\Exceptions\CannotAssignOwnerRoleException(
                $tenant,
                $user,
                Auth::user(),
                'Only the platform super-owner can directly assign owner role. For all other users, please use the ownership transfer process in the Company settings.'
            );
        }

        // Get old role for logging (need to refresh pivot to get current role)
        $user->refresh();
        $pivot = $tenant->users()->where('users.id', $user->id)->first()?->pivot;
        $oldRole = $pivot->role ?? null;
        $isCurrentlyOwner = $oldRole && strtolower($oldRole) === 'owner';
        
        // If setting a new owner, demote the current owner first
        // This ensures only ONE owner exists at a time - database is source of truth
        if ($isBecomingOwner && !$isCurrentlyOwner) {
            $currentOwner = $tenant->owner();
            if ($currentOwner && $currentOwner->id !== $user->id) {
                // Demote current owner to admin (preserving their status)
                // This is allowed for super-owner (break-glass exception)
                $currentOwner->setRoleForTenant($tenant, 'admin');
                
                // Log the owner change for audit trail
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: EventType::USER_ROLE_UPDATED,
                    subject: $currentOwner,
                    actor: 'system',
                    brand: null,
                    metadata: [
                        'admin_id' => Auth::id(),
                        'admin_name' => Auth::user()->name,
                        'admin_email' => Auth::user()->email,
                        'old_role' => 'owner',
                        'new_role' => 'admin',
                        'reason' => 'Owner changed - automatically demoted by platform super-owner',
                    ]
                );
            }
        }
        
        // Prevent demoting the owner without a replacement
        // If demoting current owner to non-owner, ensure someone else is being set as owner
        if ($isCurrentlyOwner && !$isBecomingOwner) {
            // Check if there's another user being set as owner in this same request
            // (This would be handled by the above logic if someone else is becoming owner)
            // But if we're just demoting the owner, prevent it - there must always be an owner
            abort(422, 'Cannot remove owner role. There must always be one owner per company. Please assign owner role to another user first.');
        }
        
        // Update role in pivot table (tenant-scoped)
        // For super-owner, this will succeed. For others, CannotAssignOwnerRoleException would have been thrown above.
        $user->setRoleForTenant($tenant, $validated['role']);

        // Log activity - tenant sees "system" as actor, but metadata contains admin info
        $admin = Auth::user();
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_ROLE_UPDATED,
            subject: $user,
            actor: 'system', // Tenant sees "system"
            brand: null,
            metadata: [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'old_role' => $oldRole,
                'new_role' => $validated['role'],
            ]
        );

        return back()->with('success', 'User role updated successfully.');
    }

    /**
     * Update a user's role for a specific brand in a company.
     */
    public function updateUserBrandRole(Request $request, Tenant $tenant, User $user, Brand $brand)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Verify user belongs to this company
        if (!$tenant->users()->where('users.id', $user->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        // Verify brand belongs to this company
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this company.');
        }

        // Check if company has access to brand_manager role (Pro/Enterprise plans only)
        $planService = app(\App\Services\PlanService::class);
        $hasAccessToBrandManager = $planService->hasAccessToBrandManagerRole($tenant);
        
        $allowedRoles = ['admin', 'member'];
        if ($hasAccessToBrandManager) {
            $allowedRoles[] = 'brand_manager';
        }
        
        // Get the role value - allow empty/null for "not assigned"
        // Handle multiple ways the frontend might send empty values
        $role = $request->input('role');
        
        // Convert empty string, null, or missing value to null
        if ($role === '' || $role === null || !$request->has('role')) {
            $role = null;
        } else {
            // Prevent 'owner' from being a brand role - convert to 'admin' if attempted
            if ($role === 'owner') {
                $role = 'admin';
            }
            
            // Validate role if provided
            if (!in_array($role, $allowedRoles)) {
                return back()->withErrors([
                    'role' => 'Invalid role. Must be one of: ' . implode(', ', $allowedRoles),
                ]);
            }
        }

        // Get old role for logging
        $oldRole = $user->getRoleForBrand($brand);
        
        // If role is empty/null, remove user from brand; otherwise update/add role
        if (empty($role)) {
            // Remove user from brand
            $brand->users()->detach($user->id);
            $newRole = null;
        } else {
            // Update or add brand role
            $user->setRoleForBrand($brand, $role);
            $newRole = $role;
        }

        // Log activity
        $admin = Auth::user();
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_ROLE_UPDATED,
            subject: $user,
            actor: 'system',
            brand: $brand,
            metadata: [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'old_role' => $oldRole,
                'new_role' => $newRole,
                'action' => empty($role) ? 'removed' : ($oldRole ? 'updated' : 'assigned'),
            ]
        );

        return back()->with('success', 'User brand role updated successfully.');
    }

    /**
     * Assign a site role to a user.
     */
    public function assignSiteRole(Request $request, User $user)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $validated = $request->validate([
            'role' => 'required|string|in:site_owner,site_admin,site_support,site_engineering,compliance',
        ]);

        // CRITICAL: Only user ID 1 can have site_owner role
        if ($validated['role'] === 'site_owner' && $user->id !== 1) {
            return redirect()->back()->withErrors(['role' => 'Only user ID 1 can have the site_owner role.']);
        }

        // Get old site roles for logging
        $oldSiteRoles = $user->getRoleNames()->filter(function ($role) {
            return in_array($role, ['site_owner', 'site_admin', 'site_support', 'site_engineering', 'compliance']);
        })->toArray();
        
        // Remove existing site roles
        $user->removeRole(['site_owner', 'site_admin', 'site_support', 'site_engineering', 'compliance']);
        
        // Assign the new site role
        $user->assignRole($validated['role']);

        // Log activity for all companies this user belongs to
        // Site role changes affect all companies, so we log for each tenant
        $admin = Auth::user();
        foreach ($user->tenants as $tenant) {
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::USER_SITE_ROLE_ASSIGNED,
                subject: $user,
                actor: 'system', // Tenant sees "system"
                brand: null,
                metadata: [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'admin_email' => $admin->email,
                    'old_roles' => $oldSiteRoles,
                    'new_role' => $validated['role'],
                ]
            );
        }

        return back()->with('success', 'Site role assigned successfully.');
    }

    /**
     * Remove a user from a company (admin action).
     */
    public function removeUserFromCompany(Request $request, Tenant $tenant, User $user)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Verify the user to be removed belongs to this company
        if (!$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        // Get user role before removal for logging
        $pivot = $tenant->users()->where('users.id', $user->id)->first()?->pivot;
        $userRole = $pivot->role ?? null;

        // Prevent removing the company owner (user with 'owner' role or first user)
        if (strtolower($userRole ?? '') === 'owner') {
            return back()->withErrors([
                'user' => 'Cannot remove the company owner.',
            ]);
        }

        // Also check if this is the first user (fallback owner detection)
        $firstUser = $tenant->users()->orderBy('created_at')->first();
        if ($firstUser && $firstUser->id === $user->id) {
            return back()->withErrors([
                'user' => 'Cannot remove the company owner.',
            ]);
        }

        // Remove user from tenant
        $tenant->users()->detach($user->id);

        // Also remove from all brands in this tenant
        foreach ($tenant->brands as $brand) {
            $brand->users()->detach($user->id);
        }

        // Log activity - tenant sees "system" as actor, but metadata contains admin info
        $admin = Auth::user();
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_REMOVED_FROM_COMPANY,
            subject: $user,
            actor: 'system', // Tenant sees "system"
            brand: null,
            metadata: [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'user_role' => $userRole,
            ]
        );

        return back()->with('success', 'User removed from company successfully.');
    }

    /**
     * Cancel a user's account (remove from company but keep account active).
     */
    public function cancelAccount(Request $request, Tenant $tenant, User $user)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Verify the user belongs to this company
        if (!$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        // Get user role before removal for logging
        $pivot = $tenant->users()->where('users.id', $user->id)->first()?->pivot;
        $userRole = $pivot->role ?? null;

        // Prevent canceling the company owner (user with 'owner' role or first user)
        if (strtolower($userRole ?? '') === 'owner') {
            return back()->withErrors([
                'user' => 'Cannot cancel the company owner account.',
            ]);
        }

        // Also check if this is the first user (fallback owner detection)
        $firstUser = $tenant->users()->orderBy('created_at')->first();
        if ($firstUser && $firstUser->id === $user->id) {
            return back()->withErrors([
                'user' => 'Cannot cancel the company owner account.',
            ]);
        }

        // Store user info before removal for email
        $userEmail = $user->email;
        $userName = $user->name;

        // Remove user from tenant
        $tenant->users()->detach($user->id);

        // Also remove from all brands in this tenant
        foreach ($tenant->brands as $brand) {
            $brand->users()->detach($user->id);
        }

        // Send notification email
        $admin = Auth::user();
        Mail::to($userEmail)->send(new AccountCanceled($tenant, $user, $admin));

        // Log activity
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_REMOVED_FROM_COMPANY,
            subject: $user,
            actor: 'system',
            brand: null,
            metadata: [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'user_role' => $userRole,
                'action' => 'canceled',
            ]
        );

        return back()->with('success', 'User account canceled successfully. Notification email sent.');
    }

    /**
     * Delete a user's account completely.
     */
    public function deleteAccount(Request $request, Tenant $tenant, User $user)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Verify the user belongs to this company
        if (!$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        // Get user role before deletion for logging
        $pivot = $tenant->users()->where('users.id', $user->id)->first()?->pivot;
        $userRole = $pivot->role ?? null;

        // Prevent deleting the company owner (user with 'owner' role or first user)
        if (strtolower($userRole ?? '') === 'owner') {
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => 'Cannot delete the company owner account.',
                ], 422);
            }
            return back()->withErrors([
                'user' => 'Cannot delete the company owner account.',
            ]);
        }

        // Also check if this is the first user (fallback owner detection)
        $firstUser = $tenant->users()->orderBy('created_at')->first();
        if ($firstUser && $firstUser->id === $user->id) {
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => 'Cannot delete the company owner account.',
                ], 422);
            }
            return back()->withErrors([
                'user' => 'Cannot delete the company owner account.',
            ]);
        }

        // Check if tenant has a Stripe account/subscription
        // If yes, require user to be suspended before deletion
        // If no Stripe account, allow deletion directly
        $hasStripeAccount = !empty($tenant->stripe_id);
        $hasActiveSubscription = false;
        
        if ($hasStripeAccount) {
            $subscription = $tenant->subscriptions()
                ->where('name', 'default')
                ->whereIn('stripe_status', ['active', 'trialing', 'past_due', 'incomplete'])
                ->first();
            $hasActiveSubscription = $subscription !== null;
        }

        // If tenant has Stripe account/subscription, user must be suspended first
        if ($hasStripeAccount || $hasActiveSubscription) {
            if (!$user->isSuspended()) {
                $errorMessage = 'Cannot delete a user whose company has a Stripe account. Please suspend the user first, then delete them.';
                if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                    return response()->json([
                        'error' => $errorMessage,
                    ], 422);
                }
                return back()->withErrors([
                    'user' => $errorMessage,
                ]);
            }
        }

        // Store user info before deletion for email (user will be deleted)
        $userEmail = $user->email;
        $userName = $user->name;
        $admin = Auth::user();

        // Remove user from tenant
        $tenant->users()->detach($user->id);

        // Also remove from all brands in this tenant
        foreach ($tenant->brands as $brand) {
            $brand->users()->detach($user->id);
        }

        // Send notification email BEFORE deleting the user
        Mail::to($userEmail)->send(new AccountDeleted($tenant, $userEmail, $userName, $admin));

        // Delete the user account completely
        $user->delete();

        // Log activity (using system as actor since user is deleted)
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_DELETED,
            subject: null, // User is deleted, can't reference it
            actor: 'system',
            brand: null,
            metadata: [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'deleted_user_email' => $userEmail,
                'deleted_user_name' => $userName,
                'user_role' => $userRole,
                'action' => 'deleted',
            ]
        );

        if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success' => true,
                'message' => 'User account deleted successfully. Notification email sent.',
            ]);
        }
        
        return redirect()->route('admin.index')->with('success', 'User account deleted successfully. Notification email sent.');
    }

    /**
     * Delete a user's account completely (without requiring a tenant).
     * Used for users with no companies associated.
     */
    public function deleteUserAccount(Request $request, User $user)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Check if user has no companies
        $hasNoCompanies = $user->tenants()->count() === 0;

        if (!$hasNoCompanies) {
            $errorMessage = 'Cannot delete account: User is associated with companies. Use the company-specific deletion route instead.';
            if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => $errorMessage,
                ], 422);
            }
            return back()->withErrors([
                'user' => $errorMessage,
            ]);
        }

        // Store user info before deletion
        $userEmail = $user->email;
        $userName = $user->name;
        $admin = Auth::user();

        // Remove user from all brands (if any)
        foreach ($user->brands as $brand) {
            $brand->users()->detach($user->id);
        }

        // Note: No tenant to send email from, so skip email notification
        // No activity log since there's no tenant

        // Delete the user account completely
        $user->delete();

        // Check if this is an Inertia request
        if ($request->header('X-Inertia')) {
            return redirect()->route('admin.index')
                ->with('success', 'User account deleted successfully.');
        }

        // For non-Inertia requests (API, etc.)
        if ($request->wantsJson() || $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success' => 'User account deleted successfully.',
            ]);
        }

        return redirect()->route('admin.index')
            ->with('success', 'User account deleted successfully.');
    }

    /**
     * Suspend a user's account (system-wide block from accessing pages).
     */
    public function suspendAccount(Request $request, User $user)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Prevent suspending yourself
        if ($user->id === Auth::id()) {
            return back()->withErrors([
                'user' => 'You cannot suspend your own account.',
            ]);
        }

        // Prevent suspending site owners (for safety)
        if ($user->hasRole('site_owner')) {
            return back()->withErrors([
                'user' => 'Cannot suspend a site owner account.',
            ]);
        }

        // Suspend the user
        $user->suspend();

        // Send notification email
        $admin = Auth::user();
        // Get primary tenant for email context (use first tenant or create a temporary one)
        $primaryTenant = $user->tenants()->first();
        // If no tenant, we need a tenant for the email - skip email if no tenant exists
        if ($primaryTenant) {
            Mail::to($user->email)->send(new AccountSuspended($primaryTenant, $user, $admin));
        }

        // Log activity
        if ($primaryTenant) {
            ActivityRecorder::record(
                tenant: $primaryTenant,
                eventType: EventType::USER_UPDATED,
                subject: $user,
                actor: 'system',
                brand: null,
                metadata: [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'admin_email' => $admin->email,
                    'action' => 'suspended',
                ]
            );
        }

        return back()->with('success', 'User account suspended successfully. Notification email sent.');
    }

    /**
     * Unsuspend a user's account.
     */
    public function unsuspendAccount(Request $request, User $user)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Unsuspend the user
        $user->unsuspend();

        // Log activity
        $admin = Auth::user();
        $primaryTenant = $user->tenants()->first();
        if ($primaryTenant) {
            ActivityRecorder::record(
                tenant: $primaryTenant,
                eventType: EventType::USER_UPDATED,
                subject: $user,
                actor: 'system',
                brand: null,
                metadata: [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'admin_email' => $admin->email,
                    'action' => 'unsuspended',
                ]
            );
        }

        return back()->with('success', 'User account unsuspended successfully.');
    }

    /**
     * Display a user's profile and activity (admin view).
     */
    public function viewUser(User $user): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Get user's companies with roles
        $companies = $user->tenants()->get()->map(function ($tenant) use ($user) {
            $role = $tenant->pivot->role ?? null;
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'role' => $role,
            ];
        });

        // Get user's brand assignments
        $brandAssignments = $user->brands()->with('tenant')->get()->map(function ($brand) use ($user) {
            return [
                'id' => $brand->id,
                'name' => $brand->name,
                'tenant_id' => $brand->tenant_id,
                'tenant_name' => $brand->tenant->name ?? null,
                'role' => $user->getRoleForBrand($brand),
            ];
        });

        // Get activity events for this user (as subject or actor)
        $activities = ActivityEvent::where(function ($query) use ($user) {
                $query->where(function ($q) use ($user) {
                    // Events where this user is the subject
                    $q->where('subject_type', User::class)
                      ->where('subject_id', $user->id);
                })->orWhere(function ($q) use ($user) {
                    // Events where this user is the actor
                    $q->where('actor_type', 'user')
                      ->where('actor_id', $user->id);
                });
            })
            ->with(['tenant', 'brand'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($activity) {
                // Get actor safely
                $actor = null;
                if ($activity->actor_type === 'user' && $activity->actor_id) {
                    $actorModel = \App\Models\User::find($activity->actor_id);
                    if ($actorModel) {
                        $actor = [
                            'name' => $actorModel->name,
                            'email' => $actorModel->email,
                            'avatar_url' => $actorModel->avatar_url,
                        ];
                    }
                } elseif ($activity->actor_type === 'system') {
                    $actor = ['name' => 'System', 'email' => null, 'avatar_url' => null];
                } else {
                    $actor = ['name' => 'Unknown', 'email' => null, 'avatar_url' => null];
                }

                // Get subject if it's a User
                $subject = null;
                if ($activity->subject_type === User::class && $activity->subject_id) {
                    $subjectModel = \App\Models\User::find($activity->subject_id);
                    if ($subjectModel) {
                        $subject = [
                            'name' => $subjectModel->name,
                            'email' => $subjectModel->email,
                        ];
                    }
                }

                return [
                    'id' => $activity->id,
                    'event_type' => $activity->event_type,
                    'description' => $this->formatActivityDescription($activity),
                    'actor' => $actor,
                    'subject' => $subject,
                    'tenant' => $activity->tenant ? $activity->tenant->name : null,
                    'brand' => $activity->brand ? $activity->brand->name : null,
                    'metadata' => $activity->metadata ?? [],
                    'created_at' => $activity->created_at->diffForHumans(),
                    'created_at_raw' => $activity->created_at->toISOString(),
                ];
            });

        // Get site roles
        $siteRoles = $user->getRoleNames()->filter(fn($role) => str_contains(strtolower($role), 'site'))->values();

        return Inertia::render('Admin/ViewUser', [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'country' => $user->country,
                'timezone' => $user->timezone,
                'address' => $user->address,
                'city' => $user->city,
                'state' => $user->state,
                'zip' => $user->zip,
                'suspended_at' => $user->suspended_at?->toISOString(),
                'is_suspended' => $user->isSuspended(),
                'site_roles' => $siteRoles->toArray(),
            ],
            'companies' => $companies,
            'brand_assignments' => $brandAssignments,
            'activities' => $activities,
        ]);
    }

    /**
     * Display admin documentation page.
     */
    public function documentation(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        return Inertia::render('Admin/Documentation');
    }

    /**
     * Update a tenant's plan (admin override).
     * Prevents updates if plan is externally managed (e.g., Shopify).
     * Prevents updates in production environment for safety.
     */
    public function updatePlan(Request $request, Tenant $tenant)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can update plans.');
        }

        $planService = app(\App\Services\PlanService::class);
        
        // Check if plan is externally managed
        if ($planService->isExternallyManaged($tenant)) {
            return back()->withErrors([
                'plan' => 'This plan is managed externally (e.g., Shopify) and cannot be adjusted from the backend. Please update the plan through the external billing system.',
            ]);
        }
        
        // Check if tenant is connected to Stripe
        // Prevent manual plan adjustments when connected to Stripe
        // Plan should be changed through Stripe billing portal or subscription changes
        if ($tenant->stripe_id) {
            return back()->withErrors([
                'plan' => 'This company is connected to Stripe. Plan changes must be made through the Stripe billing portal or by updating the subscription directly. Manual plan overrides are not allowed for Stripe-connected companies.',
            ]);
        }

        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:free,starter,pro,enterprise'],
            'management_source' => ['nullable', 'string', 'in:stripe,shopify,manual'],
            'billing_status' => ['nullable', 'string', 'in:trial,comped'],
            'expiration_months' => ['nullable', 'integer', 'min:1', 'max:36'], // Max 3 years
            'equivalent_plan_value' => ['nullable', 'numeric', 'min:0'], // For comped accounts only
        ]);

        $planName = $validated['plan'];
        $managementSource = $validated['management_source'] ?? null;
        $billingStatus = $validated['billing_status'] ?? null;
        $expirationMonths = $validated['expiration_months'] ?? null;
        $equivalentPlanValue = $validated['equivalent_plan_value'] ?? null;
        
        // Validate plan exists
        if (!config("plans.{$planName}")) {
            return back()->withErrors([
                'plan' => 'Invalid plan selected.',
            ]);
        }
        
        // If setting trial/comped with expiration, use the service
        // This ensures proper audit logging and protections
        if ($billingStatus && in_array($billingStatus, ['trial', 'comped']) && $expirationMonths) {
            try {
                $expirationService = app(\App\Services\BillingExpirationService::class);
                $admin = Auth::user();
                $oldPlan = $planService->getCurrentPlan($tenant);
                
                $expirationService->setBillingStatusWithExpiration(
                    tenant: $tenant,
                    billingStatus: $billingStatus,
                    planName: $planName,
                    months: $expirationMonths,
                    equivalentPlanValue: $equivalentPlanValue,
                    reason: 'admin_manual_assignment',
                    adminUser: $admin
                );
                
                // Set management source if provided
                if ($managementSource) {
                    $tenant->plan_management_source = $managementSource;
                    $tenant->save();
                }
                
                // Email notifications and activity logging are handled by the service
                return back()->with('success', "Plan set to {$planName} with {$billingStatus} status (expires in {$expirationMonths} months)");
            } catch (\Exception $e) {
                return back()->withErrors([
                    'plan' => 'Failed to set billing status: ' . $e->getMessage(),
                ]);
            }
        }
        
        // Legacy path: Simple plan assignment without expiration
        // Get old plan for logging
        $oldPlan = $planService->getCurrentPlan($tenant);
        
        // Update plan
        $tenant->manual_plan_override = $planName;
        if ($managementSource) {
            $tenant->plan_management_source = $managementSource;
        }
        
        // Set billing_status for accounting purposes (legacy - no expiration)
        // When manually assigning a plan without Stripe, account is comped (no revenue)
        // This is NOT frontend-facing - internal accounting only
        // TODO: Encourage use of billing_status with expiration for better tracking
        if (!$tenant->stripe_id) {
            // If billing_status provided, use it; otherwise default to 'comped'
            $tenant->billing_status = $billingStatus ?? 'comped';
            
            // Set equivalent_plan_value for comped accounts if provided
            if ($tenant->billing_status === 'comped' && $equivalentPlanValue !== null) {
                $tenant->equivalent_plan_value = $equivalentPlanValue;
            }
        }
        // If they have Stripe, billing_status should remain null (paid/subscribed)
        
        $tenant->save();

        // Log activity with admin info
        $admin = Auth::user();
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::PLAN_UPDATED,
            subject: $tenant,
            actor: $admin,
            brand: null,
            metadata: [
                'old_plan' => $oldPlan,
                'new_plan' => $planName,
                'management_source' => $tenant->plan_management_source,
                'billing_status' => $tenant->billing_status,
                'admin_id' => $admin->id,
                'admin_name' => $admin->name ?? $admin->email,
                'admin_email' => $admin->email,
            ]
        );

        // Send email notifications
        $adminName = $admin->name ?? $admin->email ?? 'System Administrator';
        $owner = $tenant->owner();
        
        // Send to tenant owner
        if ($owner && $owner->email) {
            try {
                Mail::to($owner->email)->send(new PlanChangedTenant(
                    $tenant,
                    $owner,
                    $oldPlan,
                    $planName,
                    $tenant->billing_status,
                    $tenant->billing_status_expires_at,
                    $adminName
                ));
            } catch (\Exception $e) {
                Log::error('Failed to send plan change email to tenant', [
                    'tenant_id' => $tenant->id,
                    'owner_email' => $owner->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Send to admin (site owner)
        $siteOwner = User::find(1); // Site owner is user ID 1
        if ($siteOwner && $siteOwner->email && $siteOwner->id !== $admin->id) {
            try {
                Mail::to($siteOwner->email)->send(new PlanChangedAdmin(
                    $tenant,
                    $oldPlan,
                    $planName,
                    $tenant->billing_status,
                    $tenant->billing_status_expires_at,
                    $adminName
                ));
            } catch (\Exception $e) {
                Log::error('Failed to send plan change email to admin', [
                    'tenant_id' => $tenant->id,
                    'admin_email' => $siteOwner->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return back()->with('success', "Plan updated from {$oldPlan} to {$planName}.");
    }

    /**
     * Format activity description for display.
     */
    private function formatActivityDescription($activity): string
    {
        $eventType = $activity->event_type;
        $metadata = $activity->metadata ?? [];
        
        // Get subject name if available
        $subjectName = null;
        if ($activity->subject) {
            if (method_exists($activity->subject, 'getNameAttribute')) {
                $subjectName = $activity->subject->name;
            } elseif (isset($activity->subject->name)) {
                $subjectName = $activity->subject->name;
            }
        }
        if (!$subjectName && isset($metadata['subject_name'])) {
            $subjectName = $metadata['subject_name'];
        }
        if (!$subjectName && $activity->tenant) {
            $subjectName = $activity->tenant->name ?? null;
        }
        if (!$subjectName && $activity->brand) {
            $subjectName = $activity->brand->name ?? null;
        }
        
        // Format based on event type with human-readable language
        switch ($eventType) {
            case EventType::TENANT_UPDATED:
                return $subjectName ? "Updated {$subjectName}" : 'Updated company';
            case EventType::TENANT_CREATED:
                return $subjectName ? "Created {$subjectName}" : 'Created company';
            case EventType::TENANT_DELETED:
                return $subjectName ? "Deleted {$subjectName}" : 'Deleted company';
            case EventType::BRAND_CREATED:
                return $subjectName ? "Created {$subjectName}" : 'Created brand';
            case EventType::BRAND_UPDATED:
                return $subjectName ? "Updated {$subjectName}" : 'Updated brand';
            case EventType::BRAND_DELETED:
                return $subjectName ? "Deleted {$subjectName}" : 'Deleted brand';
            case EventType::USER_CREATED:
                return $subjectName ? "Created {$subjectName} account" : 'Created user account';
            case EventType::USER_UPDATED:
                $action = $metadata['action'] ?? 'updated';
                if ($action === 'suspended') {
                    return $subjectName ? "Suspended {$subjectName} account" : 'Suspended account';
                } elseif ($action === 'unsuspended') {
                    return $subjectName ? "Unsuspended {$subjectName} account" : 'Unsuspended account';
                }
                return $subjectName ? "Updated {$subjectName} account" : 'Updated user account';
            case EventType::USER_DELETED:
                return $subjectName ? "Deleted {$subjectName} account" : 'Deleted user account';
            case EventType::USER_INVITED:
                return 'Invited user';
            case EventType::USER_REMOVED_FROM_COMPANY:
                return 'Removed user from company';
            case EventType::USER_ADDED_TO_BRAND:
                $role = $metadata['role'] ?? 'member';
                $brandName = $activity->brand->name ?? null;
                return $brandName ? "Added to {$brandName} as {$role}" : "Added to brand as {$role}";
            case EventType::USER_REMOVED_FROM_BRAND:
                $brandName = $activity->brand->name ?? null;
                return $brandName ? "Removed from {$brandName}" : 'Removed from brand';
            case EventType::USER_ROLE_UPDATED:
                $oldRole = $metadata['old_role'] ?? null;
                $newRole = $metadata['new_role'] ?? null;
                if ($oldRole && $newRole) {
                    return "Changed role from {$oldRole} to {$newRole}";
                }
                return 'Updated role';
            case EventType::CATEGORY_CREATED:
                return $subjectName ? "Created {$subjectName}" : 'Created category';
            case EventType::CATEGORY_UPDATED:
                return $subjectName ? "Updated {$subjectName}" : 'Updated category';
            case EventType::CATEGORY_DELETED:
                return $subjectName ? "Deleted {$subjectName}" : 'Deleted category';
            case EventType::CATEGORY_SYSTEM_UPGRADED:
                $fieldsUpdated = $metadata['fields_updated'] ?? [];
                $oldVersion = $metadata['old_version'] ?? null;
                $newVersion = $metadata['new_version'] ?? null;
                $versionInfo = ($oldVersion && $newVersion) ? " (v{$oldVersion} → v{$newVersion})" : '';
                if ($subjectName) {
                    return "Upgraded {$subjectName}{$versionInfo}";
                }
                return "Upgraded category{$versionInfo}";
            case EventType::PLAN_UPDATED:
                $oldPlan = $metadata['old_plan'] ?? null;
                $newPlan = $metadata['new_plan'] ?? null;
                if ($oldPlan && $newPlan) {
                    return "Changed plan from {$oldPlan} to {$newPlan}";
                }
                return 'Updated plan';
            default:
                // Fallback: format event type nicely
                return ucfirst(str_replace(['_', '.'], ' ', $eventType));
        }
    }

    /**
     * Display the permissions management page.
     */
    public function permissions(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Get site roles
        $siteRoles = [
            ['id' => 'site_owner', 'name' => 'Site Owner', 'icon' => '👑'],
            ['id' => 'site_admin', 'name' => 'Site Admin', 'icon' => ''],
            ['id' => 'site_support', 'name' => 'Site Support', 'icon' => ''],
            ['id' => 'site_engineering', 'name' => 'Site Engineering', 'icon' => ''],
            ['id' => 'compliance', 'name' => 'Compliance', 'icon' => ''],
        ];

        // Get company roles (all tenant-level roles from database)
        // Ordered by hierarchy: Owner > Admin > Brand Manager > Manager > Contributor > Uploader > Viewer > Member
        // Note: 'member' is deprecated but kept for backward compatibility
        $companyRoles = [
            ['id' => 'owner', 'name' => 'Owner', 'icon' => '👑'],
            ['id' => 'admin', 'name' => 'Admin', 'icon' => ''],
            ['id' => 'brand_manager', 'name' => 'Brand Manager', 'icon' => ''],
            ['id' => 'manager', 'name' => 'Manager', 'icon' => ''],
            ['id' => 'contributor', 'name' => 'Contributor', 'icon' => ''],
            ['id' => 'uploader', 'name' => 'Uploader', 'icon' => ''],
            ['id' => 'viewer', 'name' => 'Viewer', 'icon' => ''],
            ['id' => 'member', 'name' => 'Member', 'icon' => ''], // Deprecated
        ];

        // Get site permissions (company.manage, permissions.manage, ticket permissions, AI dashboard permissions, plus any custom site permissions)
        // Site permissions are identified by being in the site permissions list or having 'site.' prefix
        $sitePermissions = Permission::where(function ($query) {
                $query->whereIn('name', [
                    'company.manage',
                    'permissions.manage',
                    'assets.regenerate_thumbnails_admin', // Site role permission for thumbnail regeneration
                    'tickets.view_any',
                    'tickets.view_tenant',
                    'tickets.create',
                    'tickets.reply',
                    'tickets.view_staff',
                    'tickets.assign',
                    'tickets.add_internal_note',
                    'tickets.convert',
                    'tickets.view_sla',
                    'tickets.view_audit_log',
                    'tickets.create_engineering',
                    'tickets.view_engineering',
                    'tickets.link_diagnostic',
                    'ai.dashboard.view',
                    'ai.dashboard.manage',
                    'ai.budgets.view',
                    'ai.budgets.manage',
                    'assets.regenerate_thumbnails_admin', // Site role permission for thumbnail regeneration
                ])
                    ->orWhere('name', 'like', 'site.%');
            })
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        // Get company permissions - get all permissions that are NOT site permissions
        // Exclude staff-only ticket permissions (view_staff, assign, add_internal_note, convert, view_sla, view_audit_log, create_engineering, view_engineering, link_diagnostic)
        // Company roles should only have tenant-facing ticket permissions (create, reply, view_tenant, view_any)
        // Note: tickets.view_any and tickets.view_tenant are site permissions (for staff), but can also be assigned to company roles for tenant users
        $companyPermissions = Permission::where(function ($query) {
                $query->whereNotIn('name', [
                    'company.manage',
                    'permissions.manage',
                    'tickets.view_staff',
                    'tickets.assign',
                    'tickets.add_internal_note',
                    'tickets.convert',
                    'tickets.view_sla',
                    'tickets.view_audit_log',
                    'tickets.create_engineering',
                    'tickets.view_engineering',
                    'tickets.link_diagnostic',
                    'ai.dashboard.view',
                    'ai.dashboard.manage',
                    'ai.budgets.view',
                    'ai.budgets.manage',
                    'assets.regenerate_thumbnails_admin', // Exclude from company permissions
                ])
                    ->where('name', 'not like', 'site.%');
            })
            ->orderBy('name')
            ->pluck('name')
            ->toArray();
        
        // Add tenant-facing ticket permissions to company permissions (these can be assigned to company roles)
        // These are also site permissions, but they're dual-purpose: staff can use them, and tenant users can too
        $tenantTicketPermissions = ['tickets.create', 'tickets.reply', 'tickets.view_tenant', 'tickets.view_any'];
        foreach ($tenantTicketPermissions as $perm) {
            if (!in_array($perm, $companyPermissions)) {
                $companyPermissions[] = $perm;
            }
        }
        sort($companyPermissions);

        // Get current role permissions
        $siteRolePermissions = [];
        foreach ($siteRoles as $roleData) {
            $role = Role::where('name', $roleData['id'])->first();
            if ($role) {
                $permissions = $role->permissions->pluck('name')->toArray();
                $siteRolePermissions[$roleData['id']] = array_fill_keys($permissions, true);
            }
        }

        $companyRolePermissions = [];
        foreach ($companyRoles as $roleData) {
            $role = Role::where('name', $roleData['id'])->first();
            if ($role) {
                $permissions = $role->permissions->pluck('name')->toArray();
                $companyRolePermissions[$roleData['id']] = array_fill_keys($permissions, true);
            }
        }

        return Inertia::render('Admin/Permissions', [
            'site_roles' => $siteRoles,
            'company_roles' => $companyRoles,
            'site_permissions' => $sitePermissions,
            'company_permissions' => $companyPermissions,
            'site_role_permissions' => $siteRolePermissions,
            'company_role_permissions' => $companyRolePermissions,
        ]);
    }

    /**
     * Display the Stripe status page.
     */
    public function stripeStatus(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Laravel Cashier uses STRIPE_KEY and STRIPE_SECRET from env
        $stripeKey = env('STRIPE_KEY');
        $stripeSecret = env('STRIPE_SECRET');
        $hasKeys = !empty($stripeKey) && !empty($stripeSecret);
        
        // Test Stripe connection by making an API call
        $connectionTest = [
            'connected' => false,
            'error' => null,
        ];
        
        if ($hasKeys) {
            try {
                Stripe::setApiKey($stripeSecret);
                // Make a simple API call to verify connection
                $account = Account::retrieve();
                $connectionTest['connected'] = true;
                $connectionTest['account_id'] = $account->id ?? null;
                $connectionTest['account_name'] = $account->business_profile->name ?? $account->settings->dashboard->display_name ?? null;
            } catch (\Exception $e) {
                $connectionTest['connected'] = false;
                $connectionTest['error'] = $e->getMessage();
            }
        } else {
            $connectionTest['error'] = 'Stripe keys not configured (STRIPE_KEY and STRIPE_SECRET must be set in .env)';
        }

        // Check price sync status - verify prices in config exist in Stripe
        $priceSyncStatus = [];
        $plans = config('plans');
        
        if ($connectionTest['connected']) {
            try {
                foreach ($plans as $planKey => $planConfig) {
                    if ($planKey === 'free') {
                        // Free plan doesn't need a Stripe price
                        $priceSyncStatus[$planKey] = [
                            'name' => $planConfig['name'],
                            'price_id' => $planConfig['stripe_price_id'],
                            'exists' => true,
                            'note' => 'Free plan (no Stripe price required)',
                        ];
                        continue;
                    }
                    
                    $priceId = $planConfig['stripe_price_id'];
                    try {
                        $price = Price::retrieve($priceId);
                        $priceSyncStatus[$planKey] = [
                            'name' => $planConfig['name'],
                            'price_id' => $priceId,
                            'exists' => true,
                            'stripe_price_name' => $price->nickname ?? ($price->product ? 'Product: ' . $price->product : 'N/A'),
                            'amount' => $price->unit_amount ? '$' . number_format($price->unit_amount / 100, 2) : 'N/A',
                            'currency' => strtoupper($price->currency ?? 'usd'),
                            'active' => $price->active ?? false,
                        ];
                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        $priceSyncStatus[$planKey] = [
                            'name' => $planConfig['name'],
                            'price_id' => $priceId,
                            'exists' => false,
                            'error' => $e->getMessage(),
                        ];
                    } catch (\Exception $e) {
                        $priceSyncStatus[$planKey] = [
                            'name' => $planConfig['name'],
                            'price_id' => $priceId,
                            'exists' => false,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            } catch (\Exception $e) {
                // If we can't check prices, mark all as unknown
                foreach ($plans as $planKey => $planConfig) {
                    $priceSyncStatus[$planKey] = [
                        'name' => $planConfig['name'],
                        'price_id' => $planConfig['stripe_price_id'],
                        'exists' => null,
                        'error' => 'Could not verify: ' . $e->getMessage(),
                    ];
                }
            }
        } else {
            // If not connected, mark all prices as unknown
            foreach ($plans as $planKey => $planConfig) {
                $priceSyncStatus[$planKey] = [
                    'name' => $planConfig['name'],
                    'price_id' => $planConfig['stripe_price_id'],
                    'exists' => null,
                    'error' => 'Stripe not connected',
                ];
            }
        }

        // Get detailed tenants with Stripe accounts
        $tenantsWithStripe = Tenant::whereNotNull('stripe_id')
            ->with(['subscriptions', 'subscriptions.items'])
            ->get()
            ->map(function ($tenant) {
                // Query subscription directly instead of using Cashier's method (more reliable with Tenant model)
                $subscription = $tenant->subscriptions()
                    ->where('name', 'default')
                    ->orderBy('created_at', 'desc')
                    ->first();
                $planService = new \App\Services\PlanService();
                $currentPlan = $planService->getCurrentPlan($tenant);
                
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'stripe_id' => $tenant->stripe_id,
                    'current_plan' => $currentPlan,
                    'has_subscription' => $subscription !== null,
                    'subscription_status' => $subscription?->stripe_status,
                    'subscription_id' => $subscription?->stripe_id,
                    'created_at' => $tenant->created_at?->toDateTimeString(),
                ];
            });

        // Get all subscriptions with detailed info
        $allSubscriptions = Subscription::with(['items', 'owner'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($subscription) use ($connectionTest) {
                $tenant = $subscription->owner; // Cashier uses 'owner' relationship for billable model
                $planService = new \App\Services\PlanService();
                $currentPlan = $tenant ? $planService->getCurrentPlan($tenant) : 'unknown';
                
                // Calculate monthly revenue for this subscription
                $monthlyRevenue = 0;
                if ($subscription->stripe_price && $connectionTest['connected']) {
                    try {
                        $price = Price::retrieve($subscription->stripe_price);
                        if ($price->recurring->interval === 'month') {
                            $monthlyRevenue = ($price->unit_amount ?? 0) / 100;
                        } elseif ($price->recurring->interval === 'year') {
                            $monthlyRevenue = (($price->unit_amount ?? 0) / 100) / 12;
                        }
                    } catch (\Exception $e) {
                        // Price not found or error
                    }
                }
                
                return [
                    'id' => $subscription->id,
                    'tenant_id' => $subscription->tenant_id,
                    'tenant_name' => $tenant->name ?? 'Unknown',
                    'stripe_id' => $subscription->stripe_id,
                    'stripe_price' => $subscription->stripe_price,
                    'stripe_status' => $subscription->stripe_status,
                    'current_plan' => $currentPlan,
                    'monthly_revenue' => $monthlyRevenue,
                    'quantity' => $subscription->quantity ?? 1,
                    'trial_ends_at' => $subscription->trial_ends_at?->toDateTimeString(),
                    'ends_at' => $subscription->ends_at?->toDateTimeString(),
                    'created_at' => $subscription->created_at?->toDateTimeString(),
                    'updated_at' => $subscription->updated_at?->toDateTimeString(),
                ];
            });

        // Calculate MRR (Monthly Recurring Revenue)
        $mrr = $allSubscriptions
            ->where('stripe_status', 'active')
            ->sum('monthly_revenue');

        // Get subscription statistics
        $subscriptionStats = [
            'total' => $allSubscriptions->count(),
            'active' => $allSubscriptions->where('stripe_status', 'active')->count(),
            'canceled' => $allSubscriptions->where('stripe_status', 'canceled')->count(),
            'past_due' => $allSubscriptions->where('stripe_status', 'past_due')->count(),
            'trialing' => $allSubscriptions->where('stripe_status', 'trialing')->count(),
            'mrr' => round($mrr, 2),
        ];

        // Get recent webhook events from logs (last 100 lines)
        $webhookEvents = $this->getRecentWebhookEvents(50);

        return Inertia::render('Admin/StripeManagement', [
            'stripe_status' => [
                'connected' => $connectionTest['connected'],
                'has_keys' => $hasKeys,
                'error' => $connectionTest['error'],
                'account_id' => $connectionTest['account_id'] ?? null,
                'account_name' => $connectionTest['account_name'] ?? null,
                'last_check' => now()->toDateTimeString(),
            ],
            'price_sync_status' => $priceSyncStatus,
            'tenants_with_stripe' => $tenantsWithStripe,
            'subscriptions' => $allSubscriptions,
            'subscription_stats' => $subscriptionStats,
            'webhook_events' => $webhookEvents,
        ]);
    }

    /**
     * Get recent webhook events from logs.
     */
    private function getRecentWebhookEvents(int $limit = 50): array
    {
        $logFile = storage_path('logs/laravel.log');
        $events = [];
        
        if (!file_exists($logFile)) {
            return $events;
        }

        try {
            // Read last N lines of log file
            $lines = array_slice(file($logFile), -1000); // Read last 1000 lines for context
            $lines = array_reverse($lines); // Most recent first
            
            foreach ($lines as $line) {
                if (count($events) >= $limit) {
                    break;
                }
                
                // Look for webhook log entries
                if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*Stripe webhook received.*"type":"([^"]+)".*"id":"([^"]+)"/', $line, $matches)) {
                    $events[] = [
                        'timestamp' => $matches[1],
                        'type' => $matches[2],
                        'id' => $matches[3] ?? null,
                        'status' => 'success',
                    ];
                } elseif (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*Error handling Stripe webhook.*"type":"([^"]+)".*"error":"([^"]+)"/', $line, $matches)) {
                    $events[] = [
                        'timestamp' => $matches[1],
                        'type' => $matches[2],
                        'id' => null,
                        'status' => 'error',
                        'error' => $matches[3] ?? 'Unknown error',
                    ];
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Error reading webhook events from logs: ' . $e->getMessage());
        }

        return array_slice($events, 0, $limit);
    }

    /**
     * Save site role permissions.
     */
    public function saveSiteRolePermissions(Request $request)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $validated = $request->validate([
            'role_id' => 'required|string|in:site_owner,site_admin,site_support,site_engineering,compliance',
            'permissions' => 'required|array',
        ]);

        $role = Role::where('name', $validated['role_id'])->firstOrFail();
        
        // Get only valid site permissions (all site permissions start with specific prefixes)
        $validPermissions = Permission::where(function ($query) {
                $query->whereIn('name', [
                    'company.manage',
                    'permissions.manage',
                    'assets.regenerate_thumbnails_admin',
                    'tickets.view_any',
                    'tickets.view_tenant',
                    'tickets.create',
                    'tickets.reply',
                    'tickets.view_staff',
                    'tickets.assign',
                    'tickets.add_internal_note',
                    'tickets.convert',
                    'tickets.view_sla',
                    'tickets.view_audit_log',
                    'tickets.create_engineering',
                    'tickets.view_engineering',
                    'tickets.link_diagnostic',
                    'ai.dashboard.view',
                    'ai.dashboard.manage',
                    'ai.budgets.view',
                    'ai.budgets.manage',
                    'assets.regenerate_thumbnails_admin',
                ])
                ->orWhere('name', 'like', 'site.%');
            })
            ->pluck('name')
            ->toArray();

        // Filter permissions to only include valid ones and those that are true
        $permissionsToAssign = [];
        foreach ($validated['permissions'] as $permission => $enabled) {
            if ($enabled && in_array($permission, $validPermissions)) {
                $permissionsToAssign[] = $permission;
            }
        }

        // Sync permissions (remove all and add selected ones)
        $permissionModels = Permission::whereIn('name', $permissionsToAssign)->get();
        $role->syncPermissions($permissionModels);

        return back()->with('success', 'Site role permissions updated successfully.');
    }

    /**
     * Save company role permissions.
     */
    public function saveCompanyRolePermissions(Request $request)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $validated = $request->validate([
            'role_id' => 'required|string|in:owner,admin,brand_manager,member',
            'permissions' => 'required|array',
        ]);

        $role = Role::where('name', $validated['role_id'])->firstOrFail();
        
        // Get only valid company permissions (all permissions except site permissions)
        // Exclude site-only permissions like assets.regenerate_thumbnails_admin
        $validPermissions = Permission::where(function ($query) {
                $query->whereNotIn('name', [
                    'company.manage',
                    'permissions.manage',
                    'assets.regenerate_thumbnails_admin', // Site-only permission
                ])
                    ->where('name', 'not like', 'site.%');
            })
            ->pluck('name')
            ->toArray();

        // Filter permissions to only include valid ones and those that are true
        $permissionsToAssign = [];
        foreach ($validated['permissions'] as $permission => $enabled) {
            if ($enabled && in_array($permission, $validPermissions)) {
                $permissionsToAssign[] = $permission;
            }
        }

        // Sync permissions (remove all and add selected ones)
        $permissionModels = Permission::whereIn('name', $permissionsToAssign)->get();
        $role->syncPermissions($permissionModels);

        return back()->with('success', 'Company role permissions updated successfully.');
    }

    /**
     * Create a new permission.
     */
    public function createPermission(Request $request)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|regex:/^[a-z0-9._]+$/',
            'type' => 'required|string|in:company,site',
        ]);

        // For site permissions, prefix with 'site.' if not already prefixed
        $permissionName = $validated['name'];
        if ($validated['type'] === 'site' && !str_starts_with($permissionName, 'site.')) {
            $permissionName = 'site.' . $permissionName;
        }

        // Check if permission already exists
        $existingPermission = Permission::where('name', $permissionName)->first();
        if ($existingPermission) {
            return back()->withErrors([
                'name' => 'A permission with this name already exists.',
            ]);
        }

        // Create the permission
        $permission = Permission::create([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);

        return back()->with('success', "Permission '{$permission->name}' created successfully. Use this slug in your frontend PermissionGate: '{$permission->name}'");
    }

    /**
     * Manually sync a subscription from Stripe.
     */
    public function syncSubscription(Request $request, Tenant $tenant)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        if (!$tenant->stripe_id) {
            return back()->withErrors(['error' => 'Tenant does not have a Stripe customer ID.']);
        }

        try {
            Stripe::setApiKey(env('STRIPE_SECRET'));
            
            // Get customer's subscriptions from Stripe
            $stripeCustomer = \Stripe\Customer::retrieve($tenant->stripe_id);
            $stripeSubscriptions = \Stripe\Subscription::all([
                'customer' => $tenant->stripe_id,
                'limit' => 10,
            ]);

            // Sync each subscription
            foreach ($stripeSubscriptions->data as $stripeSubscription) {
                $subscription = $tenant->subscriptions()->firstOrNew([
                    'stripe_id' => $stripeSubscription->id,
                ]);

                $subscription->name = 'default';
                $subscription->stripe_status = $stripeSubscription->status;
                $subscription->stripe_price = $stripeSubscription->items->data[0]->price->id ?? null;
                $subscription->quantity = $stripeSubscription->items->data[0]->quantity ?? 1;
                $subscription->trial_ends_at = $stripeSubscription->trial_end 
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end) 
                    : null;
                $subscription->ends_at = $stripeSubscription->cancel_at 
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->cancel_at) 
                    : null;
                $subscription->save();

                // Sync subscription items
                foreach ($stripeSubscription->items->data as $item) {
                    $subscriptionItem = $subscription->items()->firstOrNew([
                        'stripe_id' => $item->id,
                    ]);
                    $subscriptionItem->stripe_product = $item->price->product;
                    $subscriptionItem->stripe_price = $item->price->id;
                    $subscriptionItem->quantity = $item->quantity ?? 1;
                    $subscriptionItem->save();
                }
            }

            return back()->with('success', 'Subscription synced successfully from Stripe.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to sync subscription: ' . $e->getMessage()]);
        }
    }

    /**
     * Process a refund for a tenant.
     */
    public function processRefund(Request $request)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'invoice_id' => 'required|string',
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $billingService = new \App\Services\BillingService();

        try {
            $amount = $validated['amount'] ? (int)($validated['amount'] * 100) : null; // Convert to cents
            $refund = $billingService->refundInvoice(
                $tenant,
                $validated['invoice_id'],
                $amount,
                $validated['reason'] ?? null
            );

            return back()->with('success', 'Refund processed successfully. Refund ID: ' . $refund['id']);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to process refund: ' . $e->getMessage()]);
        }
    }

    /**
     * Display activity logs page.
     */
    public function activityLogs(Request $request): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $query = \App\Models\ActivityEvent::query();

        // Filter by tenant
        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        // Filter by event type
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        // Filter by actor type
        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->actor_type);
        }

        // Filter by subject type
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Filter by brand
        if ($request->filled('brand_id')) {
            if ($request->brand_id === 'null') {
                $query->whereNull('brand_id');
            } else {
                $query->where('brand_id', $request->brand_id);
            }
        }

        // Search functionality
        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
            $query->where(function ($q) use ($searchTerm, $request) {
                // Search in event type
                $q->where('activity_events.event_type', 'like', $searchTerm)
                    // Search in tenant name (via left join)
                    ->orWhereExists(function ($subQuery) use ($searchTerm) {
                        $subQuery->select(DB::raw(1))
                            ->from('tenants')
                            ->whereColumn('tenants.id', 'activity_events.tenant_id')
                            ->where('tenants.name', 'like', $searchTerm);
                    })
                    // Search in brand name (via left join)
                    ->orWhereExists(function ($subQuery) use ($searchTerm) {
                        $subQuery->select(DB::raw(1))
                            ->from('brands')
                            ->whereColumn('brands.id', 'activity_events.brand_id')
                            ->where('brands.name', 'like', $searchTerm);
                    })
                    // Search in actor user (name/email) - only for user actor type
                    ->orWhere(function ($actorQuery) use ($searchTerm) {
                        $actorQuery->where('activity_events.actor_type', 'user')
                            ->whereExists(function ($subQuery) use ($searchTerm) {
                                $subQuery->select(DB::raw(1))
                                    ->from('users')
                                    ->whereColumn('users.id', 'activity_events.actor_id')
                                    ->where(function ($userQuery) use ($searchTerm) {
                                        $userQuery->where('users.first_name', 'like', $searchTerm)
                                            ->orWhere('users.last_name', 'like', $searchTerm)
                                            ->orWhere('users.email', 'like', $searchTerm);
                                    });
                            });
                    })
                    // Search in subject - brands
                    ->orWhere(function ($subjectQuery) use ($searchTerm) {
                        $subjectQuery->where('activity_events.subject_type', 'App\\Models\\Brand')
                            ->whereExists(function ($subQuery) use ($searchTerm) {
                                $subQuery->select(DB::raw(1))
                                    ->from('brands')
                                    ->whereColumn('brands.id', 'activity_events.subject_id')
                                    ->where('brands.name', 'like', $searchTerm);
                            });
                    })
                    // Search in subject - tenants
                    ->orWhere(function ($subjectQuery) use ($searchTerm) {
                        $subjectQuery->where('activity_events.subject_type', 'App\\Models\\Tenant')
                            ->whereExists(function ($subQuery) use ($searchTerm) {
                                $subQuery->select(DB::raw(1))
                                    ->from('tenants')
                                    ->whereColumn('tenants.id', 'activity_events.subject_id')
                                    ->where('tenants.name', 'like', $searchTerm);
                            });
                    })
                    // Search in subject - users
                    ->orWhere(function ($subjectQuery) use ($searchTerm) {
                        $subjectQuery->where('activity_events.subject_type', 'App\\Models\\User')
                            ->whereExists(function ($subQuery) use ($searchTerm) {
                                $subQuery->select(DB::raw(1))
                                    ->from('users')
                                    ->whereColumn('users.id', 'activity_events.subject_id')
                                    ->where(function ($userQuery) use ($searchTerm) {
                                        $userQuery->where('users.first_name', 'like', $searchTerm)
                                            ->orWhere('users.last_name', 'like', $searchTerm)
                                            ->orWhere('users.email', 'like', $searchTerm);
                                    });
                            });
                    })
                    // Search in metadata JSON (search as text for brand name changes, etc.)
                    ->orWhereRaw('CAST(metadata AS CHAR) LIKE ?', [$searchTerm]);
            });
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        // Paginate results
        $perPage = (int) $request->get('per_page', 50);
        // Only eager load actor for 'user' type to avoid errors with string types (system, api, guest)
        $events = $query->with(['tenant', 'brand', 'subject'])
            ->paginate($perPage)
            ->appends($request->except('page'));

        // Get filter options
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);
        $brands = \App\Models\Brand::orderBy('name')->get(['id', 'name', 'tenant_id']);
        $eventTypes = \App\Enums\EventType::all();
        $actorTypes = ['user', 'system', 'api', 'guest'];
        
        // Get unique subject types from recent events
        $subjectTypes = \App\Models\ActivityEvent::select('subject_type')
            ->distinct()
            ->whereNotNull('subject_type')
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->toArray();

        // Format events for display
        $planService = new \App\Services\PlanService();
        $formattedEvents = $events->map(function ($event) use ($planService) {
            $tenant = $event->tenant;
            $hasPaidPlan = false;
            
            if ($tenant) {
                $currentPlan = $planService->getCurrentPlan($tenant);
                $hasPaidPlan = $currentPlan !== 'free';
            }
            
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'created_at' => $event->created_at->toDateTimeString(),
                'created_at_human' => $event->created_at->diffForHumans(),
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'has_paid_plan' => $hasPaidPlan,
                ] : null,
                'brand' => $event->brand ? [
                    'id' => $event->brand->id,
                    'name' => $event->brand->name,
                    'logo_path' => $event->brand->logo_path,
                    'icon_path' => $event->brand->icon_path,
                    'primary_color' => $event->brand->primary_color,
                    'icon' => $event->brand->icon,
                    'icon_bg_color' => $event->brand->icon_bg_color,
                ] : null,
                'actor' => $this->formatActor($event),
                'subject' => $this->formatSubject($event),
                'metadata' => $event->metadata,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
            ];
        });

        return Inertia::render('Admin/ActivityLogs', [
            'events' => $formattedEvents,
            'pagination' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
            'filters' => [
                'tenant_id' => $request->tenant_id,
                'event_type' => $request->event_type,
                'actor_type' => $request->actor_type,
                'subject_type' => $request->subject_type,
                'brand_id' => $request->brand_id,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'per_page' => $perPage,
                'search' => $request->search,
            ],
            'filter_options' => [
                'tenants' => $tenants,
                'brands' => $brands,
                'event_types' => $eventTypes,
                'actor_types' => $actorTypes,
                'subject_types' => $subjectTypes,
            ],
        ]);
    }

    /**
     * Format actor for display.
     */
    private function formatActor($event): ?array
    {
        if (!$event->actor_type) {
            return [
                'type' => 'unknown',
                'name' => 'Unknown',
            ];
        }

        // Handle string actor types (system, api, guest) that aren't models
        $stringActorTypes = ['system', 'api', 'guest'];
        if (in_array($event->actor_type, $stringActorTypes, true)) {
            // Check if metadata contains admin info (admin-initiated system action)
            $metadata = $event->metadata ?? [];
            if (isset($metadata['admin_id']) || isset($metadata['admin_name'])) {
                // Try to load admin user for avatar
                $adminUser = null;
                if (isset($metadata['admin_id'])) {
                    try {
                        $adminUser = \App\Models\User::find($metadata['admin_id']);
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
                
                return [
                    'type' => 'admin',
                    'name' => $metadata['admin_name'] ?? 'Site Admin',
                    'first_name' => $adminUser?->first_name,
                    'last_name' => $adminUser?->last_name,
                    'email' => $metadata['admin_email'] ?? $adminUser?->email,
                    'avatar_url' => $adminUser?->avatar_url,
                    'admin_id' => $metadata['admin_id'] ?? null,
                    'is_system_action' => true, // Flag to indicate this was a system action initiated by admin
                ];
            }
            return [
                'type' => $event->actor_type,
                'name' => ucfirst($event->actor_type),
            ];
        }

        // For 'user' type, manually load the User model to avoid relationship errors
        // Also handle 'App\Models\User' class name format
        $isUserType = $event->actor_type === 'user' || 
                     $event->actor_type === 'App\\Models\\User' ||
                     str_contains($event->actor_type, 'User');
        
        if ($isUserType && $event->actor_id) {
            try {
                $user = \App\Models\User::find($event->actor_id);
                if ($user) {
                    return [
                        'type' => 'user',
                        'id' => $user->id,
                        'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'avatar_url' => $user->avatar_url,
                    ];
                }
            } catch (\Exception $e) {
                // If user not found, fall through to default
            }
        }

        // Handle class name formats (e.g., "App\Models\User")
        if (str_contains($event->actor_type, '\\')) {
            $className = class_basename($event->actor_type);
            return [
                'type' => strtolower($className),
                'name' => $className,
                'raw_type' => $event->actor_type,
            ];
        }

        return [
            'type' => $event->actor_type,
            'name' => ucfirst($event->actor_type),
        ];
    }

    /**
     * Format subject for display.
     */
    private function formatSubject($event): ?array
    {
        if (!$event->subject_type || !$event->subject_id) {
            return null;
        }

        if ($event->subject) {
            $name = match (true) {
                method_exists($event->subject, 'name') => $event->subject->name,
                method_exists($event->subject, 'title') => $event->subject->title,
                method_exists($event->subject, 'email') => $event->subject->email,
                default => '#' . $event->subject->id,
            };

            return [
                'type' => $event->subject_type,
                'id' => $event->subject_id,
                'name' => $name,
            ];
        }

        return [
            'type' => $event->subject_type,
            'id' => $event->subject_id,
            'name' => '#' . $event->subject_id,
        ];
    }

    /**
     * Get the plan prefix label (Comped, Trial, Forced, etc.)
     * 
     * Uses billing_status for comped/trial accounts instead of generic "Override"
     * This is internal-only labeling for admin purposes.
     */
    private function getPlanPrefix(Tenant $tenant, string $planName, $latestSubscription, $planService): ?string
    {
        // Check billing_status first - use specific labels for comped/trial
        // billing_status is NOT frontend-facing - internal admin labeling only
        if ($tenant->billing_status === 'comped') {
            return 'Comped'; // No revenue, expenses still apply
        }
        
        if ($tenant->billing_status === 'trial') {
            return 'Trial'; // No revenue during trial, expenses still apply
        }
        
        // Check if manually overridden (legacy - before billing_status was added)
        // For accounts with manual_plan_override but no billing_status set, treat as comped
        if ($tenant->manual_plan_override && !$tenant->stripe_id) {
            // TODO: Set billing_status to 'comped' when updating plan manually
            return 'Comped';
        }
        
        // Check if externally managed (e.g., Shopify)
        if ($planService->isExternallyManaged($tenant)) {
            return 'Forced';
        }
        
        // Check if not paid (no active subscription) but not free plan
        if ($planName !== 'free' && (!$latestSubscription || $latestSubscription->stripe_status !== 'active')) {
            return 'Forced';
        }
        
        return null;
    }

    /**
     * Reset/clear all subscriptions for a tenant.
     * This is useful when a subscription is stuck in an incomplete state
     * or when payment method issues prevent subscription updates.
     */
    public function resetSubscriptions(Request $request, Tenant $tenant)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can reset subscriptions.');
        }

        try {
            $stripeSecret = env('STRIPE_SECRET');
            if ($stripeSecret) {
                Stripe::setApiKey($stripeSecret);
            }

            // Cancel all subscriptions in Stripe first
            $subscriptions = $tenant->subscriptions()->get();
            foreach ($subscriptions as $subscription) {
                if ($subscription->stripe_id) {
                    try {
                        if ($stripeSecret) {
                            $stripeSubscription = \Stripe\Subscription::retrieve($subscription->stripe_id);
                            // Only cancel if it's not already canceled
                            if ($stripeSubscription->status !== 'canceled' && $stripeSubscription->status !== 'incomplete_expired') {
                                $stripeSubscription->cancel();
                            }
                        }
                    } catch (\Exception $e) {
                        // Log but continue - subscription might already be canceled
                        \Log::warning('Failed to cancel Stripe subscription', [
                            'subscription_id' => $subscription->stripe_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Delete all subscription records from database
            $tenant->subscriptions()->delete();

            // Optionally: Clear Stripe customer ID to force fresh subscription creation
            // Uncomment the line below if you want to completely reset the Stripe relationship
            // $tenant->stripe_id = null;
            // $tenant->pm_type = null;
            // $tenant->pm_last_four = null;
            // $tenant->trial_ends_at = null;
            // $tenant->save();

            // Reset manual plan override (force them back to free plan)
            $tenant->manual_plan_override = null;
            $tenant->save();

            return back()->with('success', "All subscriptions for {$tenant->name} have been reset. They can now create a new subscription.");
        } catch (\Exception $e) {
            return back()->withErrors([
                'subscription' => 'Failed to reset subscriptions: ' . $e->getMessage(),
            ]);
        }
    }
}
