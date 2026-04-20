<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Mail\InviteMember;
use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\CollectionUser;
use App\Models\ProstaffMembership;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\BrandDNA\BrandLogoVariantAutomationService;
use App\Services\BrandDNA\BrandVersionService;
use App\Services\BrandService;
use App\Services\CategoryService;
use App\Services\CategoryVisibilityLimitService;
use App\Services\FeatureGate;
use App\Services\PlanService;
use App\Services\Prostaff\ApplyProstaffAfterBrandInvitationAccept;
use App\Services\SystemCategoryService;
use App\Support\Roles\PermissionMap;
use App\Support\Roles\RoleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function __construct(
        protected BrandService $brandService,
        protected PlanService $planService,
        protected CategoryService $categoryService,
        protected SystemCategoryService $systemCategoryService,
        protected CategoryVisibilityLimitService $categoryVisibilityLimitService
    ) {}

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
        // Phase MI-1: Eager-load active brand members to avoid N+1 and to compute other-brands counts
        $orderedBrands = $brands->load([
            'categories',
            'invitations',
            'users' => fn ($q) => $q->wherePivotNull('removed_at'),
        ])
            ->sortBy([['is_default', 'desc'], ['name', 'asc']])
            ->values();

        $userBrandMembershipCount = [];
        foreach ($orderedBrands as $b) {
            foreach ($b->users as $u) {
                $uid = $u->id;
                $userBrandMembershipCount[$uid] = ($userBrandMembershipCount[$uid] ?? 0) + 1;
            }
        }

        $canRemoveUserFromCompany = $user->canForContext('team.manage', $tenant, null);

        return Inertia::render('Brands/Index', [
            'can_remove_user_from_company' => $canRemoveUserFromCompany,
            'brands' => $orderedBrands->map(function ($brand, $index) use ($tenantUsers, $maxBrands, $brandLimitExceeded, $userBrandMembershipCount) {
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
                    'is_creator_invite' => (bool) (($invitation->metadata ?? [])['assign_prostaff_after_accept'] ?? false),
                    'sent_at' => $invitation->sent_at?->toISOString(),
                    'created_at' => $invitation->created_at->toISOString(),
                ]);

                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'logo_path' => $brand->logo_path,
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
                    // Phase MI-1: Active memberships (eager-loaded)
                    'users' => $brand->users->map(function ($user) use ($userBrandMembershipCount) {
                        $totalBrands = $userBrandMembershipCount[$user->id] ?? 1;

                        return [
                            'id' => $user->id,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'name' => $user->name,
                            'email' => $user->email,
                            'avatar_url' => $user->avatar_url,
                            'role' => $user->pivot->role,
                            'other_brands_count' => max(0, $totalBrands - 1),
                        ];
                    }),
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

        // Check plan limits - prevent switching to disabled brands
        $planService = app(PlanService::class);
        if ($planService->isBrandDisabledByPlanLimit($brand, $tenant)) {
            $info = $planService->getBrandLimitInfo($tenant);
            $message = "This brand is not accessible on your current plan. Your plan allows {$info['max_brands']} brand(s), but you have {$info['total_brands']}. Please upgrade your plan to access all brands.";

            if ($this->shouldReturnJsonForWorkspaceSwitch($request)) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['brand' => [$message]],
                ], 422);
            }

            return back()->withErrors([
                'brand' => $message,
            ]);
        }

        // Tenant-wide roles can switch to any brand in the company (align with company switch + permissions)
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantWideBrandAccess = in_array($tenantRole, ['owner', 'admin', 'agency_admin'], true);

        // Verify user has access to this brand (via brand_user pivot table) OR tenant-wide role
        // Phase MI-1: Check active membership
        if (! $isTenantWideBrandAccess && ! $user->activeBrandMembership($brand)) {
            abort(403, 'You do not have access to this brand.');
        }

        // Update session with new brand
        session(['brand_id' => $brand->id]);

        // fetch() + Accept: application/json: return JSON so the client navigates once (no 302 follow + window.location).
        if ($this->shouldReturnJsonForWorkspaceSwitch($request)) {
            return response()->json([
                'ok' => true,
                'brand_id' => $brand->id,
            ]);
        }

        // Inertia: return back to allow client-side navigation without full page reload
        return back();
    }

    /**
     * POST /app/brands/{brand}/logo-variants/generate
     * Raster logo variants (on-dark white mark, on-light primary wash) — same engine as Brand DNA → Standards.
     */
    public function generateLogoVariants(Request $request, Brand $brand): JsonResponse
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $validated = $request->validate([
            'on_dark' => 'boolean',
            'on_light' => 'boolean',
        ]);
        $onDark = (bool) ($validated['on_dark'] ?? false);
        $onLight = (bool) ($validated['on_light'] ?? false);
        if (! $onDark && ! $onLight) {
            return response()->json([
                'ok' => false,
                'errors' => ['Choose at least one variant to generate.'],
            ], 422);
        }

        $version = app(BrandVersionService::class)->getWorkingVersion($brand);
        $result = app(BrandLogoVariantAutomationService::class)->generateExplicit($brand, $version, $onDark, $onLight);

        $brand->refresh();

        // Preview URLs are read from brand columns (not just the automation return) so the
        // UI immediately reflects persisted state after the bug fix in createVariantAsset.
        $darkUrls = $this->resolveLogoPreviewUrls($brand->logo_dark_id);
        $lightUrls = $this->resolveLogoPreviewUrls($brand->logo_light_id);

        $result['logo_dark_preview_url'] = $darkUrls['thumbnail'] ?? $darkUrls['original'];
        $result['logo_dark_original_url'] = $darkUrls['original'];
        $result['logo_dark_id'] = $brand->logo_dark_id;

        $result['logo_light_preview_url'] = $lightUrls['thumbnail'] ?? $lightUrls['original'];
        $result['logo_light_original_url'] = $lightUrls['original'];
        $result['logo_light_id'] = $brand->logo_light_id;
        // Retain legacy key for any in-flight client JS that still reads it.
        $result['on_light_preview_url'] = $result['logo_light_preview_url'];

        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    /**
     * Show the form for creating a new brand.
     */
    public function create(): Response|\Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Check if user has permission to manage brand settings
        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to create brands.');
        }

        $canCreate = $this->brandService->canCreate($tenant);

        if (! $canCreate) {
            return redirect()->route('app')->withErrors([
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
            'icon_bg_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'icon_style' => 'nullable|string|in:subtle,gradient,solid',
            'primary_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'accent_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'nav_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'settings' => 'nullable|array',
        ]);

        // Logos are added via Edit after brand creation (must be assets)
        $validated['logo_path'] = null;
        $validated['logo_id'] = null;

        // Handle icon_bg_color
        if ($request->has('icon_bg_color')) {
            $validated['icon_bg_color'] = $request->input('icon_bg_color') ?: null;
        }

        // Handle icon_style
        if ($request->has('icon_style')) {
            $validated['icon_style'] = $request->input('icon_style') ?: 'subtle';
        }

        try {
            $brand = $this->brandService->create($tenant, $validated, $user);

            return redirect()->route('app')->with('success', 'Brand created successfully.');
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

        // Phase MI-1: Get brand users with active membership only
        $activeCreatorUserIdSet = array_fill_keys(
            ProstaffMembership::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->pluck('user_id')
                ->all(),
            true
        );

        $brandUsersCollection = $brand->users()
            ->wherePivotNull('removed_at')
            ->get();

        $brandUserIds = $brandUsersCollection->pluck('id')->all();
        $otherBrandsCountByUserId = [];
        if (! empty($brandUserIds)) {
            $rows = DB::table('brand_user')
                ->join('brands', 'brands.id', '=', 'brand_user.brand_id')
                ->where('brands.tenant_id', $tenant->id)
                ->whereIn('brand_user.user_id', $brandUserIds)
                ->whereNull('brand_user.removed_at')
                ->select('brand_user.user_id', 'brand_user.brand_id')
                ->get();
            $distinctBrandsByUser = [];
            foreach ($rows as $row) {
                $uid = (int) $row->user_id;
                $distinctBrandsByUser[$uid][(int) $row->brand_id] = true;
            }
            foreach ($brandUserIds as $uid) {
                $n = isset($distinctBrandsByUser[$uid]) ? count($distinctBrandsByUser[$uid]) : 1;
                $otherBrandsCountByUserId[$uid] = max(0, $n - 1);
            }
        }

        $brandUsers = $brandUsersCollection->map(function ($user) use ($brand, $activeCreatorUserIdSet, $otherBrandsCountByUserId) {
            return [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'role' => $user->getRoleForBrand($brand) ?? 'viewer',
                'is_active_creator' => isset($activeCreatorUserIdSet[$user->id]),
                'other_brands_count' => $otherBrandsCountByUserId[$user->id] ?? 0,
            ];
        });

        // Available users (tenant users not yet on this brand) and pending invitations for Members tab
        $tenantUsers = $tenant->users;
        $assignedUserIds = $brand->users()
            ->wherePivotNull('removed_at')
            ->pluck('users.id')
            ->toArray();
        $availableUsers = $tenantUsers->reject(fn ($u) => in_array($u->id, $assignedUserIds))->map(fn ($u) => [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'name' => $u->name,
            'email' => $u->email,
            'avatar_url' => $u->avatar_url,
        ])->values();
        $pendingInvitations = $brand->invitations()->whereNull('accepted_at')->get()->map(fn ($inv) => [
            'id' => $inv->id,
            'email' => $inv->email,
            'role' => $inv->role,
            'is_creator_invite' => (bool) (($inv->metadata ?? [])['assign_prostaff_after_accept'] ?? false),
            'sent_at' => $inv->sent_at?->toISOString(),
            'created_at' => $inv->created_at->toISOString(),
        ]);

        // Get all valid brand roles dynamically (not hardcoded)
        // Show all valid brand roles so they can be assigned even if no users have them yet
        $brandRoles = \App\Models\User::getValidBrandRoles();

        $currentPlan = $this->planService->getCurrentPlan($tenant);
        $brandModel = $brand->brandModel;
        $activeVersion = $brandModel?->activeVersion;
        $modelPayload = self::deepUnwrapPayload($activeVersion?->model_payload ?? []);

        $allVersions = $brandModel
            ? $brandModel->versions()
                ->orderByDesc('version_number')
                ->get(['id', 'version_number', 'status', 'source_type', 'lifecycle_stage', 'research_status', 'review_status', 'created_at', 'updated_at'])
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'version_number' => $v->version_number,
                    'status' => $v->status,
                    'source_type' => $v->source_type,
                    'lifecycle_stage' => $v->lifecycle_stage,
                    'research_status' => $v->research_status,
                    'review_status' => $v->review_status,
                    'created_at' => $v->created_at->toISOString(),
                    'updated_at' => $v->updated_at->toISOString(),
                ])
            : [];

        // Execution alignment data (ported from BrandDNAController::index)
        $complianceAggregate = $brand->complianceAggregate;
        $deliverableCategoryIds = \App\Models\Category::where('brand_id', $brand->id)
            ->where('asset_type', \App\Enums\AssetType::DELIVERABLE)
            ->pluck('id')
            ->toArray();

        $topExecutions = [];
        $bottomExecutions = [];
        if (! empty($deliverableCategoryIds)) {
            $topScores = \App\Models\BrandIntelligenceScore::where('brand_id', $brand->id)
                ->whereNull('execution_id')
                ->whereNotNull('asset_id')
                ->whereNotNull('overall_score')
                ->with('asset:id,title,metadata')
                ->orderByDesc('overall_score')
                ->limit(3)
                ->get();
            $bottomScores = \App\Models\BrandIntelligenceScore::where('brand_id', $brand->id)
                ->whereNull('execution_id')
                ->whereNotNull('asset_id')
                ->whereNotNull('overall_score')
                ->with('asset:id,title,metadata')
                ->orderBy('overall_score')
                ->limit(3)
                ->get();

            foreach ($topScores as $s) {
                $catId = $s->asset?->metadata['category_id'] ?? null;
                if ($catId && in_array((int) $catId, $deliverableCategoryIds, true)) {
                    $topExecutions[] = ['id' => $s->asset_id, 'title' => $s->asset?->title ?? '—', 'score' => $s->overall_score];
                }
            }
            foreach ($bottomScores as $s) {
                $catId = $s->asset?->metadata['category_id'] ?? null;
                if ($catId && in_array((int) $catId, $deliverableCategoryIds, true)) {
                    $bottomExecutions[] = ['id' => $s->asset_id, 'title' => $s->asset?->title ?? '—', 'score' => $s->overall_score];
                }
            }
        }

        $logoUrls = $this->resolveLogoPreviewUrls($brand->logo_id);
        $logoDarkUrls = $this->resolveLogoPreviewUrls($brand->logo_dark_id);
        $logoLightUrls = $this->resolveLogoPreviewUrls($brand->logo_light_id);
        $logoHorizontalUrls = $this->resolveLogoPreviewUrls($brand->logo_horizontal_id);

        return Inertia::render('Brands/Edit', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'logo_id' => $brand->logo_id,
                'logo_thumbnail_url' => $logoUrls['thumbnail'],
                'logo_original_url' => $logoUrls['original'],
                'logo_dark_path' => $brand->logo_dark_path,
                'logo_dark_id' => $brand->logo_dark_id,
                'logo_dark_thumbnail_url' => $logoDarkUrls['thumbnail'],
                'logo_dark_original_url' => $logoDarkUrls['original'],
                'logo_light_path' => $brand->logo_light_path,
                'logo_light_id' => $brand->logo_light_id,
                'logo_light_thumbnail_url' => $logoLightUrls['thumbnail'],
                'logo_light_original_url' => $logoLightUrls['original'],
                'logo_horizontal_path' => $brand->logo_horizontal_path,
                'logo_horizontal_id' => $brand->logo_horizontal_id,
                'logo_horizontal_thumbnail_url' => $logoHorizontalUrls['thumbnail'],
                'logo_horizontal_original_url' => $logoHorizontalUrls['original'],
                'icon_bg_color' => $brand->icon_bg_color,
                'icon_style' => $brand->icon_style ?? 'subtle',
                'is_default' => $brand->is_default,
                'show_in_selector' => $brand->show_in_selector ?? true,
                'primary_color' => $brand->primary_color,
                'primary_color_user_defined' => (bool) $brand->primary_color_user_defined,
                'secondary_color' => $brand->secondary_color,
                'secondary_color_user_defined' => (bool) $brand->secondary_color_user_defined,
                'accent_color' => $brand->accent_color,
                'accent_color_user_defined' => (bool) $brand->accent_color_user_defined,
                'nav_color' => $brand->nav_color,
                'workspace_button_style' => $brand->workspace_button_style ?? $brand->settings['button_style'] ?? 'primary',
                'logo_filter' => $brand->logo_filter ?? 'none',
                'settings' => $brand->settings,
                'download_landing_settings' => $brand->download_landing_settings ?? [],
                'logo_assets' => [], // Populated client-side when fetching logos
                'background_asset_details' => $this->buildBackgroundAssetDetails($brand),
                'logo_asset_thumbnail_url' => $this->buildLogoAssetThumbnailUrl($brand),
            ],
            'brand_users' => $brandUsers,
            'can_remove_user_from_company' => Auth::user()->canForContext('team.manage', $tenant, null),
            'brand_roles' => $brandRoles,
            'available_users' => $availableUsers,
            'pending_invitations' => $pendingInvitations,
            // Phase M-2: Pass tenant settings to check if company metadata approval is enabled
            'tenant_settings' => $tenant->settings ?? [],
            'current_plan' => $currentPlan,
            // Brand DNA: active version model_payload for Strategy, Positioning, Expression, Standards tabs
            'model_payload' => $modelPayload,
            // Brand Model header: active version, create draft, run builder
            'brand_model' => $brandModel ? ['id' => $brandModel->id, 'is_enabled' => $brandModel->is_enabled ?? false] : null,
            'active_version' => $activeVersion ? [
                'id' => $activeVersion->id,
                'version_number' => $activeVersion->version_number,
                'status' => $activeVersion->status,
                'updated_at' => $activeVersion->updated_at->toISOString(),
            ] : null,
            'all_versions' => $allVersions,
            'research_insights' => $this->buildResearchInsights($brand, $brandModel),
            'compliance_aggregate' => $complianceAggregate ? [
                'avg_score' => $complianceAggregate->execution_count > 0 ? (float) $complianceAggregate->avg_score : null,
                'execution_count' => (int) $complianceAggregate->execution_count,
                'high_score_count' => (int) $complianceAggregate->high_score_count,
                'low_score_count' => (int) $complianceAggregate->low_score_count,
                'last_scored_at' => $complianceAggregate->last_scored_at?->toISOString(),
            ] : null,
            'top_executions' => $topExecutions,
            'bottom_executions' => $bottomExecutions,
            'portal_settings' => $brand->portal_settings ?? [],
            'portal_features' => $this->resolvePortalFeatures($tenant),
            'portal_url' => \App\Http\Controllers\PublicBrandPortalController::portalUrl($brand),
            'creator_module' => [
                'enabled' => app(FeatureGate::class)->creatorModuleEnabled($tenant),
                'approver_user_ids' => $brand->creatorModuleApproverUserIds(),
                'has_approvers' => $brand->hasConfiguredCreatorApprovers(),
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
            'logo_id' => 'nullable|uuid|exists:assets,id',
            'clear_logo' => 'nullable|boolean',
            'logo_dark_id' => 'nullable|uuid|exists:assets,id',
            'clear_logo_dark' => 'nullable|boolean',
            'logo_light_id' => 'nullable|uuid|exists:assets,id',
            'clear_logo_light' => 'nullable|boolean',
            'icon_bg_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'icon_style' => 'nullable|string|in:subtle,gradient,solid',
            'show_in_selector' => 'nullable|boolean',
            'primary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'accent_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'nav_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'workspace_button_style' => 'nullable|string|in:context,primary,secondary,accent,white,black',
            'logo_filter' => 'nullable|string|in:none,white,black,primary',
            'settings' => 'nullable|array',
            'settings.metadata_approval_enabled' => 'nullable|boolean', // Phase M-2
            'settings.contributor_upload_requires_approval' => 'nullable|boolean', // Phase J.3.1
            'settings.asset_grid_style' => 'nullable|string|in:clean,impact',
            'settings.nav_display_mode' => 'nullable|string|in:logo,text',
            'settings.workspace_sidebar_style' => 'nullable|string|in:solid,cinematic',
            'settings.cinematic_accent_color_role' => 'nullable|string|in:auto,primary,secondary,accent',
            'portal_settings' => 'nullable|array',
            'portal_settings.entry' => 'nullable|array',
            'portal_settings.entry.style' => 'nullable|string|in:cinematic,instant',
            'portal_settings.entry.auto_enter' => 'nullable|boolean',
            'portal_settings.entry.default_destination' => 'nullable|string|in:assets,guidelines,collections',
            'portal_settings.entry.primary_button' => 'nullable|string|in:assets,guidelines,collections',
            'portal_settings.entry.secondary_button' => 'nullable|string|in:assets,guidelines,collections',
            'portal_settings.entry.tagline_override' => 'nullable|string|max:255',
            'portal_settings.public' => 'nullable|array',
            'portal_settings.public.enabled' => 'nullable|boolean',
            'portal_settings.public.visibility' => 'nullable|string|in:private,link_only,public',
            'portal_settings.public.indexable' => 'nullable|boolean',
            'portal_settings.sharing' => 'nullable|array',
            'portal_settings.sharing.external_collections' => 'nullable|boolean',
            'portal_settings.sharing.expiring_links' => 'nullable|boolean',
            'portal_settings.sharing.watermark_branding' => 'nullable|boolean',
            'portal_settings.agency_template' => 'nullable|array',
            'portal_settings.agency_template.enabled' => 'nullable|boolean',
            'portal_settings.agency_template.template_id' => 'nullable|string|max:100',
            'portal_settings.agency_template.locked_fields' => 'nullable|array',
            'portal_settings.invite' => 'nullable|array',
            'portal_settings.invite.headline' => 'nullable|string|max:255',
            'portal_settings.invite.subtext' => 'nullable|string|max:500',
            'portal_settings.invite.background_style' => 'nullable|string|in:brand,minimal,dark',
            'portal_settings.invite.cta_label' => 'nullable|string|max:100',
        ]);

        // Handle logo: explicit clear or asset_id (all logos must be assets, no direct file upload)
        if ($request->boolean('clear_logo')) {
            if ($brand->logo_path && str_starts_with($brand->logo_path, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $brand->logo_path);
                Storage::disk('public')->delete($oldPath);
            }
            $validated['logo_path'] = null;
            $validated['logo_id'] = null;
        } elseif ($request->filled('logo_id')) {
            $logoAsset = \App\Models\Asset::where('id', $request->input('logo_id'))
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();
            if (! $logoAsset) {
                abort(403, 'Logo asset does not belong to this brand.');
            }
            $validated['logo_id'] = $request->input('logo_id');
            $validated['logo_path'] = null;
        } else {
            $validated['logo_path'] = $brand->logo_path;
            $validated['logo_id'] = $brand->logo_id;
        }

        // Handle dark logo variant: explicit clear or asset_id
        if ($request->boolean('clear_logo_dark')) {
            $validated['logo_dark_path'] = null;
            $validated['logo_dark_id'] = null;
        } elseif ($request->filled('logo_dark_id')) {
            $darkLogoAsset = \App\Models\Asset::where('id', $request->input('logo_dark_id'))
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();
            if (! $darkLogoAsset) {
                abort(403, 'Dark logo asset does not belong to this brand.');
            }
            $validated['logo_dark_id'] = $request->input('logo_dark_id');
            $validated['logo_dark_path'] = null;
        } else {
            $validated['logo_dark_path'] = $brand->logo_dark_path;
            $validated['logo_dark_id'] = $brand->logo_dark_id;
        }

        // Handle light logo variant: explicit clear or asset_id. Left unset it falls back
        // to the primary logo at render time (see Brand::logoForSurface('light')).
        if ($request->boolean('clear_logo_light')) {
            $validated['logo_light_path'] = null;
            $validated['logo_light_id'] = null;
        } elseif ($request->filled('logo_light_id')) {
            $lightLogoAsset = \App\Models\Asset::where('id', $request->input('logo_light_id'))
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();
            if (! $lightLogoAsset) {
                abort(403, 'Light logo asset does not belong to this brand.');
            }
            $validated['logo_light_id'] = $request->input('logo_light_id');
            $validated['logo_light_path'] = null;
        } else {
            $validated['logo_light_path'] = $brand->logo_light_path;
            $validated['logo_light_id'] = $brand->logo_light_id;
        }

        // Handle horizontal logo variant: explicit clear or asset_id
        if ($request->boolean('clear_logo_horizontal')) {
            $validated['logo_horizontal_path'] = null;
            $validated['logo_horizontal_id'] = null;
        } elseif ($request->filled('logo_horizontal_id')) {
            $horizAsset = \App\Models\Asset::where('id', $request->input('logo_horizontal_id'))
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();
            if (! $horizAsset) {
                abort(403, 'Horizontal logo asset does not belong to this brand.');
            }
            $validated['logo_horizontal_id'] = $request->input('logo_horizontal_id');
            $validated['logo_horizontal_path'] = null;
        } else {
            $validated['logo_horizontal_path'] = $brand->logo_horizontal_path;
            $validated['logo_horizontal_id'] = $brand->logo_horizontal_id;
        }

        // Handle icon_bg_color
        if ($request->has('icon_bg_color')) {
            $validated['icon_bg_color'] = $request->input('icon_bg_color') ?: null;
        } else {
            // Keep existing icon_bg_color if not provided
            $validated['icon_bg_color'] = $brand->icon_bg_color;
        }

        // Handle icon_style
        if ($request->has('icon_style')) {
            $validated['icon_style'] = $request->input('icon_style') ?: 'subtle';
        } else {
            $validated['icon_style'] = $brand->icon_style ?? 'subtle';
        }

        // Handle download_landing_settings (D10, Phase 1.4: includes custom_color)
        $dls = $request->input('download_landing_settings');
        if (is_array($dls)) {
            $current = $brand->download_landing_settings ?? [];
            $validated['download_landing_settings'] = array_merge($current, $dls);
        }

        // Handle portal_settings (Brand Portal system) — recursive merge preserves
        // sibling keys so saving { entry: { auto_enter: true } } won't wipe style/destination.
        $portalInput = $request->input('portal_settings');
        if (is_array($portalInput)) {
            $current = $brand->portal_settings ?? [];
            $validated['portal_settings'] = self::deepMergePortal($current, $portalInput);
        }

        // Handle workspace_button_style (dedicated column; fallback from settings.button_style for backward compat)
        if ($request->has('workspace_button_style')) {
            $validated['workspace_button_style'] = $request->input('workspace_button_style') ?: 'primary';
        } elseif (isset($validated['settings']['button_style'])) {
            $validated['workspace_button_style'] = $validated['settings']['button_style'] ?: 'primary';
        }

        // Phase M-2: Handle settings separately (merge with existing)
        $settings = $validated['settings'] ?? [];
        unset($validated['settings']);

        // Always merge settings to ensure all settings are preserved
        $currentSettings = $brand->settings ?? [];
        if (! empty($settings)) {
            // Merge new settings with existing settings
            $mergedSettings = array_merge($currentSettings, $settings);
        } else {
            // If no settings provided, keep existing settings
            $mergedSettings = $currentSettings;
        }

        // Always set settings (even if empty) to ensure the column is updated
        $validated['settings'] = $mergedSettings;

        // Mark colors as user-defined when explicitly provided
        if ($request->filled('primary_color')) {
            $validated['primary_color_user_defined'] = true;
        }
        if ($request->filled('secondary_color')) {
            $validated['secondary_color_user_defined'] = true;
        }
        if ($request->filled('accent_color')) {
            $validated['accent_color_user_defined'] = true;
        }

        try {
            $this->brandService->update($brand, $validated);

            return redirect()->back()->with('success', 'Brand updated successfully.');
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

            return redirect()->route('app')->with('success', 'Brand deleted successfully.');
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
     * Get category form data (limits, brand users, brand roles) for Add Category modal.
     * Used by Metadata Registry Add Category slide-over.
     *
     * GET /api/brands/{brand}/category-form-data
     */
    public function categoryFormData(Brand $brand)
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            abort(403, 'You do not have permission to manage categories.');
        }

        $visibleByAssetType = $this->categoryVisibilityLimitService->limitsPayloadForBrand($brand);
        $currentCount = $brand->categories()->custom()->count();

        $brandUsers = $brand->users()
            ->wherePivotNull('removed_at')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ]);

        $brandRoles = User::getValidBrandRoles();

        // Available system templates (not yet added to this brand) for Add Existing flow
        $categories = $brand->categories()->get();
        $systemTemplates = $this->systemCategoryService->getAllTemplates();
        $availableTemplates = collect();
        foreach ($systemTemplates as $template) {
            $exists = $categories->contains(fn ($c) => $c->slug === $template->slug && $c->asset_type->value === $template->asset_type->value);
            if (! $exists) {
                $availableTemplates->push([
                    'system_category_id' => $template->id,
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'icon' => $template->icon ?? 'folder',
                    'asset_type' => $template->asset_type->value,
                    'is_private' => $template->is_private,
                    'system_version' => $template->version,
                ]);
            }
        }

        return response()->json([
            'category_limits' => [
                'current' => $currentCount,
                'max' => null,
                'can_create' => true,
                'visible_by_asset_type' => $visibleByAssetType,
            ],
            'brand_users' => $brandUsers->values(),
            'brand_roles' => $brandRoles,
            'available_system_templates' => $availableTemplates->values(),
        ]);
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

        $inviteUrl = route('gateway.invite', [
            'token' => $token,
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

        // Mark pending invitations as accepted and apply creator (prostaff) flags from metadata when present.
        $pendingInvites = $brand->invitations()
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->whereNull('accepted_at')
            ->get();

        foreach ($pendingInvites as $invitation) {
            $invitation->update(['accepted_at' => now()]);
            app(ApplyProstaffAfterBrandInvitationAccept::class)->apply($user, $invitation, $brand);
        }

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

        if ($user->isAgencyManagedMemberOf($tenant)) {
            return back()->withErrors([
                'brand' => 'This user is managed by an agency link. Change access in Company Settings → Agencies.',
            ]);
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
     *
     * Optional `remove_from_company` (boolean): when true, also detaches the user from the tenant.
     * Allowed only if the requester has team.manage and the user has no other active brand memberships
     * in this company (avoids orphans and accidental company removal while still on other brands).
     */
    public function removeUser(Request $request, Brand $brand, User $user)
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
        if (! $membership) {
            abort(404, 'User is not an active member of this brand.');
        }

        if ($user->isAgencyManagedMemberOf($tenant)) {
            return back()->withErrors([
                'brand' => 'This user is managed by an agency link. Change access in Company Settings → Agencies.',
            ]);
        }

        $validated = $request->validate([
            'remove_from_company' => 'sometimes|boolean',
        ]);
        $removeFromCompany = (bool) ($validated['remove_from_company'] ?? false);

        $otherActiveBrandsInTenant = DB::table('brand_user')
            ->join('brands', 'brands.id', '=', 'brand_user.brand_id')
            ->where('brand_user.user_id', $user->id)
            ->where('brands.tenant_id', $tenant->id)
            ->where('brands.id', '!=', $brand->id)
            ->whereNull('brand_user.removed_at')
            ->count();

        if ($removeFromCompany) {
            if (! $authUser->canForContext('team.manage', $tenant, null)) {
                abort(403, 'You do not have permission to remove members from the company.');
            }
            if ($otherActiveBrandsInTenant > 0) {
                return back()->withErrors([
                    'brand' => 'This user is still assigned to other brands. Remove those assignments first, or uncheck removing them from the company.',
                ]);
            }
            if ($user->id === $authUser->id) {
                return back()->withErrors([
                    'brand' => 'You cannot remove yourself from the company.',
                ]);
            }
            $owner = $tenant->users()->orderBy('created_at')->first();
            if ($owner && $user->id === $owner->id) {
                return back()->withErrors([
                    'brand' => 'You cannot remove the company owner.',
                ]);
            }
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
            metadata: [
                'also_removed_from_company' => $removeFromCompany,
            ]
        );

        if ($removeFromCompany) {
            $tenantUser = $user->tenants()->where('tenants.id', $tenant->id)->first();
            $userRole = $tenantUser?->pivot->role ?? null;
            $tenant->users()->detach($user->id);

            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::USER_REMOVED_FROM_COMPANY,
                subject: $user,
                actor: $authUser,
                brand: null,
                metadata: [
                    'removed_by' => $authUser->name,
                    'removed_by_email' => $authUser->email,
                    'user_role' => $userRole,
                    'via' => 'brand_member_remove',
                ]
            );

            return back()->with('success', 'User removed from this brand and from the company.');
        }

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

        $inviteUrl = route('gateway.invite', [
            'token' => $invitation->token,
        ]);

        // Resend email
        Mail::to($invitation->email)->send(new InviteMember($tenant, $authUser, $inviteUrl));

        return back()->with('success', 'Invitation resent successfully.');
    }

    /**
     * Revoke a pending brand invitation (deletes the record; the invite link stops working).
     */
    public function revokeInvitation(Brand $brand, BrandInvitation $invitation)
    {
        $tenant = app('tenant');
        $authUser = Auth::user();

        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        if ($invitation->brand_id !== $brand->id) {
            abort(403, 'Invitation does not belong to this brand.');
        }

        if (! $authUser->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to revoke invitations.');
        }

        if ($invitation->accepted_at) {
            return back()->withErrors([
                'invitation' => 'This invitation has already been accepted.',
            ]);
        }

        $email = $invitation->email;
        $invitation->delete();

        return back()->with('success', "Invitation to {$email} has been revoked.");
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
        if (! $featureGate->approvalsEnabled($tenant)) {
            $requiredPlan = $featureGate->getRequiredPlanName($tenant);
            abort(403, "Approval workflows require {$requiredPlan} plan or higher. Please upgrade your plan to access approval features.");
        }

        // Check if user is approval_capable for this brand
        $brandRole = $user->getRoleForBrand($brand);
        if (! $brandRole || ! PermissionMap::canApproveAssets($brandRole)) {
            abort(403, 'You do not have permission to view the approval queue.');
        }

        return redirect()->route('insights.review', ['workspace' => 'uploads']);
    }

    /**
     * D10.1: Return assets eligible for download landing background (photography category, brand-scoped).
     * Used by Public Page Theme Background Visuals picker. No load_more — first page only.
     */
    public function downloadBackgroundCandidates(Brand $brand)
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to manage brand settings.');
        }

        $category = \App\Models\Category::where('slug', 'photography')
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();

        if (! $category) {
            return response()->json(['assets' => []]);
        }

        $eligibilityService = app(\App\Services\AssetEligibilityService::class);
        $assets = \App\Models\Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', \App\Enums\AssetType::ASSET)
            ->whereNull('deleted_at')
            ->where('metadata->category_id', (int) $category->id)
            ->whereNull('archived_at')
            ->whereNotNull('published_at')
            ->orderBy('created_at', 'desc')
            ->limit(24)
            ->with(['user'])
            ->get()
            ->filter(fn ($a) => $eligibilityService->isEligibleForDownloadBackground($a))
            ->values()
            ->map(function ($asset) {
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                    ? $asset->thumbnail_status->value
                    : ($asset->thumbnail_status ?? 'pending');
                $finalThumbnailUrl = null;
                if ($thumbnailStatus === 'completed') {
                    $variant = $asset->thumbnailPathForStyle('medium') ? \App\Support\AssetVariant::THUMB_MEDIUM : \App\Support\AssetVariant::THUMB_SMALL;
                    $finalThumbnailUrl = $asset->deliveryUrl($variant, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
                }

                return [
                    'id' => $asset->id,
                    'original_filename' => $asset->original_filename,
                    'thumbnail_url' => $finalThumbnailUrl,
                    'final_thumbnail_url' => $finalThumbnailUrl,
                    'preview_thumbnail_url' => null,
                ];
            });

        return response()->json(['assets' => $assets]);
    }

    /**
     * Build background_asset_details for Brands/Edit (CDN thumbnail URLs for background_asset_ids).
     */
    protected function buildBackgroundAssetDetails(Brand $brand): array
    {
        $settings = $brand->download_landing_settings ?? [];
        $ids = $settings['background_asset_ids'] ?? [];
        if (! is_array($ids) || empty($ids)) {
            return [];
        }
        $assets = \App\Models\Asset::whereIn('id', $ids)
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->get(['id']);
        $out = [];
        foreach ($assets as $asset) {
            $url = $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_MEDIUM, \App\Support\DeliveryContext::AUTHENTICATED);
            if ($url) {
                $out[] = ['id' => $asset->id, 'thumbnail_url' => $url];
            }
        }

        return $out;
    }

    /**
     * Build logo_asset_thumbnail_url for custom logo (when logo_mode is custom and logo_asset_id is set).
     */
    protected function buildLogoAssetThumbnailUrl(Brand $brand): ?string
    {
        $settings = $brand->download_landing_settings ?? [];
        $logoAssetId = $settings['logo_asset_id'] ?? null;
        if (! $logoAssetId) {
            return null;
        }
        $asset = \App\Models\Asset::where('id', $logoAssetId)
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->first();
        if (! $asset) {
            return null;
        }

        return $this->resolveLogoThumbnailUrl($asset) ?: null;
    }

    /**
     * Build a {thumbnail, original} preview URL pair for a brand logo asset.
     *
     * SVGs are passthrough: no raster thumbnail is ever written to S3, so
     * `deliveryUrl(THUMB_MEDIUM)` would return a *guessed* CDN path that 404s
     * in the browser. For any asset without a real generated raster thumbnail
     * (SVG, or anything still SKIPPED/FAILED), fall back to the original so
     * the preview renders. For SVG, the original IS the preview.
     *
     * @return array{thumbnail: ?string, original: ?string}
     */
    protected function resolveLogoPreviewUrls(?string $assetId): array
    {
        if (! $assetId) {
            return ['thumbnail' => null, 'original' => null];
        }
        $asset = \App\Models\Asset::find($assetId);
        if (! $asset) {
            return ['thumbnail' => null, 'original' => null];
        }

        $original = $asset->deliveryUrl(
            \App\Support\AssetVariant::ORIGINAL,
            \App\Support\DeliveryContext::AUTHENTICATED
        ) ?: null;

        return [
            'thumbnail' => $this->resolveLogoThumbnailUrl($asset, $original),
            'original' => $original,
        ];
    }

    /**
     * Return the best thumbnail URL for a logo, or the original when no
     * generated raster thumbnail exists (SVG passthrough, SKIPPED/FAILED).
     */
    protected function resolveLogoThumbnailUrl(\App\Models\Asset $asset, ?string $originalFallback = null): ?string
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        $ext = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
        $isSvg = $mime === 'image/svg+xml' || $ext === 'svg';
        if ($isSvg) {
            return $originalFallback
                ?? ($asset->deliveryUrl(\App\Support\AssetVariant::ORIGINAL, \App\Support\DeliveryContext::AUTHENTICATED) ?: null);
        }

        $hasGeneratedThumb = $asset->thumbnailPathForStyle('medium') !== null
            || $asset->thumbnailPathForStyle('thumb') !== null;
        if (! $hasGeneratedThumb) {
            return $originalFallback
                ?? ($asset->deliveryUrl(\App\Support\AssetVariant::ORIGINAL, \App\Support\DeliveryContext::AUTHENTICATED) ?: null);
        }

        return $asset->deliveryUrl(
            \App\Support\AssetVariant::THUMB_MEDIUM,
            \App\Support\DeliveryContext::AUTHENTICATED
        ) ?: null;
    }

    protected function buildResearchInsights(Brand $brand, ?\App\Models\BrandModel $brandModel): array
    {
        if (! $brandModel) {
            return ['runs' => [], 'snapshots' => [], 'latest_snapshot_data' => null];
        }

        $versionIds = $brandModel->versions()->pluck('id')->toArray();
        if (empty($versionIds)) {
            return ['runs' => [], 'snapshots' => [], 'latest_snapshot_data' => null];
        }

        $runs = \App\Models\BrandPipelineRun::where('brand_id', $brand->id)
            ->whereIn('brand_model_version_id', $versionIds)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'stage' => $r->stage,
                'extraction_mode' => $r->extraction_mode,
                'pages_total' => $r->pages_total,
                'pages_processed' => $r->pages_processed,
                'error_message' => $r->error_message,
                'created_at' => $r->created_at->toISOString(),
                'completed_at' => $r->completed_at?->toISOString(),
                'duration_seconds' => $r->completed_at && $r->created_at
                    ? $r->completed_at->diffInSeconds($r->created_at)
                    : null,
                'version_id' => $r->brand_model_version_id,
                'has_asset' => (bool) $r->asset_id,
            ]);

        $snapshots = \App\Models\BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->whereIn('brand_model_version_id', $versionIds)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'status' => $s->status,
                'source_url' => $s->source_url,
                'created_at' => $s->created_at->toISOString(),
                'has_snapshot' => ! empty($s->snapshot),
                'has_suggestions' => ! empty($s->suggestions),
                'has_coherence' => ! empty($s->coherence),
                'suggestion_count' => is_array($s->suggestions) ? count(array_filter($s->suggestions, fn ($v) => is_array($v) && isset($v['key']))) : 0,
                'coherence_score' => $s->coherence['overall']['score'] ?? null,
                'version_id' => $s->brand_model_version_id,
            ]);

        $latestCompleted = \App\Models\BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->whereIn('brand_model_version_id', $versionIds)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->first();

        $latestSnapshotData = null;
        if ($latestCompleted && ! empty($latestCompleted->snapshot)) {
            $snap = $latestCompleted->snapshot;
            $latestSnapshotData = [
                'source_url' => $latestCompleted->source_url,
                'created_at' => $latestCompleted->created_at->toISOString(),
                'mission' => $snap['mission'] ?? null,
                'vision' => $snap['vision'] ?? null,
                'tagline' => $snap['tagline'] ?? null,
                'industry' => $snap['industry'] ?? null,
                'target_audience' => $snap['target_audience'] ?? null,
                'positioning' => $snap['positioning'] ?? null,
                'brand_bio' => $snap['brand_bio'] ?? null,
                'voice_description' => $snap['voice_description'] ?? null,
                'brand_look' => $snap['brand_look'] ?? null,
                'visual_style' => $snap['visual_style'] ?? null,
                'photography_style' => $snap['photography_style'] ?? null,
                'primary_colors' => $snap['primary_colors'] ?? [],
                'secondary_colors' => $snap['secondary_colors'] ?? [],
                'detected_fonts' => $snap['detected_fonts'] ?? [],
                'hero_headlines' => array_slice($snap['hero_headlines'] ?? [], 0, 10),
                'logo_url' => $snap['logo_url'] ?? null,
                'logo_description' => $snap['logo_description'] ?? null,
                'design_cues' => $snap['design_cues'] ?? null,
                'coherence_score' => $latestCompleted->coherence['overall']['score'] ?? null,
                'coherence_strengths' => array_slice($latestCompleted->coherence['overall']['strengths'] ?? [], 0, 5),
                'coherence_risks' => array_slice($latestCompleted->coherence['overall']['risks'] ?? [], 0, 5),
            ];
        }

        return [
            'runs' => $runs,
            'snapshots' => $snapshots,
            'latest_snapshot_data' => $latestSnapshotData,
        ];
    }

    protected function resolvePortalFeatures(\App\Models\Tenant $tenant): array
    {
        $featureGate = app(FeatureGate::class);

        return [
            'customization' => $featureGate->brandPortalCustomization($tenant),
            'public_access' => $featureGate->brandPortalPublicAccess($tenant),
            'sharing' => $featureGate->brandPortalAdvancedSharing($tenant),
            'agency_templates' => $featureGate->brandPortalAgencyTemplates($tenant),
        ];
    }

    /**
     * Recursively merge portal settings so partial updates never overwrite sibling keys.
     * Unlike array_replace_recursive, this also strips null leaves so clearing a field
     * via the UI (sending null) doesn't leave phantom keys.
     */
    protected static function deepMergePortal(array $original, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value) && isset($original[$key]) && is_array($original[$key])) {
                $original[$key] = self::deepMergePortal($original[$key], $value);
            } else {
                $original[$key] = $value;
            }
        }

        return $original;
    }

    protected static function deepUnwrapPayload(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['value'], $value['source'])) {
                $inner = $value['value'];
                $result[$key] = is_array($inner) ? self::deepUnwrapPayload($inner) : $inner;
            } elseif (is_array($value)) {
                $result[$key] = self::deepUnwrapPayload($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
