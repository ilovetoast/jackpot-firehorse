<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function index(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }
        $companies = Tenant::with(['brands', 'users'])->get();
        
        $stats = [
            'total_companies' => Tenant::count(),
            'total_brands' => Brand::count(),
            'total_users' => User::count(),
            'active_subscriptions' => Subscription::where('stripe_status', 'active')->count(),
            'stripe_accounts' => Tenant::whereNotNull('stripe_id')->count(),
            'support_tickets' => 0, // Placeholder for future implementation
        ];

        // Get all users with their companies and roles
        $allUsers = User::with(['tenants', 'brands.tenant'])->get()->map(function ($user) {
            // Get site roles from Spatie (global)
            $userRoles = $user->getRoleNames()->toArray();
            $siteRoles = array_unique(array_filter($userRoles, fn($role) => str_contains(strtolower($role), 'site')));
            
            return [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'companies_count' => $user->tenants->count(),
                'brands_count' => $user->brands->count(),
                'site_roles' => array_values($siteRoles),
                'companies' => $user->tenants->map(function ($tenant) {
                    // Get user's role in this specific company from pivot table
                    $role = $tenant->pivot->role ?? null;
                    
                    return [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                        'role' => $role ? ucfirst($role) : null,
                    ];
                }),
                'brands' => $user->brands->map(function ($brand) {
                    return [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'tenant_id' => $brand->tenant_id,
                        'tenant_name' => $brand->tenant->name ?? null,
                        'role' => $brand->pivot->role ?? null,
                    ];
                }),
            ];
        });

        // Get all users for the selector (all users in the system)
        $allUsersForSelector = User::all()->map(fn ($user) => [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ]);

        $planService = app(\App\Services\PlanService::class);
        
        return Inertia::render('Admin/Index', [
            'companies' => $companies->map(function ($company) use ($planService) {
                // Get owner - first try to find user with "owner" role in pivot, otherwise fallback to first user by created_at
                $owner = null;
                $usersWithOwnerRole = $company->users()->get()->filter(function ($user) {
                    $role = $user->pivot->role ?? null;
                    return strtolower($role ?? '') === 'owner';
                });
                
                if ($usersWithOwnerRole->isNotEmpty()) {
                    $owner = $usersWithOwnerRole->first();
                } else {
                    // Fallback to first user by created_at
                    $owner = $company->users()->orderBy('created_at')->first();
                }
                
                // Get plan info
                $planName = $planService->getCurrentPlan($company);
                $planConfig = config("plans.{$planName}", config('plans.free'));
                $planDisplayName = ucfirst($planName);
                
                // Check Stripe connection
                $stripeConnected = !empty($company->stripe_id);
                $stripeStatus = $company->subscribed() ? 'active' : ($stripeConnected ? 'inactive' : 'not_connected');
                
                // Check if company has access to brand_manager role
                $hasAccessToBrandManager = $planService->hasAccessToBrandManagerRole($company);
                
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                    'brands_count' => $company->brands->count(),
                    'users_count' => $company->users->count(),
                    'plan' => $planDisplayName,
                    'plan_name' => $planName,
                    'stripe_connected' => $stripeConnected,
                    'stripe_status' => $stripeStatus,
                    'has_access_to_brand_manager' => $hasAccessToBrandManager,
                    'owner' => $owner ? [
                        'id' => $owner->id,
                        'name' => trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')),
                        'email' => $owner->email,
                    ] : null,
                    'created_at' => $company->created_at?->format('M d, Y'),
                    'brands' => $company->brands->map(fn ($brand) => [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'is_default' => $brand->is_default,
                    ]),
                    'users' => (function() use ($company) {
                        // Get first user ID for fallback role determination
                        $firstUserId = $company->users()->orderBy('created_at')->first()?->id;
                        
                        return $company->users->map(function ($user) use ($company, $firstUserId) {
                            // Get role from pivot table (tenant-scoped)
                            $role = $user->pivot->role;
                            
                            // If no role in pivot, fallback: first user is owner
                            if (empty($role)) {
                                $isFirstUser = $firstUserId && $firstUserId === $user->id;
                                $role = $isFirstUser ? 'owner' : 'member';
                            }
                        
                        return [
                            'id' => $user->id,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'email' => $user->email,
                            'role' => strtolower($role),
                        ];
                        });
                    })(),
                ];
            }),
            'users' => $allUsers,
            'all_users' => $allUsersForSelector,
            'stats' => $stats,
        ]);
    }

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
        
        $allowedRoles = ['owner', 'admin', 'member'];
        if ($hasAccessToBrandManager) {
            $allowedRoles[] = 'brand_manager';
        }
        
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => ['nullable', 'string', 'in:' . implode(',', $allowedRoles)],
            'brand_ids' => 'nullable|array',
            'brand_ids.*' => 'exists:brands,id',
        ]);

        $user = User::findOrFail($validated['user_id']);

        // Check if user is already in this company
        if ($tenant->users()->where('users.id', $user->id)->exists()) {
            return back()->withErrors([
                'user' => 'User is already a member of this company.',
            ]);
        }

        // Add user to company with role in pivot table
        $tenant->users()->attach($user->id, ['role' => $validated['role'] ?? null]);

        // Add user to selected brands (or all brands if none specified)
        if (!empty($validated['brand_ids'])) {
            // Validate that all brand IDs belong to this tenant
            $brandIds = $validated['brand_ids'];
            $validBrandIds = $tenant->brands()->whereIn('id', $brandIds)->pluck('id')->toArray();
            
            if (count($validBrandIds) !== count($brandIds)) {
                return back()->withErrors([
                    'brand_ids' => 'One or more selected brands do not belong to this company.',
                ]);
            }
            
            $brands = $tenant->brands()->whereIn('id', $validBrandIds)->get();
        } else {
            // If no brands specified, add to all brands in the company
            $brands = $tenant->brands;
        }

        foreach ($brands as $brand) {
            if (!$brand->users()->where('users.id', $user->id)->exists()) {
                $brand->users()->attach($user->id, ['role' => 'member']);
            }
        }

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

        // Update role in pivot table (tenant-scoped)
        $user->setRoleForTenant($tenant, $validated['role']);

        return back()->with('success', 'User role updated successfully.');
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
            'role' => 'required|string|in:site_owner,site_admin,site_support,compliance',
        ]);

        // Remove existing site roles
        $user->removeRole(['site_owner', 'site_admin', 'site_support', 'compliance']);
        
        // Assign the new site role
        $user->assignRole($validated['role']);

        return back()->with('success', 'Site role assigned successfully.');
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
            ['id' => 'compliance', 'name' => 'Compliance', 'icon' => ''],
        ];

        // Get company roles
        $companyRoles = [
            ['id' => 'owner', 'name' => 'Owner', 'icon' => '👑'],
            ['id' => 'admin', 'name' => 'Admin', 'icon' => ''],
            ['id' => 'brand_manager', 'name' => 'Brand Manager', 'icon' => ''],
            ['id' => 'member', 'name' => 'Member', 'icon' => ''],
        ];

        // Get site permissions (company.manage and permissions.manage, plus any custom site permissions)
        // Site permissions are identified by being in the site permissions list or having 'site.' prefix
        $sitePermissions = Permission::where(function ($query) {
                $query->whereIn('name', ['company.manage', 'permissions.manage'])
                    ->orWhere('name', 'like', 'site.%');
            })
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        // Get company permissions - get all permissions that are NOT site permissions
        $companyPermissions = Permission::where(function ($query) {
                $query->whereNotIn('name', ['company.manage', 'permissions.manage'])
                    ->where('name', 'not like', 'site.%');
            })
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

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

        // Get tenants with Stripe accounts
        $tenantsWithStripe = Tenant::whereNotNull('stripe_id')->get()->map(fn ($tenant) => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'stripe_id' => $tenant->stripe_id,
        ]);

        // Get active subscriptions
        $subscriptions = Subscription::where('stripe_status', 'active')
            ->get()
            ->map(function ($subscription) {
                $tenant = Tenant::find($subscription->tenant_id);
                return [
                    'id' => $subscription->id,
                    'tenant_name' => $tenant->name ?? 'Unknown',
                    'stripe_price' => $subscription->stripe_price,
                    'stripe_status' => $subscription->stripe_status,
                    'ends_at' => $subscription->ends_at?->toDateTimeString(),
                ];
            });

        return Inertia::render('Admin/StripeStatus', [
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
            'subscriptions' => $subscriptions,
        ]);
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
            'role_id' => 'required|string|in:site_owner,site_admin,site_support,compliance',
            'permissions' => 'required|array',
        ]);

        $role = Role::where('name', $validated['role_id'])->firstOrFail();
        
        // Get only valid site permissions
        $validPermissions = Permission::where(function ($query) {
                $query->whereIn('name', ['company.manage', 'permissions.manage'])
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
        $validPermissions = Permission::where(function ($query) {
                $query->whereNotIn('name', ['company.manage', 'permissions.manage'])
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
}
