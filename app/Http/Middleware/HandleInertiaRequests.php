<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Consent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AgencyBrandAccessService;
use App\Services\AuthPermissionService;
use App\Services\CreatorModuleStatusService;
use App\Services\FeatureGate;
use App\Services\FileTypeService;
use App\Services\PlanService;
use App\Services\Privacy\PrivacyRegionResolver;
use App\Services\Prostaff\ResolveCreatorsDashboardAccess;
use App\Support\BrandDNA\HeadlineAppearanceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        protected AgencyBrandAccessService $agencyBrandAccessService
    ) {}

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

        // Membership list for nav — load tenants only; default brand colors come from a batch query (no Tenant->defaultBrand access).
        if ($user) {
            $user->loadMissing('tenants');
            $user->warmTenantRoleCacheFromLoadedTenants();
        }

        /** @var array<int|string, string|null> primary_color keyed by tenant id for auth.companies / activeCompany */
        $defaultBrandPrimaryByTenantId = [];
        if ($user && $user->tenants->isNotEmpty()) {
            $defaultBrandPrimaryByTenantId = Brand::query()
                ->whereIn('tenant_id', $user->tenants->pluck('id')->all())
                ->where('is_default', true)
                ->pluck('primary_color', 'tenant_id')
                ->all();
        }

        // Resolve tenant if not already bound (HandleInertiaRequests runs before ResolveTenant middleware)
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (! $tenant && $currentTenantId) {
            $tenant = \App\Models\Tenant::find($currentTenantId);
            if ($tenant) {
                // If user is no longer a member of this tenant (e.g. removed from company), clear session
                // so we don't show their old company/brand in the header on /app/companies or errors.no-companies
                if ($user && ! $user->belongsToTenant($tenant->id)) {
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

        // Session / container tenant is not the same instance as $user->tenants; eager-load relations
        // used below (defaultBrand, brands loop, agencyTier) — lazy loading may be disabled app-wide.
        if ($tenant) {
            $tenant->loadMissing(['defaultBrand', 'brands', 'agencyTier']);
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
                            } elseif ($this->userIsCollectionOnlyForTenant($user, $tenant)) {
                                // C12: Never persist default brand_id for collection-only users — EnsureGatewayEntry rejects it → redirect loop with /gateway.
                                $activeBrand = $this->resolveBrandForCollectionOnlyInertia($request, $tenant);
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
                    if ($this->userIsCollectionOnlyForTenant($user, $tenant)) {
                        $activeBrand = $this->resolveBrandForCollectionOnlyInertia($request, $tenant);
                    } else {
                        $activeBrand = $tenant->defaultBrand;
                        if ($activeBrand) {
                            session(['brand_id' => $activeBrand->id]);
                        }
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

                // Load all brands at query time. activeBrandMembership() uses brand->tenant_id only.
                // See docs/EAGER_LOADING_RULES.md
                $allBrands = $tenant->brands()
                    ->orderBy('is_default', 'desc')
                    ->orderBy('name')
                    ->get();

                // Phase MI-1: Get brands the user has active membership for (removed_at IS NULL)
                $userBrandIds = [];
                if ($user) {
                    foreach ($allBrands as $brand) {
                        if ($user->activeBrandMembership($brand)) {
                            $userBrandIds[] = $brand->id;
                        }
                    }
                }

                // Filter brands based on user role and access
                $accessibleBrands = $allBrands->filter(function ($brand) use ($userBrandIds, $isTenantOwnerOrAdmin) {
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

                // Use centralized brand limit info from PlanService
                $brandLimitInfo = $planService->getBrandLimitInfo($tenant);
                $disabledBrandIds = $brandLimitInfo['disabled'];

                $brands = $accessibleBrands->values()->map(function ($brand) use ($activeBrand, $disabledBrandIds) {
                    $isActive = $activeBrand && $brand->id === $activeBrand->id;
                    $isDisabled = in_array($brand->id, $disabledBrandIds) && ! $isActive;

                    $brandData = [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'logo_path' => $brand->logo_path,
                        'logo_dark_path' => $brand->logo_dark_path,
                        'logo_light_path' => $brand->logo_light_path,
                        'is_default' => $brand->is_default,
                        'is_active' => $isActive,
                        'is_disabled' => $isDisabled,
                        'logo_filter' => $brand->logo_filter ?? 'none',
                        'primary_color' => $brand->primary_color,
                        'secondary_color' => $brand->secondary_color,
                        'icon_bg_color' => $brand->icon_bg_color,
                        'icon_style' => $brand->icon_style ?? 'subtle',
                        'show_in_selector' => $brand->show_in_selector ?? true,
                        'settings' => [
                            'nav_display_mode' => $brand->settings['nav_display_mode'] ?? 'logo',
                        ],
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
                $brands = [];
                $planLimitInfo = null;
            }
        }

        // Effective permissions: merged tenant + Spatie + brand role permissions (no collisions)
        // When tenant is null (e.g. admin dashboard without company selected), still include Spatie (site) role permissions
        if ($user) {
            $user->load('tenants');
            $user->warmTenantRoleCacheFromLoadedTenants();
        }
        $effectivePermissions = [];
        if ($user) {
            try {
                $brand = app()->bound('brand') ? app('brand') : $activeBrand ?? null;
                $effectivePermissions = app(AuthPermissionService::class)
                    ->effectivePermissions($user, $tenant, $brand);
            } catch (\Throwable $e) {
                $effectivePermissions = [];
            }
        }

        // App nav: Brand Guidelines link — show when published (any brand user) or when user can set up DNA/builder
        $showBrandGuidelinesNav = false;
        if ($user && $activeBrand && $tenant && ! (app()->bound('collection_only') && app('collection_only'))) {
            $activeBrand->loadMissing('brandModel');
            $hasPublishedGuidelines = $activeBrand->brandModel?->active_version_id !== null;
            $canSetupBrandGuidelines = $user->hasPermissionForBrand($activeBrand, 'brand_settings.manage');
            $showBrandGuidelinesNav = $hasPublishedGuidelines || $canSetupBrandGuidelines;
        }

        // C12: Collection guests (grants only, no brand membership) — same chrome as collection_only; used when container flag is unset (e.g. some /app/assets/* routes).
        $isCollectionGuestExperience = $tenant && $user && $this->userIsCollectionOnlyForTenant($user, $tenant);
        if ($isCollectionGuestExperience) {
            $showBrandGuidelinesNav = false;
        }

        $parentShared = parent::share($request);

        // Manually ensure 'old' input is included if it exists in session but not in parent shared
        $sessionOldInput = $request->session()->getOldInput();
        if (! empty($sessionOldInput) && ! isset($parentShared['old'])) {
            $parentShared['old'] = $sessionOldInput;
        }

        // DEPRECATED: Processing assets are now fetched via /app/assets/processing endpoint
        // This shared prop is kept for backward compatibility but AssetProcessingTray now polls the endpoint
        // Remove this in a future cleanup after confirming the new polling approach works
        $processingAssets = [];

        // Category selection (GET /app/assets?category=... or /app/executions?category=...) must not carry flash - avoids ghost toasts
        $isCategorySelection = $request->isMethod('GET')
            && ($request->is('app/assets') || $request->is('app/executions'))
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

        $collectionModelForGuestNav = app()->bound('collection') ? app('collection') : null;
        if (! $collectionModelForGuestNav && $isCollectionGuestExperience && $tenant) {
            $collectionModelForGuestNav = $this->resolveCollectionForCollectionGuestInertia($request, $tenant);
        }

        // Creator Home (nav + badge): prostaff users — rejected assets need re-upload
        $creatorHomeAttentionCount = 0;
        $showCreatorHomeNav = false;
        if ($user && $activeBrand && $tenant) {
            $showCreatorHomeNav = $user->isProstaffForBrand($activeBrand)
                && app(FeatureGate::class)->creatorModuleEnabled($tenant)
                && $user->hasPermissionForBrand($activeBrand, 'asset.view');
            if ($showCreatorHomeNav) {
                try {
                    $counts = app(\App\Services\Prostaff\GetProstaffDashboardData::class)
                        ->pipelineCountsForProstaffUser($activeBrand, (int) $user->id);
                    $creatorHomeAttentionCount = (int) ($counts['rejected'] ?? 0);
                } catch (\Throwable) {
                    $creatorHomeAttentionCount = 0;
                }
            }
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
            'privacy' => $this->buildPrivacySharedProps($request),
            'currentWorkspace' => $tenant ? [
                'id' => (int) $tenant->id,
                'name' => $tenant->name,
                'type' => $tenant->is_agency ? 'agency_workspace' : 'company',
            ] : null,
            'creator_module_status' => app(CreatorModuleStatusService::class)->sharedPayload($tenant),
            'ai_credit_warning_level' => $tenant ? app(\App\Services\AiUsageService::class)->getCreditWarningLevel($tenant) : null,
            'can_upload_assets' => $tenant ? app(\App\Services\FeatureGate::class)->canUploadAssets($tenant) : true,
            'onboarding_status' => $activeBrand ? app(\App\Services\OnboardingService::class)->getStatusPayload($activeBrand) : null,
            'signup_enabled' => ! app()->environment('staging'),
            'performance_client_metrics_enabled' => config('performance.client_metrics_enabled', false),
            // DAM file registry → uploader accept + thumbnail UI (single source: config/file_types.php via FileTypeService)
            'dam_file_types' => (function () {
                $svc = app(FileTypeService::class);
                $thumbMimes = $svc->getThumbnailCapabilityMimeTypes();
                $thumbExts = $svc->getThumbnailCapabilityExtensions();
                $uploadMimes = $svc->getAllRegisteredMimeTypes();
                $uploadExts = $svc->getAllRegisteredExtensions();
                sort($thumbMimes);
                sort($thumbExts);
                sort($uploadMimes);
                sort($uploadExts);

                return [
                    'thumbnail_mime_types' => $thumbMimes,
                    'thumbnail_extensions' => $thumbExts,
                    'upload_mime_types' => $uploadMimes,
                    'upload_extensions' => $uploadExts,
                    'upload_accept' => $svc->buildHtmlAcceptAttribute($uploadMimes, $uploadExts),
                    'thumbnail_accept' => $svc->buildHtmlAcceptAttribute($thumbMimes, $thumbExts),
                ];
            })(),
            'video_ai' => [
                'show_cost_in_drawer' => (bool) config('assets.video_ai.show_cost_in_drawer', true),
            ],
            // Phase C12.0: Collection-only mode (no brand; user has only collection access)
            'collection_only' => app()->bound('collection_only') && app('collection_only'),
            'collection_only_collection' => $this->collectionToSharedCollectionOnlyPayload($collectionModelForGuestNav),
            // C12: All collections this user has access to (collection-only or collection-guest), for switching / dropdown
            'collection_only_collections' => ($user && $tenant && (
                (app()->bound('collection_only') && app('collection_only'))
                || $isCollectionGuestExperience
            ))
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
                /** Creator / prostaff — active membership on the workspace brand (overview + /api/prostaff/me). */
                'is_prostaff_for_active_brand' => $user && $activeBrand ? $user->isProstaffForBrand($activeBrand) : false,
                /** Rejected creator uploads still needing fix & re-upload (AppNav badge). */
                'creator_home_attention_count' => $creatorHomeAttentionCount,
                'user' => $user ? [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'site_roles' => $user ? $user->getSiteRoles() : [],
                    'push_prompted_at' => $user->push_prompted_at?->toIso8601String(),
                    'push_enabled' => (bool) $user->push_enabled,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                ] : null,
                'companies' => $user ? $user->tenants->map(fn ($tenant) => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'is_active' => $tenant->id == $currentTenantId,
                    'is_agency' => (bool) $tenant->is_agency,
                    /** Agency-provisioned client access — hidden from user-menu company switcher (use agency strip / brand switcher). */
                    'is_agency_managed' => (bool) ($tenant->pivot->is_agency_managed ?? false),
                    'settings' => $tenant->settings ?? [], // Phase J.3.1: Include tenant settings for approval checks
                    'primary_color' => $defaultBrandPrimaryByTenantId[$tenant->id] ?? null,
                ]) : [],
                'activeCompany' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'settings' => $tenant->settings ?? [], // Phase J.3.1: Include tenant settings for approval checks
                    'is_agency' => (bool) $tenant->is_agency,
                    /** Mirrors `auth.companies[]` pivot — nav uses this to keep agency strip on incubated client workspaces. */
                    'is_agency_managed' => (bool) ($user && $user->tenants->firstWhere('id', $tenant->id)?->pivot?->is_agency_managed ?? false),
                    'agency_tier' => $tenant->agencyTier?->name, // Phase AG-7.1: Agency nav link
                    'primary_color' => $defaultBrandPrimaryByTenantId[$tenant->id] ?? null,
                ] : null,
                /** Client companies linked to the user's agency (not session tenant) — for nav badges / overview teaser */
                'managed_agency_clients' => $user ? $this->agencyBrandAccessService->managedAgencyClientSummariesForUser($user) : [],
                /** Agency + linked-client brands the user can open (flat list for agency strip quick switch) */
                'agency_flat_brands' => $user ? $this->agencyBrandAccessService->flatBrandsForAgencyStrip($user) : [],
                'activeBrand' => $activeBrand ? (function () use ($activeBrand) {
                    $activeBrand->loadMissing('brandModel');

                    return [
                        'id' => $activeBrand->id,
                        'name' => $activeBrand->name,
                        'slug' => $activeBrand->slug,
                        'logo_path' => $activeBrand->logo_path,
                        'logo_dark_path' => $activeBrand->logo_dark_path,
                        'logo_light_path' => $activeBrand->logo_light_path,
                        'primary_color' => $activeBrand->primary_color,
                        'secondary_color' => $activeBrand->secondary_color,
                        'icon_style' => $activeBrand->icon_style ?? 'subtle',
                        'accent_color' => $activeBrand->accent_color,
                        'primary_color_user_defined' => (bool) $activeBrand->primary_color_user_defined,
                        'secondary_color_user_defined' => (bool) $activeBrand->secondary_color_user_defined,
                        'accent_color_user_defined' => (bool) $activeBrand->accent_color_user_defined,
                        'nav_color' => $activeBrand->nav_color,
                        'workspace_button_style' => $activeBrand->workspace_button_style ?? $activeBrand->settings['button_style'] ?? 'primary',
                        'logo_filter' => $activeBrand->logo_filter ?? 'none',
                        'settings' => $activeBrand->settings ?? [], // Phase J.3.1: Include brand settings for approval checks
                        'asset_grid_style' => $activeBrand->settings['asset_grid_style'] ?? 'clean', // clean | impact
                        /** Brand Intelligence / DNA: published guidelines version exists (same check as nav / Insights). */
                        'has_published_guidelines' => $activeBrand->brandModel?->active_version_id !== null,
                    ];
                })() : null,
                'brands' => $brands, // All brands for the active tenant (filtered by access)
                // User is in company but has no brand access (removed from all brands) — not collection guests
                'no_brand_access' => $tenant && $user
                    && ! (app()->bound('collection_only') && app('collection_only'))
                    && ! $isCollectionGuestExperience
                    && count($brands) === 0,
                /** C12: Accepted collection grants, no brand_user in tenant — hide agency strip, no-brand banner, full-app nav extras */
                'is_collection_guest_experience' => (bool) $isCollectionGuestExperience,
                /** D12: UI + server block unauthenticated download links for guests / roles without permission */
                'downloads' => [
                    'can_share_public_link' => $tenant && $user
                        ? $user->mayCreatePublicDownloadLinkForTenant($tenant)
                        : true,
                    /** Hide access + advanced create UI; company-only + default expiration (collection / no-brand + session grant). */
                    'simple_create_modal' => $tenant && $user
                        && $this->userShouldSeeSimpleDownloadCreateModal($user, $tenant, $brands),
                ],
                'brand_plan_limit_info' => $planLimitInfo ?? null, // Plan limit info for alerts
                'effective_permissions' => $effectivePermissions, // Always array; [] when no tenant
                // Computed permission flags for UI (derived from effective_permissions)
                'permissions' => [
                    'can_edit_metadata' => in_array('metadata.edit_post_upload', $effectivePermissions, true)
                        || in_array('metadata.bulk_edit', $effectivePermissions, true),
                    // Company overview: tenant-level permission (admin/owner via company.view)
                    'can_view_company_overview' => $tenant && in_array('company.view', $effectivePermissions, true),
                    // Nav: show Brand Guidelines when DNA is published for everyone, or unpublished only for brand_settings.manage
                    'show_brand_guidelines_nav' => $showBrandGuidelinesNav,
                    // /app/insights/* — same rule as AnalyticsOverviewController (User::canViewBrandWorkspaceInsights)
                    'can_view_workspace_insights' => $tenant && $activeBrand && $user
                        && $user->canViewBrandWorkspaceInsights($tenant, $activeBrand),
                    // Creators list dashboard — managers / tenant admins / brand managers only (not individual creators)
                    'can_view_creators_dashboard' => $tenant && $activeBrand && $user
                        && app(ResolveCreatorsDashboardAccess::class)->canView($user, $tenant, $activeBrand),
                    'can_manage_creators_dashboard' => $tenant && $activeBrand && $user
                        && app(ResolveCreatorsDashboardAccess::class)->canManage($user, $tenant, $activeBrand),
                    /** Overview dropdown → Creator Home (matches cinematic overview quick links). */
                    'show_creator_home_nav' => $showCreatorHomeNav,
                    'generative_enabled' => $tenant
                        && ($tenant->settings['generative_enabled'] ?? true),
                    'ai_enabled' => $tenant
                        ? ($tenant->settings['ai_enabled'] ?? true)
                        : true,
                ],
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
                // Phase 4B: Asset versioning (Pro/Enterprise only)
                'plan_allows_versions' => $tenant ? app(PlanService::class)->planAllowsVersions($tenant) : false,
            ],
            'processing_assets' => $processingAssets, // Assets currently processing (for upload tray)
            // Phase D1: Download bucket count (session-based) for sticky bar
            'download_bucket_count' => $user && $tenant ? app(\App\Services\DownloadBucketService::class)->count() : 0,
            // Phase D2: Download management features (plan-gated) for CreateDownloadPanel, EditDownloadSettingsModal
            'download_features' => $tenant ? app(PlanService::class)->getDownloadManagementFeatures($tenant) : [],
            // D11/D12: Enterprise download policy — disable single-asset download button in quick view/drawer
            'download_policy_disable_single_asset' => $tenant ? app(\App\Services\EnterpriseDownloadPolicy::class)->disableSingleAssetDownloads($tenant) : false,
            // Brand DNA: headline appearance tags (config/headline_appearance.php) for Builder / Brand Settings / guidelines
            'headlineAppearanceCatalog' => HeadlineAppearanceCatalog::forFrontend(),
            'oneSignal' => [
                'app_id' => config('services.onesignal.app_id'),
                'client_enabled' => config('services.onesignal.push_enabled')
                    && ! empty(config('services.onesignal.app_id')),
            ],
        ];

        // Add pending items counts for notification bell (simple, lightweight)
        // Only calculate if tenant and brand are available
        if ($tenant && $activeBrand && $user) {
            try {
                // Pending AI suggestions (same asset scope as /api/ai/review — contributors: teammates' uploads only)
                $aiScope = app(\App\Services\AiReviewSuggestionScopeService::class);
                $pendingMetadataQuery = DB::table('asset_metadata_candidates')
                    ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
                    ->whereNull('assets.deleted_at')
                    ->where('assets.tenant_id', $tenant->id)
                    ->where('assets.brand_id', $activeBrand->id)
                    ->whereNull('asset_metadata_candidates.resolved_at')
                    ->whereNull('asset_metadata_candidates.dismissed_at')
                    ->where('asset_metadata_candidates.producer', 'ai');
                $aiScope->scopeQueryToAiReviewAssetVisibility($pendingMetadataQuery, $user, $activeBrand);
                $pendingMetadataCount = (int) $pendingMetadataQuery->count();

                $pendingTagQuery = DB::table('asset_tag_candidates')
                    ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
                    ->whereNull('assets.deleted_at')
                    ->where('assets.tenant_id', $tenant->id)
                    ->where('assets.brand_id', $activeBrand->id)
                    ->where('asset_tag_candidates.producer', 'ai')
                    ->whereNull('asset_tag_candidates.resolved_at')
                    ->whereNull('asset_tag_candidates.dismissed_at');
                $aiScope->scopeQueryToAiReviewAssetVisibility($pendingTagQuery, $user, $activeBrand);
                $pendingTagCount = (int) $pendingTagQuery->count();

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
                    'ai_tag_suggestions' => $pendingTagCount,
                    'ai_category_suggestions' => $pendingMetadataCount,
                    'ai_suggestions_asset_id' => null, // deprecated: now link to analytics/review
                    'metadata_approvals' => $pendingMetadataApprovalsCount, // null if not approver
                    'my_pending_metadata_approvals' => $myPendingMetadataApprovalsCount, // always computed
                ];
            } catch (\Exception $e) {
                // If there's an error, just set to zero
                $shared['pending_items'] = [
                    'ai_suggestions' => 0,
                    'ai_tag_suggestions' => 0,
                    'ai_category_suggestions' => 0,
                    'metadata_approvals' => null,
                    'my_pending_metadata_approvals' => 0,
                ];
            }
        } else {
            $shared['pending_items'] = [
                'ai_suggestions' => 0,
                'ai_tag_suggestions' => 0,
                'ai_category_suggestions' => 0,
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

    /**
     * Cookie / GPC / region hints for consent UI (Privacy Policy §9).
     *
     * @return array<string, mixed>
     */
    private function buildPrivacySharedProps(Request $request): array
    {
        $resolver = app(PrivacyRegionResolver::class);
        $country = $resolver->countryCodeFromRequest($request);
        $user = $request->user();

        $cookieConsent = null;
        if ($user) {
            $purposes = Consent::latestPurposesForUser($user->id);
            $latest = Consent::latestRecordForUser($user->id);
            if ($purposes !== null && $latest) {
                $cookieConsent = [
                    'purposes' => $purposes,
                    'policy_version' => $latest->policy_version,
                    'updated_at' => $latest->granted_at->toIso8601String(),
                ];
            }
        }

        return [
            'cookie_policy_version' => config('privacy.cookie_policy_version', '1'),
            'strict_opt_in_region' => $resolver->needsStrictOptIn($country),
            'country_code' => $country,
            'gpc' => $resolver->globalPrivacyControl($request),
            'gate_onesignal_behind_consent' => (bool) config('privacy.gate_onesignal_behind_consent', true),
            'cookie_consent' => $cookieConsent,
        ];
    }

    /**
     * C12: Tenant members with collection_user grants only (no brand_user in this tenant).
     */
    private function userIsCollectionOnlyForTenant(?User $user, Tenant $tenant): bool
    {
        return (bool) ($user && $user->isExternalCollectionAccessOnlyForTenant($tenant));
    }

    /**
     * Create Download dialog: no access chooser, no advanced section — matches enforced company-only guest create.
     *
     * $brands is usually a Collection from brand picker building; [] on error paths — never assume array-only.
     */
    private function userShouldSeeSimpleDownloadCreateModal(User $user, Tenant $tenant, array|\Illuminate\Support\Collection $brands): bool
    {
        if (app()->bound('collection_only') && app('collection_only')) {
            return true;
        }
        if ($user->isExternalCollectionAccessOnlyForTenant($tenant)) {
            return true;
        }
        $brandCount = $brands instanceof \Illuminate\Support\Collection
            ? $brands->count()
            : count($brands);
        if ($brandCount !== 0) {
            return false;
        }
        $cid = session('collection_id');
        if (! $cid) {
            return false;
        }

        return $user->collectionAccessGrants()
            ->where('collection_id', (int) $cid)
            ->whereNotNull('accepted_at')
            ->whereHas('collection', static fn ($q) => $q->where('tenant_id', $tenant->id))
            ->exists();
    }

    /**
     * C12: Resolve collection for nav/theming (session or collection-access routes).
     */
    private function resolveCollectionForCollectionGuestInertia(Request $request, Tenant $tenant): ?Collection
    {
        if ($request->routeIs(['collection-invite.landing', 'collection-invite.view'])) {
            $routeCollection = $request->route('collection');
            if ($routeCollection instanceof Collection && (int) $routeCollection->tenant_id === (int) $tenant->id) {
                return $routeCollection;
            }
        }
        $cid = session('collection_id');
        if ($cid) {
            return Collection::query()
                ->where('id', $cid)
                ->where('tenant_id', $tenant->id)
                ->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function collectionToSharedCollectionOnlyPayload(?Collection $collection): ?array
    {
        if (! $collection) {
            return null;
        }
        $collection->loadMissing('brand');
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
    }

    /**
     * Theme/nav context for collection-only users without persisting session brand_id.
     */
    private function resolveBrandForCollectionOnlyInertia(Request $request, Tenant $tenant): ?Brand
    {
        return $this->resolveCollectionForCollectionGuestInertia($request, $tenant)?->brand;
    }
}
