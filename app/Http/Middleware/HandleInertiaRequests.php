<?php

namespace App\Http\Middleware;

use App\Services\AuthPermissionService;
use App\Services\FeatureGate;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

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
                // If user is no longer a member of this tenant (e.g. removed from company), clear session
                // so we don't show their old company/brand in the header on /app/companies or errors.no-companies
                if ($user && ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
                    session()->forget(['tenant_id', 'brand_id', 'collection_id']);
                    $tenant = null;
                } else {
                    app()->instance('tenant', $tenant);
                }
            }
        }
        if ($tenant && ! app()->bound('tenant')) {
            app()->instance('tenant', $tenant);
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

        // Effective permissions: merged tenant + Spatie + brand role permissions (no collisions)
        // When tenant is null (e.g. login screen), always return [] — no exceptions
        if ($user) {
            $user->load('tenants');
        }
        $effectivePermissions = [];
        if ($user && $tenant) {
            try {
                $brand = app()->bound('brand') ? app('brand') : $activeBrand ?? null;
                $effectivePermissions = app(AuthPermissionService::class)
                    ->effectivePermissions($user, $tenant, $brand);
            } catch (\Throwable $e) {
                $effectivePermissions = [];
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

        // Category selection (GET /app/assets?category=... or /app/deliverables?category=...) must not carry flash - avoids ghost toasts
        $isCategorySelection = $request->isMethod('GET')
            && ($request->is('app/assets') || $request->is('app/deliverables'))
            && $request->has('category');

        $flashKeys = ['success', 'error', 'warning', 'info', 'status', 'download_policy_saved', 'show_toast'];
        $success = $request->session()->get('success');
        $error = $request->session()->get('error');
        $warning = $request->session()->get('warning');
        $info = $request->session()->get('info');
        $status = $request->session()->get('status');
        $flash = $isCategorySelection
            ? array_fill_keys($flashKeys, null)
            : [
                'success' => $success,
                'error' => $error,
                'warning' => $warning,
                'info' => $info,
                'status' => $status,
                'download_policy_saved' => $request->session()->get('download_policy_saved'),
                'show_toast' => ! empty($success) || ! empty($error) || ! empty($warning) || ! empty($info) || ! empty($status),
            ];

        // Clear flash after consumption so it does not persist to subsequent requests
        if (! $isCategorySelection) {
            $request->session()->forget(['success', 'error', 'warning', 'info', 'status', 'download_policy_saved']);
        }

        $shared = [
            ...$parentShared,
            'flash' => $flash,
            // Phase 2.5: Environment detection for dev-only features
            'env' => [
                'is_production' => config('app.env') === 'production',
                'is_development' => config('app.env') === 'local' || config('app.env') === 'development',
                'app_env' => config('app.env'),
            ],
            // Phase C12.0: Collection-only mode (no brand; user has only collection access)
            'collection_only' => app()->bound('collection_only') && app('collection_only'),
            'collection_only_collection' => app()->bound('collection') ? (function () {
                $collection = app('collection');
                $brand = $collection->brand;
                return [
                    'id' => $collection->id,
                    'name' => $collection->name,
                    'slug' => $collection->slug,
                    'brand' => $brand ? [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'logo_path' => $brand->logo_path,
                        'logo_filter' => $brand->logo_filter ?? 'none',
                        'primary_color' => $brand->primary_color,
                    ] : null,
                ];
            })() : null,
            // C12: All collections this user has access to (when collection-only), for switching / dropdown
            'collection_only_collections' => (app()->bound('collection_only') && app('collection_only') && $user && $tenant)
                ? $user->collectionAccessGrants()
                    ->whereNotNull('accepted_at')
                    ->whereHas('collection', fn ($q) => $q->where('tenant_id', $tenant->id))
                    ->with(['collection:id,name,slug,brand_id', 'collection.brand'])
                    ->get()
                    ->map(fn ($grant) => [
                        'id' => $grant->collection->id,
                        'name' => $grant->collection->name,
                        'slug' => $grant->collection->slug,
                        'brand' => $grant->collection->brand ? [
                            'id' => $grant->collection->brand->id,
                            'name' => $grant->collection->brand->name,
                            'slug' => $grant->collection->brand->slug,
                            'logo_path' => $grant->collection->brand->logo_path,
                            'logo_filter' => $grant->collection->brand->logo_filter ?? 'none',
                            'primary_color' => $grant->collection->brand->primary_color,
                        ] : null,
                    ])
                    ->unique('id')
                    ->values()
                    ->toArray()
                : [],
            'auth' => [
                // C9.2: Roles for upload AI controls (Admin/Brand Manager) — used by UploadAssetDialog, AssetDrawer
                'brand_role' => ($user && $activeBrand) ? ($user->getRoleForBrand($activeBrand) ?? null) : null,
                'tenant_role' => ($user && $tenant) ? ($user->getRoleForTenant($tenant) ?? null) : null,
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
                    'settings' => $tenant->settings ?? [], // Phase J.3.1: Include tenant settings for approval checks
                ]) : [],
                'activeCompany' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'settings' => $tenant->settings ?? [], // Phase J.3.1: Include tenant settings for approval checks
                    'is_agency' => (bool) $tenant->is_agency,
                    'agency_tier' => $tenant->agencyTier?->name, // Phase AG-7.1: Agency nav link
                ] : null,
                'activeBrand' => $activeBrand ? [
                    'id' => $activeBrand->id,
                    'name' => $activeBrand->name,
                    'slug' => $activeBrand->slug,
                    'logo_path' => $activeBrand->logo_path,
                    'primary_color' => $activeBrand->primary_color,
                    'secondary_color' => $activeBrand->secondary_color,
                    'accent_color' => $activeBrand->accent_color,
                    'nav_color' => $activeBrand->nav_color,
                    'workspace_button_style' => $activeBrand->workspace_button_style ?? $activeBrand->settings['button_style'] ?? 'primary',
                    'logo_filter' => $activeBrand->logo_filter ?? 'none',
                    'settings' => $activeBrand->settings ?? [], // Phase J.3.1: Include brand settings for approval checks
                ] : null,
                'brands' => $brands, // All brands for the active tenant (filtered by access)
                // User is in company but has no brand access (removed from all brands)
                'no_brand_access' => $tenant && $user && ! (app()->bound('collection_only') && app('collection_only')) && (is_array($brands) && count($brands) === 0),
                'brand_plan_limit_info' => $planLimitInfo ?? null, // Plan limit info for alerts
                'effective_permissions' => $effectivePermissions, // Always array; [] when no tenant
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
                // Phase M-2: Metadata approval feature flags (company + brand gated)
                'metadata_approval_features' => $tenant && $activeBrand ? (function () use ($tenant, $activeBrand) {
                    $featureGate = app(FeatureGate::class);
                    return [
                        'metadata_approval_enabled' => $featureGate->metadataApprovalEnabled($tenant, $activeBrand),
                    ];
                })() : [
                    'metadata_approval_enabled' => false,
                ],
            ],
            'processing_assets' => $processingAssets, // Assets currently processing (for upload tray)
            // Phase D1: Download bucket count (session-based) for sticky bar
            'download_bucket_count' => $user && $tenant ? app(\App\Services\DownloadBucketService::class)->count() : 0,
            // D11/D12: Enterprise download policy — disable single-asset download button in quick view/drawer
            'download_policy_disable_single_asset' => $tenant ? app(\App\Services\EnterpriseDownloadPolicy::class)->disableSingleAssetDownloads($tenant) : false,
        ];
        
        // Add pending items counts for notification bell (simple, lightweight)
        // Only calculate if tenant and brand are available
        if ($tenant && $activeBrand && $user) {
            try {
                // Pending AI suggestions count (metadata candidates + tag candidates)
                $pendingMetadataCount = DB::table('asset_metadata_candidates')
                    ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
                    ->where('assets.tenant_id', $tenant->id)
                    ->where('assets.brand_id', $activeBrand->id)
                    ->whereNull('asset_metadata_candidates.resolved_at')
                    ->whereNull('asset_metadata_candidates.dismissed_at')
                    ->where('asset_metadata_candidates.producer', 'ai')
                    ->count();
                    
                $pendingTagCount = DB::table('asset_tag_candidates')
                    ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
                    ->where('assets.tenant_id', $tenant->id)
                    ->where('assets.brand_id', $activeBrand->id)
                    ->where('asset_tag_candidates.producer', 'ai')
                    ->whereNull('asset_tag_candidates.resolved_at')
                    ->whereNull('asset_tag_candidates.dismissed_at')
                    ->count();
                    
                $totalPendingAiSuggestions = $pendingMetadataCount + $pendingTagCount;
                
                // Pending metadata approvals: Always compute user's own pending fields
                // Only compute global pending (for approvers) if user can approve
                $approvalResolver = app(\App\Services\MetadataApprovalResolver::class);
                $canApprove = $approvalResolver->canApprove($user, $tenant);
                
                // Base query for pending metadata (field-based, not asset-based)
                $pendingMetadataBaseQuery = DB::table('asset_metadata')
                    ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
                    ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
                    ->where('assets.tenant_id', $tenant->id)
                    ->where('assets.brand_id', $activeBrand->id)
                    ->whereNull('asset_metadata.approved_at')
                    ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected', 'automatic', 'system', 'manual_override'])
                    ->whereIn('asset_metadata.source', ['ai', 'user'])
                    ->where('metadata_fields.population_mode', '!=', 'automatic')
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('asset_metadata as approved_metadata')
                            ->whereColumn('approved_metadata.asset_id', 'asset_metadata.asset_id')
                            ->whereColumn('approved_metadata.metadata_field_id', 'asset_metadata.metadata_field_id')
                            ->whereNotNull('approved_metadata.approved_at')
                            ->whereNotIn('approved_metadata.source', ['user_rejected', 'ai_rejected']);
                    });
                
                // Always compute: user's own pending metadata fields
                $myPendingMetadataApprovalsCount = (clone $pendingMetadataBaseQuery)
                    ->where('assets.user_id', $user->id)
                    ->count('asset_metadata.id');
                
                // Conditionally compute: all pending metadata fields (only for approvers)
                $pendingMetadataApprovalsCount = null;
                if ($canApprove) {
                    $pendingMetadataApprovalsCount = (clone $pendingMetadataBaseQuery)
                        ->count('asset_metadata.id');
                }
                
                $shared['pending_items'] = [
                    'ai_suggestions' => $totalPendingAiSuggestions,
                    'metadata_approvals' => $pendingMetadataApprovalsCount, // null if not approver
                    'my_pending_metadata_approvals' => $myPendingMetadataApprovalsCount, // always computed
                ];
            } catch (\Exception $e) {
                // If there's an error, just set to zero
                $shared['pending_items'] = [
                    'ai_suggestions' => 0,
                    'metadata_approvals' => null,
                    'my_pending_metadata_approvals' => 0,
                ];
            }
        } else {
            $shared['pending_items'] = [
                'ai_suggestions' => 0,
                'metadata_approvals' => null,
                'my_pending_metadata_approvals' => 0,
            ];
        }

        // Guarantee effective_permissions is always present (never undefined) — merge last to prevent overwrite
        $shared['auth'] = array_merge($shared['auth'] ?? [], [
            'effective_permissions' => $effectivePermissions,
        ]);

        return $shared;
    }
}
