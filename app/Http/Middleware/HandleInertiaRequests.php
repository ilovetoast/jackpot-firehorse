<?php

namespace App\Http\Middleware;

use App\Services\FeatureGate;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Role;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $currentTenantId = session('tenant_id');
        
        // Resolve tenant if not already bound (HandleInertiaRequests runs before ResolveTenant middleware)
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (! $tenant && $currentTenantId) {
            $tenant = \App\Models\Tenant::find($currentTenantId);
            if ($tenant) {
                app()->instance('tenant', $tenant);
            }
        }
        
        // Resolve active brand if not already bound
        // Note: ResolveTenant middleware will handle brand access verification
        // This is just for early resolution if tenant is available
        $activeBrand = app()->bound('brand') ? app('brand') : null;
        if (! $activeBrand && $tenant) {
            $brandId = session('brand_id');
            if ($brandId) {
                $activeBrand = \App\Models\Brand::where('id', $brandId)
                    ->where('tenant_id', $tenant->id)
                    ->first();
                
                // Verify user has access to this brand (unless owner/admin)
                if ($activeBrand && $user) {
                    $tenantRole = $user->getRoleForTenant($tenant);
                    $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
                    
                    // Phase MI-1: Verify user has active brand membership (unless owner/admin)
                    if (! $isTenantOwnerOrAdmin) {
                        $membership = $user->activeBrandMembership($activeBrand);
                        $hasBrandAccess = $membership !== null;
                        
                        if (! $hasBrandAccess) {
                            // User doesn't have active membership - find a brand they do have access to
                            $userBrand = null;
                            foreach ($tenant->brands as $brand) {
                                if ($user->activeBrandMembership($brand)) {
                                    $userBrand = $brand;
                                    break;
                                }
                            }
                            
                            if ($userBrand) {
                                $activeBrand = $userBrand;
                                session(['brand_id' => $activeBrand->id]);
                            } else {
                                // No accessible brand - use default (policies will restrict access)
                                $activeBrand = $tenant->defaultBrand;
                                if ($activeBrand) {
                                    session(['brand_id' => $activeBrand->id]);
                                }
                            }
                        }
                    }
                }
            }
            if (! $activeBrand) {
                // Try to find a brand the user has access to
                if ($user) {
                    $tenantRole = $user->getRoleForTenant($tenant);
                    $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
                    
                    if (! $isTenantOwnerOrAdmin) {
                        $userBrand = $user->brands()
                            ->where('tenant_id', $tenant->id)
                            ->first();
                        
                        if ($userBrand) {
                            $activeBrand = $userBrand;
                            session(['brand_id' => $activeBrand->id]);
                        }
                    }
                }
                
                // Fallback to default brand
                if (! $activeBrand) {
                    $activeBrand = $tenant->defaultBrand;
                    if ($activeBrand) {
                        session(['brand_id' => $activeBrand->id]);
                    }
                }
            }
            if ($activeBrand) {
                app()->instance('brand', $activeBrand);
            }
        }

        // Get all brands for the active tenant that are visible in selector
        $brands = [];
        $brandLimitExceeded = false;
        if ($tenant && $currentTenantId) {
            try {
                $planService = app(PlanService::class);
                $limits = $planService->getPlanLimits($tenant);
                $currentBrandCount = $tenant->brands()->count();
                $maxBrands = $limits['max_brands'] ?? PHP_INT_MAX;
                $brandLimitExceeded = $currentBrandCount > $maxBrands;

                // Check if user is tenant owner/admin - they see ALL brands (ignoring show_in_selector)
                $tenantRole = null;
                $isTenantOwnerOrAdmin = false;
                if ($user) {
                    $tenantRole = $user->getRoleForTenant($tenant);
                    $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
                }
                
                // Phase MI-1: Get brands the user has active membership for (removed_at IS NULL)
                $userBrandIds = [];
                if ($user) {
                    foreach ($tenant->brands as $brand) {
                        if ($user->activeBrandMembership($brand)) {
                            $userBrandIds[] = $brand->id;
                        }
                    }
                }
                
                // Get all brands for the tenant
                // Order by default first, then by name for consistent ordering
                $allBrands = $tenant->brands()
                    ->orderBy('is_default', 'desc')
                    ->orderBy('name')
                    ->get();


                // Filter brands based on user role and access
                $accessibleBrands = $allBrands->filter(function ($brand) use ($userBrandIds, $activeBrand, $isTenantOwnerOrAdmin, $tenant, $user) {
                    // Tenant owners/admins see ALL brands (ignoring show_in_selector flag)
                    // This is bulletproof: owners/admins always see all brands for their tenant
                    if ($isTenantOwnerOrAdmin) {
                        return true;
                    }
                    
                    // For regular members: check if they have explicit access via brand_user pivot table
                    // If they have a role on the brand, they should see it regardless of show_in_selector
                    // This ensures anyone with a role and access to the brand can see it
                    $hasBrandAccess = in_array($brand->id, $userBrandIds);
                    if ($hasBrandAccess) {
                        // User has explicit access to this brand (has a role) - always show it
                        return true;
                    }
                    
                    // If user doesn't have explicit brand access, they shouldn't see it
                    // (show_in_selector is only for general visibility, but explicit access trumps it)
                    // NOTE: We do NOT include active brand if user doesn't have access - this prevents
                    // orphaned brand access where session has a brand_id but user was removed from brand
                    return false;
                });


                // Determine which brands are disabled (those beyond the limit)
                // If limit is exceeded, brands beyond the limit count are disabled
                // IMPORTANT: Even admins/owners cannot access disabled brands - plan limits apply to everyone
                // However, the active brand should never be disabled (can't switch away from it)
                $brands = $accessibleBrands->values()->map(function ($brand, $index) use ($activeBrand, $maxBrands, $brandLimitExceeded) {
                    $isActive = $activeBrand && $brand->id === $activeBrand->id;
                    // Brands beyond the limit are disabled (but still shown so user knows they exist)
                    // Index is 0-based, so index >= maxBrands means it's beyond the limit
                    // But never disable the active brand (user must be able to see their current brand)
                    // Plan limits apply to EVERYONE, including admins/owners
                    $isDisabled = $brandLimitExceeded && ($index >= $maxBrands) && !$isActive;
                    
                    $brandData = [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'logo_path' => $brand->logo_path,
                        'is_default' => $brand->is_default,
                        'is_active' => $isActive,
                        'is_disabled' => $isDisabled,
                        'logo_filter' => $brand->logo_filter ?? 'none',
                        'primary_color' => $brand->primary_color,
                        'show_in_selector' => $brand->show_in_selector ?? true,
                    ];
                    
                    return $brandData;
                });
                
                // Calculate plan limit info for alerts
                $disabledBrandsCount = $brands->where('is_disabled', true)->count();
                
                // Get user limit info
                $currentUserCount = $tenant->users()->count();
                $maxUsers = $limits['max_users'] ?? PHP_INT_MAX;
                $userLimitExceeded = $currentUserCount > $maxUsers;
                
                // Get enabled/disabled users for this tenant
                $enabledUsers = $tenant->getEnabledUsers($planService);
                $disabledUserIds = $enabledUsers['disabled'];
                $isUserDisabled = $user && in_array($user->id, $disabledUserIds);
                
                $planLimitInfo = [
                    'brand_limit_exceeded' => $brandLimitExceeded,
                    'current_brand_count' => $currentBrandCount,
                    'max_brands' => $maxBrands,
                    'disabled_brands_count' => $disabledBrandsCount,
                    'disabled_brand_names' => $brands->where('is_disabled', true)->pluck('name')->toArray(),
                    'plan_name' => $planService->getCurrentPlan($tenant),
                    'user_limit_exceeded' => $userLimitExceeded,
                    'current_user_count' => $currentUserCount,
                    'max_users' => $maxUsers,
                    'disabled_user_ids' => $disabledUserIds,
                    'is_user_disabled' => $isUserDisabled,
                ];
            } catch (\Exception $e) {
                // If there's an error loading brands, just use empty array
                $brands = [];
                $planLimitInfo = null;
            }
        }

        // Get user permissions and roles for current tenant
        $permissions = [];
        $roles = [];
        $tenantRole = null;
        $rolePermissions = [];
        $permissions = []; // Initialize as empty array
        $roles = []; // Initialize as empty array
        
        if ($user && $currentTenantId && $tenant) {
            // Refresh the user's tenants relationship to ensure we have fresh pivot data
            $user->load('tenants');
            
            // Get site-wide permissions and roles from Spatie (global)
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();
            $siteRoles = $user->getSiteRoles();
            $roles = array_values($siteRoles);
            
            // Get tenant-specific role from pivot table using the User model method
            // This ensures pivot data is loaded correctly
            $tenantRole = $user->getRoleForTenant($tenant);
            
            // If role is null but user belongs to tenant, try to determine role from created_at order
            // (fallback: first user is owner, others are members)
            if (!$tenantRole) {
                $belongsToTenant = $user->tenants()->where('tenants.id', $tenant->id)->exists();
                if ($belongsToTenant) {
                    // Check if this is the first user (by pivot created_at) - they should be owner
                    $firstUser = $tenant->users()->orderBy('tenant_user.created_at')->first();
                    if ($firstUser && $firstUser->id === $user->id) {
                        $tenantRole = 'owner';
                        // Set the role in the pivot table for future requests
                        $user->setRoleForTenant($tenant, 'owner');
                    } else {
                        // Default to member if no role is set
                        $tenantRole = 'member';
                        $user->setRoleForTenant($tenant, 'member');
                    }
                }
            }
            
            // Add permissions from site roles (site_admin, site_owner, etc.)
            // Site roles are Spatie roles, so their permissions should already be in getAllPermissions()
            // But we also explicitly add them here to ensure they're included
            foreach ($siteRoles as $siteRoleName) {
                $siteRoleModel = \Spatie\Permission\Models\Role::where('name', $siteRoleName)->first();
                if ($siteRoleModel) {
                    $siteRolePermissions = $siteRoleModel->permissions->pluck('name')->toArray();
                    $permissions = array_unique(array_merge($permissions, $siteRolePermissions));
                }
            }
            
            if ($tenantRole) {
                $roles[] = $tenantRole;
                
                // Add permissions from tenant role to the permissions array
                // This ensures tenant role permissions (like metadata.registry.view) are available in frontend
                $roleModel = \Spatie\Permission\Models\Role::where('name', $tenantRole)->first();
                if ($roleModel) {
                    $tenantRolePermissions = $roleModel->permissions->pluck('name')->toArray();
                    $permissions = array_unique(array_merge($permissions, $tenantRolePermissions));
                }
            }
            
            // Build role permissions mapping for frontend permission checking
            // This maps each role name to an array of permission names
            // Always build this mapping, even if user has no tenant role, so frontend can check any role
            $allRoles = Role::all();
            foreach ($allRoles as $role) {
                $rolePermissions[$role->name] = $role->permissions->pluck('name')->toArray();
            }
        } else {
            // No tenant selected - still get site role permissions if user has site roles
            if ($user) {
                $siteRoles = $user->getSiteRoles();
                foreach ($siteRoles as $siteRoleName) {
                    $siteRoleModel = \Spatie\Permission\Models\Role::where('name', $siteRoleName)->first();
                    if ($siteRoleModel) {
                        $siteRolePermissions = $siteRoleModel->permissions->pluck('name')->toArray();
                        $permissions = array_unique(array_merge($permissions, $siteRolePermissions));
                    }
                }
                
                // Also get direct Spatie permissions (should already include site role permissions, but ensure they're there)
                $spatiePermissions = $user->getAllPermissions()->pluck('name')->toArray();
                $permissions = array_unique(array_merge($permissions, $spatiePermissions));
            }
            
            // Even if no tenant is selected, build role permissions mapping for consistency
            $allRoles = Role::all();
            foreach ($allRoles as $role) {
                $rolePermissions[$role->name] = $role->permissions->pluck('name')->toArray();
            }
        }

        $parentShared = parent::share($request);
        
        // Manually ensure 'old' input is included if it exists in session but not in parent shared
        $sessionOldInput = $request->session()->getOldInput();
        if (!empty($sessionOldInput) && !isset($parentShared['old'])) {
            $parentShared['old'] = $sessionOldInput;
        }
        
        // DEPRECATED: Processing assets are now fetched via /app/assets/processing endpoint
        // This shared prop is kept for backward compatibility but AssetProcessingTray now polls the endpoint
        // Remove this in a future cleanup after confirming the new polling approach works
        $processingAssets = [];

        $shared = [
            ...$parentShared,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
            ],
            // Phase 2.5: Environment detection for dev-only features
            'env' => [
                'is_production' => config('app.env') === 'production',
                'is_development' => config('app.env') === 'local' || config('app.env') === 'development',
                'app_env' => config('app.env'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'site_roles' => $user ? $user->getSiteRoles() : [],
                ] : null,
                'companies' => $user ? $user->tenants->map(fn ($tenant) => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'is_active' => $tenant->id == $currentTenantId,
                ]) : [],
                'activeBrand' => $activeBrand ? [
                    'id' => $activeBrand->id,
                    'name' => $activeBrand->name,
                    'slug' => $activeBrand->slug,
                    'logo_path' => $activeBrand->logo_path,
                    'primary_color' => $activeBrand->primary_color,
                    'nav_color' => $activeBrand->nav_color,
                    'logo_filter' => $activeBrand->logo_filter ?? 'none',
                ] : null,
                'brands' => $brands, // All brands for the active tenant (filtered by access)
                'brand_plan_limit_info' => $planLimitInfo ?? null, // Plan limit info for alerts
                'permissions' => array_values($permissions), // Ensure it's a proper array (not an object with numeric keys)
                'roles' => $roles,
                'tenant_role' => $tenantRole, // Current tenant-specific role
                'role_permissions' => $rolePermissions, // Mapping of role names to permission arrays
                // Phase AF-5: Approval feature flags (plan-gated)
                'approval_features' => $tenant ? (function () use ($tenant) {
                    $featureGate = app(FeatureGate::class);
                    return [
                        'approvals_enabled' => $featureGate->approvalsEnabled($tenant),
                        'notifications_enabled' => $featureGate->notificationsEnabled($tenant),
                        'approval_summaries_enabled' => $featureGate->approvalSummariesEnabled($tenant),
                        'required_plan' => $featureGate->approvalsEnabled($tenant) ? null : $featureGate->getRequiredPlanName($tenant),
                    ];
                })() : [
                    'approvals_enabled' => false,
                    'notifications_enabled' => false,
                    'approval_summaries_enabled' => false,
                    'required_plan' => 'Pro',
                ],
            ],
            'processing_assets' => $processingAssets, // Assets currently processing (for upload tray)
        ];
        
        return $shared;
    }
}
