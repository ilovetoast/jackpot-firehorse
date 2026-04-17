<?php

namespace App\Http\Controllers;

use App\Jobs\RunMetadataInsightsJob;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiTagPolicyService;
use App\Services\AiUsageService;
use App\Services\BillingService;
use App\Services\CompanyCostService;
use App\Services\DownloadNameResolver;
use App\Services\EnterpriseDownloadPolicy;
use App\Services\FeatureGate;
use App\Services\PlanService;
use App\Services\TagQualityMetricsService;
use App\Traits\HandlesFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CompanyController extends Controller
{
    use HandlesFlashMessages;

    public function __construct(
        protected BillingService $billingService,
        protected AiUsageService $aiUsageService,
        protected AiTagPolicyService $aiTagPolicyService,
        protected TagQualityMetricsService $tagQualityMetricsService,
        protected CompanyCostService $companyCostService
    ) {}

    /**
     * Show the company management page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $companies = $user->tenants()->with('subscriptions')->get();
        $currentCompanyId = session('tenant_id');

        return Inertia::render('Companies/Index', [
            'companies' => $companies->map(function ($company) use ($currentCompanyId) {
                $currentPlan = $this->billingService->getCurrentPlan($company);
                // Query subscription directly instead of using Cashier's method (more reliable with Tenant model)
                $subscription = $company->subscriptions()
                    ->where('name', 'default')
                    ->orderBy('created_at', 'desc')
                    ->first();

                // Calculate AI costs for current month (all AI agents)
                $aiCosts = $this->companyCostService->calculateAIAgentCosts($company);

                // Get AI usage from ai_usage table (tagging, suggestions)
                $currentMonth = now()->startOfMonth();
                $aiUsage = \Illuminate\Support\Facades\DB::table('ai_usage')
                    ->where('tenant_id', $company->id)
                    ->where('usage_date', '>=', $currentMonth)
                    ->selectRaw('SUM(call_count) as total_calls, SUM(COALESCE(cost_usd, 0)) as total_cost')
                    ->first();

                // Combine AI agent costs (from ai_agent_runs) with ai_usage costs
                $totalAICost = ($aiCosts['total_cost'] ?? 0) + ($aiUsage->total_cost ?? 0);
                $totalAICalls = ($aiCosts['runs_count'] ?? 0) + ($aiUsage->total_calls ?? 0);

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                    'timezone' => $company->timezone ?? 'UTC',
                    'is_active' => $company->id == $currentCompanyId,
                    'billing' => [
                        'current_plan' => $currentPlan,
                        'subscription_status' => $subscription ? $subscription->stripe_status : 'none',
                    ],
                    'ai_estimates' => [
                        'current_month_cost' => round($totalAICost, 2),
                        'current_month_calls' => (int) $totalAICalls,
                        'agent_runs' => $aiCosts['runs_count'] ?? 0,
                        'agent_cost' => round($aiCosts['total_cost'] ?? 0, 2),
                        'usage_calls' => (int) ($aiUsage->total_calls ?? 0),
                        'usage_cost' => round($aiUsage->total_cost ?? 0, 2),
                    ],
                ];
            }),
        ]);
    }

    /**
     * Legacy URL: managed companies list now lives on the agency dashboard.
     */
    public function managedCompanies(): RedirectResponse
    {
        $tenant = app('tenant');

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'company' => 'You must select a company first.',
            ]);
        }

        if (! $tenant->is_agency) {
            return redirect()->route('overview');
        }

        return redirect()->route('agency.dashboard');
    }

    /**
     * Whether the user may switch into this brand for the given tenant (session).
     */
    protected function userCanSwitchToBrand(User $user, Tenant $tenant, Brand $brand): bool
    {
        if ($brand->tenant_id !== $tenant->id) {
            return false;
        }

        $role = $user->getRoleForTenant($tenant);
        if (in_array($role, ['admin', 'owner', 'agency_admin'], true)) {
            return true;
        }

        return $user->activeBrandMembership($brand) !== null;
    }

    /**
     * Switch to a different company.
     */
    public function switch(Request $request, Tenant $tenant)
    {
        $user = Auth::user();

        // Verify user belongs to this company
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        $brandId = $request->input('brand_id');
        if ($brandId !== null && $brandId !== '') {
            $brand = Brand::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $brandId)
                ->first();
            if (! $brand) {
                abort(404, 'Brand not found for this company.');
            }
            if (! $this->userCanSwitchToBrand($user, $tenant, $brand)) {
                abort(403, 'You do not have access to this brand.');
            }
            session([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
        } else {
            $defaultBrand = $tenant->defaultBrand;

            if (! $defaultBrand) {
                abort(500, 'Tenant must have at least one brand');
            }

            session([
                'tenant_id' => $tenant->id,
                'brand_id' => $defaultBrand->id,
            ]);
        }

        $target = $this->resolveCompanySwitchRedirectTarget($request);

        if ($this->shouldReturnJsonForWorkspaceSwitch($request)) {
            return response()->json([
                'ok' => true,
                'tenant_id' => $tenant->id,
                'redirect' => $target,
            ]);
        }

        return redirect()->to($target);
    }

    /**
     * Same destination as legacy redirect + redirectToIntendedApp, without issuing an HTTP redirect
     * (used for JSON workspace switch responses).
     */
    protected function resolveCompanySwitchRedirectTarget(Request $request): string
    {
        $redirect = $request->input('redirect');
        if ($redirect && is_string($redirect)) {
            $path = parse_url($redirect, PHP_URL_PATH) ?? '';
            if ($path !== '' && str_starts_with($path, '/app') && ! str_starts_with($path, '/app/api')) {
                return $redirect;
            }
        }

        return $this->peekIntendedAppUrl('/app/overview');
    }

    /**
     * Clear tenant/brand session and redirect to company picker.
     * Use when stuck in "No brand access" so user can re-select company and get fresh data.
     */
    public function resetSession(Request $request)
    {
        session()->forget(['tenant_id', 'brand_id']);

        return redirect()->route('companies.index');
    }

    /**
     * Create a new company for the authenticated user (becomes owner).
     * Mirrors the signup flow but for existing users adding another company.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
        ]);

        $baseSlug = \Illuminate\Support\Str::slug($validated['company_name']);
        $slug = $baseSlug;
        $counter = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $tenant = Tenant::create([
            'name' => $validated['company_name'],
            'slug' => $slug,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        $defaultBrand = $tenant->defaultBrand;
        if ($defaultBrand) {
            $defaultBrand->users()->syncWithoutDetaching([
                $user->id => ['role' => 'admin'],
            ]);
        }

        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand?->id,
        ]);

        return redirect()->route('overview')->with('success', 'Company created successfully.');
    }

    /**
     * Show the company settings page.
     */
    public function settings()
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active tenant from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You must select a company to view settings.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You do not have access to this company.',
            ]);
        }

        // Check if user has permission to view company settings
        // Check via tenant role permissions
        if (! $user->hasPermissionForTenant($tenant, 'company_settings.view')) {
            abort(403, 'Only administrators and owners can access company settings.');
        }

        // Get billing information
        $currentPlan = $this->billingService->getCurrentPlan($tenant);
        // Query subscription directly instead of using Cashier's method (more reliable with Tenant model)
        $subscription = $tenant->subscriptions()
            ->where('name', 'default')
            ->orderBy('created_at', 'desc')
            ->first();
        $teamMembersCount = $tenant->users()->count();
        $brandsCount = $tenant->brands()->count();

        // Get current owner and all tenant users (excluding current owner) for ownership transfer
        $currentOwner = $tenant->owner();
        $isCurrentUserOwner = $currentOwner && $currentOwner->id === $user->id;
        $tenantUsers = $tenant->users()
            ->where('users.id', '!=', $currentOwner?->id ?? 0)
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.email')
            ->get()
            ->map(function ($u) {
                $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));

                return [
                    'id' => $u->id,
                    'name' => $name ?: $u->email,
                    'email' => $u->email,
                ];
            });

        // Get pending ownership transfer if any
        $pendingTransfer = $tenant->ownershipTransfers()
            ->whereIn('status', [
                \App\Enums\OwnershipTransferStatus::PENDING,
                \App\Enums\OwnershipTransferStatus::CONFIRMED,
                \App\Enums\OwnershipTransferStatus::ACCEPTED,
            ])
            ->with(['fromUser', 'toUser', 'initiatedBy'])
            ->latest()
            ->first();

        $pendingTransferData = null;
        if ($pendingTransfer) {
            $pendingTransferData = [
                'id' => $pendingTransfer->id,
                'status' => $pendingTransfer->status->value,
                'status_label' => ucfirst($pendingTransfer->status->value),
                'from_user' => [
                    'id' => $pendingTransfer->fromUser->id,
                    'name' => $pendingTransfer->fromUser->name,
                    'email' => $pendingTransfer->fromUser->email,
                ],
                'to_user' => [
                    'id' => $pendingTransfer->toUser->id,
                    'name' => $pendingTransfer->toUser->name,
                    'email' => $pendingTransfer->toUser->email,
                ],
                'initiated_at' => $pendingTransfer->initiated_at?->toIso8601String(),
                'confirmed_at' => $pendingTransfer->confirmed_at?->toIso8601String(),
                'accepted_at' => $pendingTransfer->accepted_at?->toIso8601String(),
                'can_cancel' => $pendingTransfer->from_user_id === $user->id || $pendingTransfer->to_user_id === $user->id,
            ];
        }

        $defaultBrand = $tenant->defaultBrand;

        // Enterprise Download Policy (read-only UX surface; Premium/Enterprise plans)
        $enterpriseDownloadPolicy = null;
        if (in_array($currentPlan, ['business', 'premium', 'enterprise'])) {
            $policy = app(EnterpriseDownloadPolicy::class);
            $enterpriseDownloadPolicy = [
                'disable_single_asset_downloads' => $policy->disableSingleAssetDownloads($tenant),
                'require_password_for_public' => $policy->requirePasswordForPublic($tenant),
                'force_expiration_days' => $policy->forceExpirationDays($tenant),
                'disallow_non_expiring' => $policy->disallowNonExpiring($tenant),
            ];
        }

        // Domain for company URL slug display (from APP_URL so staging/production show correct host)
        $companyUrlDomain = config('subdomain.main_domain') ?: parse_url(config('app.url'), PHP_URL_HOST) ?: 'jackpot.local';

        $planService = app(PlanService::class);
        $canUseRequireLandingPage = $planService->canUseRequireLandingPage($tenant);

        $canManageAgencies = $user->hasPermissionForTenant($tenant, 'team.manage');
        $settingsBrands = $tenant->brands()->orderBy('name')->get(['id', 'name']);
        $creatorModuleEnabled = app(FeatureGate::class)->creatorModuleEnabled($tenant);
        $creatorModuleBrands = $creatorModuleEnabled
            ? $settingsBrands->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()->all()
            : [];
        $linkedAgencies = [];
        if ($canManageAgencies) {
            $linkedAgencies = \App\Models\TenantAgency::query()
                ->where('tenant_id', $tenant->id)
                ->with(['agencyTenant:id,name,slug'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn (\App\Models\TenantAgency $row) => $row->toApiArray());
        }

        // Phase M-2: Include tenant settings
        return Inertia::render('Companies/Settings', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone ?? 'UTC',
                'settings' => $tenant->settings ?? [],
                'default_brand_name' => $defaultBrand?->name ?? null,
            ],
            'company_url_domain' => $companyUrlDomain,
            'billing' => [
                'current_plan' => $currentPlan,
                'subscription_status' => $subscription ? $subscription->stripe_status : 'none',
            ],
            'team_members_count' => $teamMembersCount,
            'brands_count' => $brandsCount,
            'is_current_user_owner' => $isCurrentUserOwner,
            'tenant_users' => $tenantUsers,
            'pending_transfer' => $pendingTransferData,
            'enterprise_download_policy' => $enterpriseDownloadPolicy,
            'can_use_require_landing_page' => $canUseRequireLandingPage,
            'settings_brands' => $settingsBrands,
            'linked_agencies' => $linkedAgencies,
            'can_manage_agencies' => $canManageAgencies,
            'creator_module_enabled' => $creatorModuleEnabled,
            'creator_module_brands' => $creatorModuleBrands,
        ]);
    }

    /**
     * Update the company settings.
     */
    public function updateSettings(Request $request)
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active tenant from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You must select a company to update settings.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Check if user has permission to edit company settings
        if (! $user->hasPermissionForTenant($tenant, 'company_settings.view')) {
            abort(403, 'You do not have access to company settings.');
        }
        if (! $user->hasPermissionForTenant($tenant, 'company_settings.edit')) {
            abort(403, 'You do not have permission to edit company settings.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'regex:/^[a-z0-9-]+$/',
                'not_regex:/^-/',
                'not_regex:/-$/',
                function ($attribute, $value, $fail) use ($tenant) {
                    // Check if slug is taken by another tenant
                    $existingTenant = Tenant::where('slug', $value)
                        ->where('id', '!=', $tenant->id)
                        ->exists();

                    if ($existingTenant) {
                        $fail('This slug is already taken by another company.');
                    }

                    // Check against reserved slugs
                    $reservedSlugs = config('subdomain.reserved_slugs', []);

                    if (in_array($value, $reservedSlugs, true)) {
                        $fail('This slug is reserved and cannot be used.');
                    }
                },
            ],
            'timezone' => 'required|string|max:255',
            'settings' => 'nullable|array',
            'settings.enable_metadata_approval' => 'nullable|boolean', // Phase M-2
            'settings.features' => 'nullable|array', // Phase J.3.1
            'settings.features.contributor_asset_approval' => 'nullable|boolean', // Phase J.3.1
            'settings.download_name_template' => [
                'nullable',
                'string',
                'max:500',
                function ($attribute, $value, $fail) use ($tenant) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $resolver = app(DownloadNameResolver::class);
                    $msg = $resolver->validateTemplate($value);
                    if ($msg !== null) {
                        $fail($msg);

                        return;
                    }
                    $resolved = $resolver->resolve($value, $tenant, $tenant->defaultBrand ?? null, null);
                    $resolvedMsg = $resolver->validateResolved($resolved);
                    if ($resolvedMsg !== null) {
                        $fail('Resolved preview: '.$resolvedMsg);
                    }
                },
            ],
            'settings.require_landing_page' => 'nullable|boolean',
            'settings.generative_enabled' => 'nullable|boolean',
            'settings.ai_enabled' => 'nullable|boolean',
        ]);

        // Phase M-2: Handle settings separately
        $settings = $validated['settings'] ?? [];
        unset($validated['settings']);

        // Phase J.3.1: Deep merge settings to preserve nested structure
        $currentSettings = $tenant->settings ?? [];
        $mergedSettings = array_merge_recursive($currentSettings, $settings);

        // Fix array_merge_recursive behavior for boolean values (it creates arrays)
        if (isset($settings['enable_metadata_approval'])) {
            $mergedSettings['enable_metadata_approval'] = $settings['enable_metadata_approval'];
        }
        if (isset($settings['features']['contributor_asset_approval'])) {
            $mergedSettings['features']['contributor_asset_approval'] = $settings['features']['contributor_asset_approval'];
        }
        if (array_key_exists('download_name_template', $settings)) {
            $mergedSettings['download_name_template'] = $settings['download_name_template'] === ''
                ? null
                : $settings['download_name_template'];
        }
        if (array_key_exists('require_landing_page', $settings)) {
            $planService = app(PlanService::class);
            if ($planService->canUseRequireLandingPage($tenant)) {
                $mergedSettings['require_landing_page'] = (bool) $settings['require_landing_page'];
            } else {
                $mergedSettings['require_landing_page'] = false;
            }
        }
        if (array_key_exists('generative_enabled', $settings)) {
            if (! $user->hasPermissionForTenant($tenant, 'company_settings.manage_generative')) {
                abort(403, 'You do not have permission to manage generative settings.');
            }
            $mergedSettings['generative_enabled'] = (bool) $settings['generative_enabled'];
        }
        if (array_key_exists('ai_enabled', $settings)) {
            if (! $user->hasPermissionForTenant($tenant, 'company_settings.manage_ai_settings')) {
                abort(403, 'You do not have permission to manage AI settings.');
            }
            $mergedSettings['ai_enabled'] = (bool) $settings['ai_enabled'];
        }

        $tenant->update($validated);
        $tenant->update(['settings' => $mergedSettings]);

        return $this->backWithSuccess('Updated');
    }

    /**
     * D12: Update Enterprise Download Policy (tenant-level overrides). Enterprise plan only.
     */
    public function updateDownloadPolicy(Request $request)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors(['settings' => 'You must select a company to update settings.']);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        if (! $user->hasPermissionForTenant($tenant, 'company_settings.manage_download_policy')) {
            abort(403, 'You do not have permission to manage download policy.');
        }

        $currentPlan = $this->billingService->getCurrentPlan($tenant);
        if (! in_array($currentPlan, ['business', 'premium', 'enterprise'])) {
            return redirect()->back()->withErrors(['download_policy' => 'Download policy is available on the Business or Enterprise plan.']);
        }

        $validated = $request->validate([
            'disable_single_asset_downloads' => 'nullable|boolean',
            'require_password_for_public' => 'nullable|boolean',
            'force_expiration_days' => 'nullable|integer|min:1|max:365',
            'disallow_non_expiring' => 'nullable|boolean',
        ]);

        // Merge all validated keys (including null) so clearing "Enforce expiration" persists
        $currentSettings = $tenant->settings ?? [];
        $currentPolicy = $currentSettings['download_policy'] ?? [];
        $mergedPolicy = is_array($currentPolicy) ? array_merge($currentPolicy, $validated) : $validated;
        $currentSettings['download_policy'] = $mergedPolicy;
        $tenant->update(['settings' => $currentSettings]);

        return redirect()->back()
            ->with('success', 'Download policy updated.')
            ->with('download_policy_saved', true);
    }

    /**
     * Show the company activity logs page.
     */
    public function activity(Request $request): Response
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active company from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'activity' => 'You must select a company to view activity logs.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors([
                'activity' => 'You do not have access to this company.',
            ]);
        }

        // Check if user has permission to view activity logs
        if (! $user->hasPermissionForTenant($tenant, 'activity_logs.view')) {
            abort(403, 'You do not have permission to view activity logs.');
        }

        $query = \App\Models\ActivityEvent::query()
            ->where('tenant_id', $tenant->id) // Only show events for this company
            ->where('event_type', '!=', \App\Enums\EventType::AI_SYSTEM_INSIGHT); // Exclude system-level AI insights

        // Filter by event type
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        // Exclude portal/gateway views by default (often noisy; user can include via event_type filter)
        $excludePortalViews = $request->has('exclude_portal_views')
            ? $request->boolean('exclude_portal_views')
            : true;
        if ($excludePortalViews) {
            $query->whereNotIn('event_type', [
                \App\Enums\EventType::PORTAL_VIEWED,
                \App\Enums\EventType::GATEWAY_VIEWED,
            ]);
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
            $query->where('created_at', '<=', $request->date_to.' 23:59:59');
        }

        // Filter by brand
        if ($request->filled('brand_id')) {
            if ($request->brand_id === 'null') {
                $query->whereNull('brand_id');
            } else {
                $query->where('brand_id', $request->brand_id);
            }
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        // Only include events with valid subject_type to avoid MorphTo "Class not found" (e.g. subject_type = 'unknown')
        $validSubjectTypes = [
            \App\Models\Asset::class,
            \App\Models\User::class,
            \App\Models\Tenant::class,
            \App\Models\Brand::class,
            \App\Models\Category::class,
            \App\Models\Collection::class,
        ];
        $query->where(function ($q) use ($validSubjectTypes) {
            $q->whereIn('subject_type', $validSubjectTypes)->orWhereNull('subject_type');
        });

        // Paginate results
        $perPage = (int) $request->get('per_page', 50);
        // Don't eager load actor to avoid errors with string types (system, api, guest)
        // We'll load it manually in the formatting method
        // Eager load relationships
        $events = $query->with(['brand', 'subject', 'tenant'])
            ->paginate($perPage)
            ->appends($request->except('page'));

        // Get filter options (only for this company)
        $brands = $tenant->brands()->orderBy('name')->get(['id', 'name']);
        $eventTypes = \App\Enums\EventType::all();
        $actorTypes = ['user', 'system', 'api', 'guest'];

        // Get unique subject types from this company's events
        $subjectTypes = \App\Models\ActivityEvent::select('subject_type')
            ->where('tenant_id', $tenant->id)
            ->distinct()
            ->whereNotNull('subject_type')
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->toArray();

        // Format events for display
        $formattedEvents = $events->map(function ($event) use ($tenant) {
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'description' => $this->formatActivityDescription($event, $tenant),
                'created_at' => $event->created_at->toDateTimeString(),
                'created_at_human' => $event->created_at->diffForHumans(),
                'brand' => $event->brand ? [
                    'id' => $event->brand->id,
                    'name' => $event->brand->name,
                ] : null,
                'tenant' => $tenant->name,
                'actor' => $this->formatActor($event),
                'subject' => $this->formatSubject($event),
                'subject_url' => $this->buildSubjectUrl($event),
                'metadata' => $event->metadata,
                'metadata_summary' => $this->formatMetadataSummary($event),
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
            ];
        });

        return Inertia::render('Companies/Activity', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'events' => $formattedEvents,
            'pagination' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
            'filters' => [
                'event_type' => $request->event_type,
                'actor_type' => $request->actor_type,
                'subject_type' => $request->subject_type,
                'brand_id' => $request->brand_id,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'exclude_portal_views' => $request->has('exclude_portal_views') ? $request->boolean('exclude_portal_views') : true,
                'per_page' => $perPage,
            ],
            'filter_options' => [
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
        if (! $event->actor_type) {
            return [
                'type' => 'unknown',
                'name' => 'Unknown',
            ];
        }

        // Handle string actor types (system, api, guest) that aren't models
        $stringActorTypes = ['system', 'api', 'guest'];
        if (in_array($event->actor_type, $stringActorTypes, true)) {
            return [
                'type' => $event->actor_type,
                'name' => ucfirst($event->actor_type),
            ];
        }

        // For 'user' type, manually load the User model to avoid relationship errors
        if ($event->actor_type === 'user' && $event->actor_id) {
            try {
                $user = \App\Models\User::find($event->actor_id);
                if ($user) {
                    return [
                        'type' => 'user',
                        'id' => $user->id,
                        'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
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
        if (! $event->subject_type || ! $event->subject_id) {
            return null;
        }

        if ($event->subject) {
            $name = match (true) {
                method_exists($event->subject, 'name') => $event->subject->name,
                method_exists($event->subject, 'title') => $event->subject->title,
                method_exists($event->subject, 'email') => $event->subject->email,
                default => '#'.$event->subject->id,
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
            'name' => '#'.$event->subject_id,
        ];
    }

    /**
     * Build a URL to the subject for quick navigation (asset, brand, category).
     */
    private function buildSubjectUrl($event): ?string
    {
        if (! $event->subject_type || ! $event->subject_id) {
            return null;
        }

        $subjectType = $event->subject_type;
        $subjectId = $event->subject_id;
        $brandId = $event->brand_id;

        if (str_ends_with($subjectType, 'Asset')) {
            return url("/app/assets/{$subjectId}/view");
        }
        if (str_ends_with($subjectType, 'Brand')) {
            return url("/app/brands/{$subjectId}/edit");
        }
        if (str_ends_with($subjectType, 'Category') && $brandId) {
            return url("/app/brands/{$brandId}/categories/{$subjectId}/edit");
        }

        return null;
    }

    /**
     * Format metadata as human-readable summary (excludes raw technical fields).
     */
    private function formatMetadataSummary($event): ?array
    {
        $metadata = $event->metadata ?? [];
        if (empty($metadata) || ! is_array($metadata)) {
            return null;
        }

        $skip = ['portal', 'brand_slug', 'subject_name', 'subject_type', 'subject_id'];
        $labels = [
            'collection_count' => 'Collections',
            'asset_count' => 'Assets',
            'has_content' => 'Has content',
            'role' => 'Role',
            'old_role' => 'Previous role',
            'new_role' => 'New role',
            'old_plan' => 'Previous plan',
            'new_plan' => 'New plan',
            'plan' => 'Plan',
            'amount' => 'Amount',
            'currency' => 'Currency',
            'action' => 'Action',
            'fields_updated' => 'Fields updated',
            'old_version' => 'Old version',
            'new_version' => 'New version',
        ];

        $summary = [];
        foreach ($metadata as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            $label = $labels[$key] ?? str_replace('_', ' ', ucfirst($key));
            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            } elseif (is_array($value)) {
                $value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
            } elseif (is_object($value)) {
                $value = json_encode($value);
            } else {
                $value = (string) $value;
            }
            $summary[] = ['label' => $label, 'value' => $value];
        }

        return empty($summary) ? null : $summary;
    }

    /**
     * Format activity description for display with human-readable language.
     */
    private function formatActivityDescription($event, $tenant): string
    {
        $eventType = $event->event_type;
        $metadata = $event->metadata ?? [];

        // Ensure metadata is an array (it should be cast, but just in case)
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }

        // Check if this is a tenant event first (check both enum constants and string values)
        $tenantEventTypes = [
            \App\Enums\EventType::TENANT_CREATED,
            \App\Enums\EventType::TENANT_UPDATED,
            \App\Enums\EventType::TENANT_DELETED,
            'tenant.created',
            'tenant.updated',
            'tenant.deleted',
        ];
        $isTenantEvent = in_array($eventType, $tenantEventTypes, true);

        // For tenant events, always use "Company" or tenant name, never show ID
        if ($isTenantEvent) {
            // For tenant events, the event is always about the current tenant
            // So we should use the current tenant's name, not try to load from subject
            // This is more reliable since we're viewing the activity log for a specific tenant
            $tenantName = $tenant ? $tenant->name : 'Company';

            // However, if metadata has subject_name, that might be more accurate (e.g., if name changed)
            if (isset($metadata['subject_name'])) {
                $tenantName = $metadata['subject_name'];
            }

            // Format tenant events
            if ($eventType === \App\Enums\EventType::TENANT_UPDATED || $eventType === 'tenant.updated') {
                return "Updated {$tenantName}";
            }
            if ($eventType === \App\Enums\EventType::TENANT_CREATED || $eventType === 'tenant.created') {
                return "Created {$tenantName}";
            }
            if ($eventType === \App\Enums\EventType::TENANT_DELETED || $eventType === 'tenant.deleted') {
                return "Deleted {$tenantName}";
            }
        }

        // Get subject name if available (for non-tenant events)
        $subjectName = null;

        // PRIORITY 1: Check metadata first (most reliable, always stored for new events)
        if (isset($metadata['subject_name']) && ! empty($metadata['subject_name'])) {
            $subjectName = $metadata['subject_name'];
        }

        // PRIORITY 2: Try to get from subject relationship if metadata doesn't have it
        if (! $subjectName && $event->subject) {
            // Check if it's a Tenant model
            if ($event->subject instanceof \App\Models\Tenant) {
                $subjectName = $event->subject->name ?? null;
            } elseif (method_exists($event->subject, 'getNameAttribute')) {
                $subjectName = $event->subject->name;
            } elseif (isset($event->subject->name)) {
                $subjectName = $event->subject->name;
            } elseif (method_exists($event->subject, 'getTitleAttribute')) {
                $subjectName = $event->subject->title ?? null;
            }
        }

        // Try brand name for brand-related events
        if (! $subjectName && $event->brand) {
            $subjectName = $event->brand->name ?? null;
        }

        // If we still don't have a name but have subject_id, try to load it directly from database
        // This is important because the subject relationship might not be loaded or the model might be soft-deleted
        if (! $subjectName && $event->subject_id && $event->subject_type) {
            try {
                // Try to load the model based on subject_type
                $subjectClass = $event->subject_type;

                // Handle different class name formats
                if (str_contains($subjectClass, '\\')) {
                    // Full class name like "App\Models\Tenant"
                    if (class_exists($subjectClass)) {
                        $subjectModel = $subjectClass::find($event->subject_id);
                        if ($subjectModel) {
                            if (isset($subjectModel->name)) {
                                $subjectName = $subjectModel->name;
                            } elseif (isset($subjectModel->title)) {
                                $subjectName = $subjectModel->title;
                            } elseif (method_exists($subjectModel, 'getNameAttribute')) {
                                $subjectName = $subjectModel->name ?? null;
                            }
                        }
                    }
                } else {
                    // Try common model classes
                    $possibleClasses = [
                        "App\\Models\\{$subjectClass}",
                        'App\\Models\\'.ucfirst($subjectClass),
                    ];

                    foreach ($possibleClasses as $class) {
                        if (class_exists($class)) {
                            $subjectModel = $class::find($event->subject_id);
                            if ($subjectModel) {
                                if (isset($subjectModel->name)) {
                                    $subjectName = $subjectModel->name;
                                    break;
                                } elseif (isset($subjectModel->title)) {
                                    $subjectName = $subjectModel->title;
                                    break;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors - model might be deleted or class doesn't exist
            }
        }

        // If no subject name but we have subject_id, use just the number with note
        $subjectIdentifier = $subjectName;
        if (! $subjectIdentifier && $event->subject_id) {
            $subjectIdentifier = "#{$event->subject_id} (no longer exists)";
        }

        // Format based on event type with human-readable language
        switch ($eventType) {
            case \App\Enums\EventType::BRAND_CREATED:
                return $subjectIdentifier ? "Created {$subjectIdentifier}" : 'Created brand';
            case \App\Enums\EventType::BRAND_UPDATED:
                return $subjectIdentifier ? "Updated {$subjectIdentifier}" : 'Updated brand';
            case \App\Enums\EventType::BRAND_DELETED:
                return $subjectIdentifier ? "Deleted {$subjectIdentifier}" : 'Deleted brand';
            case \App\Enums\EventType::USER_CREATED:
                return $subjectIdentifier ? "Created {$subjectIdentifier} account" : 'Created user account';
            case \App\Enums\EventType::USER_UPDATED:
                $action = $metadata['action'] ?? 'updated';
                if ($action === 'suspended') {
                    return $subjectIdentifier ? "Suspended {$subjectIdentifier} account" : 'Suspended account';
                } elseif ($action === 'unsuspended') {
                    return $subjectIdentifier ? "Unsuspended {$subjectIdentifier} account" : 'Unsuspended account';
                }

                return $subjectIdentifier ? "Updated {$subjectIdentifier} account" : 'Updated user account';
            case \App\Enums\EventType::USER_DELETED:
                return $subjectIdentifier ? "Deleted {$subjectIdentifier} account" : 'Deleted user account';
            case \App\Enums\EventType::USER_INVITED:
                return 'Invited user';
            case \App\Enums\EventType::USER_REMOVED_FROM_COMPANY:
                return 'Removed user from company';
            case \App\Enums\EventType::USER_ADDED_TO_BRAND:
                $role = $metadata['role'] ?? 'member';
                $brandName = $event->brand->name ?? null;

                return $brandName ? "Added to {$brandName} as {$role}" : "Added to brand as {$role}";
            case \App\Enums\EventType::USER_REMOVED_FROM_BRAND:
                $brandName = $event->brand->name ?? null;

                return $brandName ? "Removed from {$brandName}" : 'Removed from brand';
            case \App\Enums\EventType::USER_ROLE_UPDATED:
                $oldRole = $metadata['old_role'] ?? null;
                $newRole = $metadata['new_role'] ?? null;
                if ($oldRole && $newRole) {
                    return "Changed role from {$oldRole} to {$newRole}";
                }

                return 'Updated role';
            case \App\Enums\EventType::CATEGORY_CREATED:
                $brandContext = $event->brand ? " for {$event->brand->name}" : '';

                return $subjectIdentifier ? "Created {$subjectIdentifier} Category{$brandContext}" : 'Created category';
            case \App\Enums\EventType::CATEGORY_UPDATED:
                $brandContext = $event->brand ? " for {$event->brand->name}" : '';

                return $subjectIdentifier ? "Updated {$subjectIdentifier} Category{$brandContext}" : 'Updated category';
            case \App\Enums\EventType::CATEGORY_DELETED:
                $brandContext = $event->brand ? " for {$event->brand->name}" : '';

                return $subjectIdentifier ? "Deleted {$subjectIdentifier} Category{$brandContext}" : 'Deleted category';
            case \App\Enums\EventType::CATEGORY_SYSTEM_UPGRADED:
                $fieldsUpdated = $metadata['fields_updated'] ?? [];
                $oldVersion = $metadata['old_version'] ?? null;
                $newVersion = $metadata['new_version'] ?? null;
                $versionInfo = ($oldVersion && $newVersion) ? " (v{$oldVersion} → v{$newVersion})" : '';
                if ($subjectIdentifier) {
                    return "Upgraded {$subjectIdentifier}{$versionInfo}";
                }

                return "Upgraded category{$versionInfo}";
            case \App\Enums\EventType::PLAN_UPDATED:
                $oldPlan = $metadata['old_plan'] ?? null;
                $newPlan = $metadata['new_plan'] ?? null;
                $oldPlanName = $oldPlan ? ucfirst($oldPlan) : null;
                $newPlanName = $newPlan ? ucfirst($newPlan) : null;
                if ($oldPlanName && $newPlanName) {
                    return "Changed plan from {$oldPlanName} to {$newPlanName}";
                }

                return 'Updated plan';
            case \App\Enums\EventType::SUBSCRIPTION_CREATED:
                $planName = $metadata['plan'] ?? $metadata['new_plan'] ?? $metadata['plan_name'] ?? null;
                $planDisplayName = $planName ? ucfirst($planName).' plan' : 'subscription';

                return "Started {$planDisplayName} subscription";
            case \App\Enums\EventType::SUBSCRIPTION_UPDATED:
                $action = $metadata['action'] ?? null;
                $oldPlan = $metadata['old_plan'] ?? null;
                $newPlan = $metadata['new_plan'] ?? null;
                $oldPlanName = $oldPlan ? ucfirst($oldPlan) : null;
                $newPlanName = $newPlan ? ucfirst($newPlan) : null;

                if ($action === 'created' && $newPlanName) {
                    return "Started {$newPlanName} subscription";
                } elseif ($action === 'resumed' && $newPlanName) {
                    return "Resumed {$newPlanName} subscription";
                } elseif ($action === 'upgrade' && $oldPlanName && $newPlanName) {
                    return "Upgraded subscription from {$oldPlanName} to {$newPlanName}";
                } elseif ($action === 'downgrade' && $oldPlanName && $newPlanName) {
                    return "Downgraded subscription from {$oldPlanName} to {$newPlanName}";
                } elseif ($oldPlanName && $newPlanName) {
                    return "Changed subscription from {$oldPlanName} to {$newPlanName}";
                } elseif ($newPlanName) {
                    return "Updated subscription to {$newPlanName}";
                }

                return 'Updated subscription';
            case \App\Enums\EventType::SUBSCRIPTION_CANCELED:
                $planName = $metadata['plan'] ?? $metadata['old_plan'] ?? $metadata['plan_name'] ?? null;
                $planDisplayName = $planName ? ucfirst($planName).' plan' : '';

                return $planDisplayName ? "Canceled {$planDisplayName} subscription" : 'Canceled subscription';
            case \App\Enums\EventType::INVOICE_PAID:
                $amount = $metadata['amount'] ?? null;
                $currency = $metadata['currency'] ?? 'USD';
                if ($amount) {
                    $formattedAmount = is_numeric($amount) ? number_format($amount / 100, 2) : $amount;

                    return "Paid invoice ({$currency} {$formattedAmount})";
                }

                return 'Paid invoice';
            case \App\Enums\EventType::INVOICE_FAILED:
                $amount = $metadata['amount'] ?? null;
                $currency = $metadata['currency'] ?? 'USD';
                if ($amount) {
                    $formattedAmount = is_numeric($amount) ? number_format($amount / 100, 2) : $amount;

                    return "Invoice payment failed ({$currency} {$formattedAmount})";
                }

                return 'Invoice payment failed';
            case \App\Enums\EventType::PORTAL_VIEWED:
                $collections = $metadata['collection_count'] ?? 0;
                $assets = $metadata['asset_count'] ?? 0;
                $hasContent = $metadata['has_content'] ?? ($collections > 0 || $assets > 0);
                if ($hasContent) {
                    $parts = [];
                    if ($collections > 0) {
                        $parts[] = $collections.' '.($collections === 1 ? 'collection' : 'collections');
                    }
                    if ($assets > 0) {
                        $parts[] = $assets.' '.($assets === 1 ? 'asset' : 'assets');
                    }
                    $content = implode(', ', $parts);

                    return $content ? "Opened public portal ({$content})" : 'Opened public portal';
                }

                return 'Opened public portal (empty)';
            case \App\Enums\EventType::PORTAL_COLLECTION_VIEWED:
                return $subjectIdentifier ? "Viewed collection {$subjectIdentifier}" : 'Viewed collection';
            case \App\Enums\EventType::PORTAL_ASSET_CLICKED:
                return $subjectIdentifier ? "Viewed asset {$subjectIdentifier}" : 'Viewed asset';
            case \App\Enums\EventType::PORTAL_DOWNLOAD:
                return $subjectIdentifier ? "Downloaded {$subjectIdentifier}" : 'Downloaded asset';
            case \App\Enums\EventType::ASSET_UPLOADED:
                return $subjectIdentifier ? "Uploaded {$subjectIdentifier}" : 'Uploaded asset';
            case \App\Enums\EventType::ASSET_DOWNLOAD_CREATED:
            case \App\Enums\EventType::ASSET_DOWNLOAD_COMPLETED:
                return $subjectIdentifier ? "Downloaded {$subjectIdentifier}" : 'Downloaded asset';
            case \App\Enums\EventType::ASSET_SHARED_LINK_CREATED:
                return $subjectIdentifier ? "Created share link for {$subjectIdentifier}" : 'Created share link';
            case \App\Enums\EventType::ASSET_SHARED_LINK_ACCESSED:
                return $subjectIdentifier ? "Accessed share link for {$subjectIdentifier}" : 'Accessed share link';
            case \App\Enums\EventType::ASSET_SHARED_LINK_REVOKED:
                return $subjectIdentifier ? "Revoked share link for {$subjectIdentifier}" : 'Revoked share link';
            case \App\Enums\EventType::ASSET_PREVIEWED:
                return $subjectIdentifier ? "Previewed {$subjectIdentifier}" : 'Previewed asset';
            case \App\Enums\EventType::GATEWAY_VIEWED:
                return 'Viewed brand gateway';
            case \App\Enums\EventType::GATEWAY_LOGIN:
                return 'Logged in via gateway';
            case \App\Enums\EventType::GATEWAY_ENTER_CLICKED:
                return 'Entered brand portal';
            case \App\Enums\EventType::DOWNLOAD_LANDING_PAGE_VIEWED:
                return $subjectIdentifier ? "Viewed download page for {$subjectIdentifier}" : 'Viewed download page';
            default:
                // Fallback: format event type nicely
                return ucfirst(str_replace(['_', '.'], ' ', $eventType));
        }
    }

    /**
     * Delete the company (tenant).
     */
    public function destroy(Request $request)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'error' => 'You must select a company to delete.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Owner, or incubating agency steward (pre-transfer), via CompanyPolicy + PermissionMap
        $this->authorize('delete', $tenant);

        // Prevent deletion if there are other non-agency members (incubated clients may keep agency team on the tenant).
        // Agency staff rows: is_agency_managed = 1 and agency_tenant_id = incubating agency — do not count as blockers.
        $otherUsersQuery = $tenant->users()->where('users.id', '!=', $user->id);
        if ($tenant->incubated_by_agency_id) {
            $incubatingId = (int) $tenant->incubated_by_agency_id;
            $otherUsersQuery->whereRaw(
                'NOT (COALESCE(tenant_user.is_agency_managed, 0) = 1 AND tenant_user.agency_tenant_id = ?)',
                [$incubatingId]
            );
        }
        $otherUsersCount = $otherUsersQuery->count();
        if ($otherUsersCount > 0) {
            return back()->withErrors([
                'error' => 'Cannot delete company. Please remove all other team members first.',
            ]);
        }

        // Cancel any active subscriptions
        try {
            $subscription = $tenant->subscription('default');
            if ($subscription && $subscription->active()) {
                $subscription->cancel();
            }
        } catch (\Exception $e) {
            // Log but don't fail deletion if subscription cancellation fails
            \Log::warning('Failed to cancel subscription during company deletion', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Record activity before deletion
        \App\Services\ActivityRecorder::record(
            tenant: $tenant->id,
            eventType: \App\Enums\EventType::TENANT_DELETED,
            subject: $tenant,
            actor: $user,
            brand: null,
            metadata: [
                'subject_name' => $tenant->name,
            ]
        );

        $tenantName = $tenant->name;

        // Delete the tenant (cascade will handle related data)
        $tenant->delete();

        // Clear session if this was the active tenant
        if (session('tenant_id') == $tenant->id) {
            session()->forget(['tenant_id', 'brand_id']);
        }

        return redirect()->route('companies.index')
            ->with('success', "Company '{$tenantName}' has been permanently deleted.");
    }

    /**
     * Show the company permissions page.
     */
    public function permissions(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'permissions' => 'You must select a company to view permissions.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors([
                'permissions' => 'You do not have access to this company.',
            ]);
        }

        // Check if user has permission to view permissions (owners/admins can view)
        $tenantRole = $user->getRoleForTenant($tenant);
        if (! in_array($tenantRole, ['owner', 'admin'])) {
            abort(403, 'Only administrators and owners can view permissions.');
        }

        // Get all company roles (tenant-level roles, ordered by hierarchy)
        // Note: Only Owner, Admin, and Member are tenant-level roles. All other roles are brand-scoped.
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

        // Get company permissions (all permissions that are NOT site permissions)
        $companyPermissions = Permission::where(function ($query) {
            $query->whereNotIn('name', ['company.manage', 'permissions.manage'])
                ->where('name', 'not like', 'site.%');
        })
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        // Get current role permissions
        $companyRolePermissions = [];
        foreach ($companyRoles as $roleData) {
            $role = Role::where('name', $roleData['id'])->first();
            if ($role) {
                $permissions = $role->permissions->pluck('name')->toArray();
                $companyRolePermissions[$roleData['id']] = array_fill_keys($permissions, true);
            }
        }

        return Inertia::render('Companies/Permissions', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'company_roles' => $companyRoles,
            'company_permissions' => $companyPermissions,
            'company_role_permissions' => $companyRolePermissions,
        ]);
    }

    /**
     * Check if a company slug is available.
     *
     * GET /api/companies/check-slug?slug=company-name
     */
    public function checkSlugAvailability(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenant = app('tenant');

            if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Check if user has permission to edit company settings
            if (! $user->hasPermissionForTenant($tenant, 'company_settings.view')) {
                return response()->json(['error' => 'You do not have permission to check slug availability.'], 403);
            }

            $validated = $request->validate([
                'slug' => [
                    'required',
                    'string',
                    'min:3',
                    'max:50',
                    'regex:/^[a-z0-9-]+$/',
                    'not_regex:/^-/',
                    'not_regex:/-$/',
                ],
            ]);

            $slug = $validated['slug'];

            // Check if slug is taken by another tenant
            $existingTenant = Tenant::where('slug', $slug)
                ->where('id', '!=', $tenant->id)
                ->exists();

            // Check against reserved slugs from configuration
            $reservedSlugs = config('subdomain.reserved_slugs', []);
            $isReserved = in_array($slug, $reservedSlugs, true);

            return response()->json([
                'available' => ! $existingTenant && ! $isReserved,
                'slug' => $slug,
                'reason' => $existingTenant ? 'taken' : ($isReserved ? 'reserved' : null),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'available' => false,
                'slug' => $request->input('slug'),
                'reason' => 'invalid',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error checking slug availability', [
                'tenant_id' => app('tenant')?->id,
                'user_id' => Auth::id(),
                'slug' => $request->input('slug'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'available' => false,
                'slug' => $request->input('slug'),
                'reason' => 'error',
                'error' => 'Failed to check slug availability. Please try again.',
            ], 500);
        }
    }

    /**
     * Get AI usage status for the current tenant.
     *
     * GET /api/companies/ai-usage
     * Optional query: year (e.g. 2026), month (1-12) to view a specific month (for paging back).
     *
     * Admin-only endpoint to view AI usage and caps.
     */
    public function getAiUsage(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenant = app('tenant');

            if (! $tenant) {
                return response()->json(['error' => 'Tenant not found'], 404);
            }

            // Check permission (admin only)
            if (! $user->hasPermissionForTenant($tenant, 'ai.usage.view')) {
                return response()->json(['error' => 'You do not have permission to view AI usage.'], 403);
            }

            $year = $request->has('year') ? (int) $request->input('year') : null;
            $month = $request->has('month') ? (int) $request->input('month') : null;

            if ($year !== null && $month !== null) {
                // Validate: year 2020-2030, month 1-12
                if ($year < 2020 || $year > 2030 || $month < 1 || $month > 12) {
                    return response()->json(['error' => 'Invalid year or month.'], 400);
                }
                $usageStatus = $this->aiUsageService->getUsageStatusForPeriod($tenant, $year, $month);
                $breakdown = [];
                foreach (['tagging', 'suggestions'] as $feature) {
                    $breakdown[$feature] = $this->aiUsageService->getUsageBreakdownForPeriod($tenant, $feature, $year, $month);
                }
                $dt = \Carbon\Carbon::createFromDate($year, $month, 1);
                $currentMonth = $dt->format('Y-m');
                $monthStart = $dt->copy()->startOfMonth()->toDateString();
                $monthEnd = $dt->copy()->endOfMonth()->toDateString();
            } else {
                $usageStatus = $this->aiUsageService->getUsageStatus($tenant);
                $breakdown = [];
                foreach (['tagging', 'suggestions'] as $feature) {
                    $breakdown[$feature] = $this->aiUsageService->getUsageBreakdown($tenant, $feature);
                }
                $currentMonth = now()->format('Y-m');
                $monthStart = now()->startOfMonth()->toDateString();
                $monthEnd = now()->endOfMonth()->toDateString();
            }

            return response()->json([
                'status' => $usageStatus,
                'breakdown' => $breakdown,
                'current_month' => $currentMonth,
                'month_start' => $monthStart,
                'month_end' => $monthEnd,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching AI usage data', [
                'tenant_id' => app('tenant')?->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to load AI usage data. Please try again later.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Phase J.2.5: Get AI Tag Settings
     *
     * Admin-only endpoint to get current AI tagging policy settings.
     */
    public function getAiSettings(): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenant = app('tenant');

            if (! $tenant) {
                return response()->json(['error' => 'Tenant context not found'], 400);
            }

            if (! $user->hasPermissionForTenant($tenant, 'company_settings.manage_ai_settings')) {
                return response()->json(['error' => 'You do not have permission to view AI settings.'], 403);
            }

            return response()->json([
                'settings' => $this->buildAiSettingsPayload($tenant),
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching AI settings', [
                'tenant_id' => app('tenant')?->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to load AI settings. Please try again later.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Phase J.2.5: Update AI Tag Settings
     *
     * Admin-only endpoint to update AI tagging policy settings.
     */
    public function updateAiSettings(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenant = app('tenant');

            if (! $tenant) {
                return response()->json(['error' => 'Tenant context not found'], 400);
            }

            if (! $user->hasPermissionForTenant($tenant, 'company_settings.manage_ai_settings')) {
                return response()->json(['error' => 'You do not have permission to update AI settings.'], 403);
            }

            $validated = $request->validate([
                'disable_ai_tagging' => 'boolean',
                'enable_ai_tag_suggestions' => 'boolean',
                'enable_ai_tag_auto_apply' => 'boolean',
                'ai_auto_tag_limit_mode' => 'in:best_practices,custom',
                'ai_auto_tag_limit_value' => 'nullable|integer|min:1|max:10',
                'ai_best_practices_limit' => 'nullable|integer|min:1|max:10',
                'ai_insights_enabled' => 'boolean',
            ]);

            if (array_key_exists('ai_insights_enabled', $validated)) {
                $tenant->ai_insights_enabled = $validated['ai_insights_enabled'];
                $tenant->save();
            }

            $tagSettings = collect($validated)->except('ai_insights_enabled')->all();
            if (! empty($tagSettings)) {
                $this->aiTagPolicyService->updateTenantSettings($tenant, $tagSettings);
            }

            \Log::info('[CompanyController] AI settings updated', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'settings' => $validated,
            ]);

            return response()->json([
                'message' => 'AI settings updated successfully',
                'settings' => $this->buildAiSettingsPayload($tenant->fresh()),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error updating AI settings', [
                'tenant_id' => app('tenant')?->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to update AI settings. Please try again later.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Queue a metadata insights sync (bypasses the job’s 24h cooldown). Admin/owner only.
     * Repeat clicks are limited by {@see manualInsightsQueueCacheKey()} + config cooldown.
     */
    public function runMetadataInsightsNow(): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenant = app('tenant');

            if (! $tenant) {
                return response()->json(['error' => 'Tenant context not found'], 400);
            }

            if (! $user->hasPermissionForTenant($tenant, 'company_settings.manage_ai_settings')) {
                return response()->json(['error' => 'You do not have permission to run insights.'], 403);
            }

            if (! $tenant->ai_insights_enabled) {
                return response()->json(['error' => 'Enable Asset Field Intelligence before running a sync.'], 422);
            }

            $gate = $this->insightsManualRunGatePayload((int) $tenant->id);
            if ($gate['insights_manual_run_available_at'] !== null) {
                $next = Carbon::parse($gate['insights_manual_run_available_at']);
                $retryAfter = max(0, $next->getTimestamp() - time());

                return response()->json([
                    'error' => 'A library pattern scan was queued recently. Please wait before running again.',
                    'retry_after_seconds' => $retryAfter,
                    'next_available_at' => $gate['insights_manual_run_available_at'],
                    'settings' => $this->buildAiSettingsPayload($tenant),
                ], 429);
            }

            Cache::forever($this->manualInsightsQueueCacheKey((int) $tenant->id), now()->toIso8601String());

            RunMetadataInsightsJob::dispatch($tenant->id, true);

            return response()->json([
                'message' => 'Queued. New suggestions update when the job finishes.',
                'settings' => $this->buildAiSettingsPayload($tenant->fresh()),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error queueing metadata insights run', [
                'tenant_id' => app('tenant')?->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Could not queue insights sync. Please try again.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAiSettingsPayload(Tenant $tenant): array
    {
        $settings = $this->aiTagPolicyService->getTenantSettings($tenant);
        $tenant = $tenant->fresh() ?? $tenant;
        $settings['ai_insights_enabled'] = (bool) $tenant->ai_insights_enabled;

        $last = Cache::get("tenant:{$tenant->id}:metadata_insights:last_run_at");
        $settings['last_insights_run_at'] = is_string($last) ? $last : null;

        $settings['insights_pending_suggestions_count'] =
            (int) DB::table('ai_metadata_value_suggestions')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'pending')
                ->count()
            + (int) DB::table('ai_metadata_field_suggestions')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'pending')
                ->count();

        return array_merge($settings, $this->insightsManualRunGatePayload((int) $tenant->id));
    }

    protected function manualInsightsQueueCacheKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:metadata_insights:last_manual_queue_at";
    }

    /**
     * @return array{
     *     last_insights_manual_queued_at: ?string,
     *     insights_manual_run_available_at: ?string,
     *     manual_insights_run_cooldown_minutes: int
     * }
     */
    protected function insightsManualRunGatePayload(int $tenantId): array
    {
        $mins = max(1, (int) config('ai_metadata_field_suggestions.manual_insights_run_cooldown_minutes', 45));
        $last = Cache::get($this->manualInsightsQueueCacheKey($tenantId));
        $lastStr = is_string($last) ? $last : null;
        $availableAt = null;
        if ($lastStr !== null) {
            $next = Carbon::parse($lastStr)->addMinutes($mins);
            if ($next->isFuture()) {
                $availableAt = $next->toIso8601String();
            }
        }

        return [
            'last_insights_manual_queued_at' => $lastStr,
            'insights_manual_run_available_at' => $availableAt,
            'manual_insights_run_cooldown_minutes' => $mins,
        ];
    }

    /**
     * Phase J.2.6: Get Tag Quality Metrics Summary
     *
     * Admin-only endpoint for tag quality analytics.
     */
    public function getTagQualityMetrics(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenant = app('tenant');

            if (! $tenant) {
                return response()->json(['error' => 'Tenant context not found'], 400);
            }

            if (! $user->hasPermissionForTenant($tenant, 'company_settings.view_tag_quality')) {
                return response()->json(['error' => 'You do not have permission to view tag quality metrics.'], 403);
            }

            $timeRange = $request->input('time_range', now()->format('Y-m'));

            // Get all metrics
            $summary = $this->tagQualityMetricsService->getSummaryMetrics($tenant, $timeRange);
            $tagMetrics = $this->tagQualityMetricsService->getTagMetrics($tenant, $timeRange, 20);
            $confidenceMetrics = $this->tagQualityMetricsService->getConfidenceMetrics($tenant, $timeRange);
            $trustSignals = $this->tagQualityMetricsService->getTrustSignals($tenant, $timeRange);

            return response()->json([
                'summary' => $summary,
                'tags' => $tagMetrics,
                'confidence' => $confidenceMetrics,
                'trust_signals' => $trustSignals,
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching tag quality metrics', [
                'tenant_id' => app('tenant')?->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to load tag quality metrics. Please try again later.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Phase J.2.6: Export Tag Quality Metrics as CSV
     *
     * Admin-only endpoint for exporting metrics data.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportTagQualityMetrics(Request $request)
    {
        try {
            $user = Auth::user();
            $tenant = app('tenant');

            if (! $tenant) {
                return response()->json(['error' => 'Tenant context not found'], 400);
            }

            if (! $user->hasPermissionForTenant($tenant, 'company_settings.view_tag_quality')) {
                return response()->json(['error' => 'You do not have permission to export tag quality metrics.'], 403);
            }

            $timeRange = $request->input('time_range', now()->format('Y-m'));
            $tagMetrics = $this->tagQualityMetricsService->getTagMetrics($tenant, $timeRange, 1000);

            $filename = "tag-quality-metrics-{$tenant->slug}-{$timeRange}.csv";

            return response()->streamDownload(function () use ($tagMetrics) {
                $output = fopen('php://output', 'w');

                // CSV headers
                fputcsv($output, [
                    'Tag',
                    'Total Generated',
                    'Accepted',
                    'Dismissed',
                    'Acceptance Rate',
                    'Dismissal Rate',
                    'Avg Confidence',
                    'Avg Confidence (Accepted)',
                    'Avg Confidence (Dismissed)',
                    'Trust Signals',
                ]);

                // CSV data
                foreach ($tagMetrics['tags'] as $tag) {
                    fputcsv($output, [
                        $tag['tag'],
                        $tag['total_generated'],
                        $tag['accepted'],
                        $tag['dismissed'],
                        number_format($tag['acceptance_rate'] * 100, 1).'%',
                        number_format($tag['dismissal_rate'] * 100, 1).'%',
                        $tag['avg_confidence'] ? number_format($tag['avg_confidence'], 3) : '',
                        $tag['avg_confidence_accepted'] ? number_format($tag['avg_confidence_accepted'], 3) : '',
                        $tag['avg_confidence_dismissed'] ? number_format($tag['avg_confidence_dismissed'], 3) : '',
                        implode(', ', $tag['trust_signals'] ?? []),
                    ]);
                }

                fclose($output);
            }, $filename, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);

        } catch (\Exception $e) {
            \Log::error('Error exporting tag quality metrics', [
                'tenant_id' => app('tenant')?->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to export tag quality metrics. Please try again later.',
            ], 500);
        }
    }
}
