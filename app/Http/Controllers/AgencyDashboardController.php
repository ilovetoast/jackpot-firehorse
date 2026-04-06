<?php

namespace App\Http\Controllers;

use App\Enums\OwnershipTransferStatus;
use App\Models\AgencyPartnerReferral;
use App\Models\AgencyPartnerReward;
use App\Models\AgencyTier;
use App\Models\CollectionUser;
use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;
use App\Services\Agency\BrandReadinessService;
use App\Services\AgencyBrandAccessService;
use App\Services\IncubationWorkspaceService;
use App\Services\TenantAgencyService;
use App\Support\DashboardLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency Dashboard Controller
 *
 * Phase AG-7 — Agency Dashboard & Credits Visibility
 * Phase AG-8 — UX Polish & Transfer Nudges (Non-Blocking)
 * Phase AG-10 — Partner Marketing & Referral Attribution (Foundational)
 *
 * Provides visibility into agency partner program status.
 * Only accessible to tenants with is_agency = true.
 *
 * AG-8: Added computed flags for UI nudges (informational only, no enforcement).
 * AG-10: Added referral tracking (attribution only, no rewards).
 */
class AgencyDashboardController extends Controller
{
    public function __construct(
        protected TenantAgencyService $tenantAgencyService,
        protected IncubationWorkspaceService $incubationWorkspaceService,
        protected AgencyBrandAccessService $agencyBrandAccessService
    ) {}

    /**
     * Display the agency dashboard.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            abort(403, 'Tenant must be selected.');
        }

        // Only allow access to agency tenants
        if (! $tenant->is_agency) {
            abort(403, 'This page is only available to agency partners.');
        }

        $canCreateIncubatedClient = $this->userCanStartIncubation($user, $tenant);

        // Get agency tier info
        $agencyTier = $tenant->agencyTier;
        $tierName = $agencyTier?->name ?? 'None';
        $tierOrder = $agencyTier?->tier_order ?? 0;
        $activatedCount = $tenant->activated_client_count ?? 0;

        // Get next tier threshold
        $nextTier = null;
        $nextTierThreshold = null;
        $activationsToNextTier = null;
        if ($agencyTier) {
            $nextTier = AgencyTier::where('tier_order', '>', $agencyTier->tier_order)
                ->orderBy('tier_order', 'asc')
                ->first();

            if ($nextTier) {
                $nextTierThreshold = $nextTier->activation_threshold ?? null;
                // Phase AG-8: Compute activations needed for tier progress nudge
                if ($nextTierThreshold !== null) {
                    $activationsToNextTier = max(0, $nextTierThreshold - $activatedCount);
                }
            }
        }

        // Phase AG-8: Get incubation window from agency tier (informational only)
        $incubationWindowDays = $agencyTier?->incubation_window_days;
        $maxSupportExtensionDays = $agencyTier?->max_support_extension_days;
        if ($maxSupportExtensionDays === null && $agencyTier) {
            $maxSupportExtensionDays = match ($agencyTier->name) {
                'Silver' => 14,
                'Gold' => 30,
                'Platinum' => 180,
                default => 14,
            };
        }
        $incubationPlanOptions = $this->incubationPlanOptions();

        // Get reward ledger (all rewards for this agency)
        $rewards = AgencyPartnerReward::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($reward) {
                return [
                    'id' => $reward->id,
                    'client_name' => $reward->clientTenant->name ?? 'Unknown',
                    'reward_type' => $reward->reward_type,
                    'reward_value' => $reward->reward_value,
                    'created_at' => $reward->created_at->toISOString(),
                    'created_at_human' => $reward->created_at->diffForHumans(),
                ];
            });

        // Incubated = still linked to this agency and no completed ownership transfer yet.
        // (incubated_at null = legacy rows; set = explicit window start — expiry is advisory only, not enforced.)
        $incubatedRows = Tenant::query()
            ->where('incubated_by_agency_id', $tenant->id)
            ->where(function ($query) {
                $query->whereNull('incubated_at')
                    ->orWhere(function ($q) {
                        $q->whereNotNull('incubated_at')
                            ->whereDoesntHave('ownershipTransfers', function ($q2) {
                                $q2->where('status', OwnershipTransferStatus::COMPLETED);
                            });
                    });
            })
            ->get([
                'id',
                'name',
                'slug',
                'incubated_at',
                'incubation_expires_at',
                'incubation_target_plan_key',
                'incubation_extension_requested_at',
            ]);

        $tenantAgencyIdByClientId = TenantAgency::query()
            ->where('agency_tenant_id', $tenant->id)
            ->whereIn('tenant_id', $incubatedRows->pluck('id'))
            ->pluck('id', 'tenant_id');

        $incubatedClients = $incubatedRows->map(function ($client) use ($tenantAgencyIdByClientId) {
            // Phase AG-8: Compute days remaining for incubation window awareness
            $daysRemaining = null;
            $expiringsSoon = false;
            if ($client->incubation_expires_at) {
                $daysRemaining = (int) now()->diffInDays($client->incubation_expires_at, false);
                // Negative means already expired, but we don't enforce - just show warning
                $expiringsSoon = $daysRemaining >= 0 && $daysRemaining < 7;
            }

            $locked = $this->incubationWorkspaceService->isWorkspaceLocked($client);

            return [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'incubated_at' => $client->incubated_at?->toISOString(),
                'incubation_expires_at' => $client->incubation_expires_at?->toISOString(),
                'incubation_target_plan_key' => $client->incubation_target_plan_key,
                'incubation_extension_requested_at' => $client->incubation_extension_requested_at?->toISOString(),
                // Agency ↔ client link id for sync (adds agency staff to client workspace)
                'tenant_agency_id' => $tenantAgencyIdByClientId[$client->id] ?? null,
                // Phase AG-8: Computed flags for UI nudges (no enforcement)
                'days_remaining' => $daysRemaining,
                'expiring_soon' => $expiringsSoon,
                'incubation_locked' => $locked,
            ];
        });

        // Get activated clients (clients with completed transfers)
        $activatedClients = AgencyPartnerReward::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($reward) {
                return [
                    'id' => $reward->clientTenant->id,
                    'name' => $reward->clientTenant->name,
                    'slug' => $reward->clientTenant->slug,
                    'activated_at' => $reward->created_at->toISOString(),
                    'activated_at_human' => $reward->created_at->diffForHumans(),
                ];
            });

        // Get pending transfers (waiting for billing)
        $pendingTransfers = OwnershipTransfer::whereHas('tenant', function ($query) use ($tenant) {
            $query->where('incubated_by_agency_id', $tenant->id);
        })
            ->where('status', OwnershipTransferStatus::PENDING_BILLING)
            ->with(['tenant'])
            ->orderBy('accepted_at', 'desc')
            ->get()
            ->map(function ($transfer) {
                return [
                    'id' => $transfer->id,
                    'client_name' => $transfer->tenant->name,
                    'client_slug' => $transfer->tenant->slug,
                    'accepted_at' => $transfer->accepted_at?->toISOString(),
                    'accepted_at_human' => $transfer->accepted_at?->diffForHumans(),
                ];
            });

        // Phase AG-10: Get referrals (separate from incubation)
        $referrals = AgencyPartnerReferral::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($referral) {
                return [
                    'id' => $referral->id,
                    'client_name' => $referral->clientTenant->name ?? 'Unknown',
                    'client_slug' => $referral->clientTenant->slug ?? null,
                    'source' => $referral->source,
                    'activated_at' => $referral->activated_at?->toISOString(),
                    'activated_at_human' => $referral->activated_at?->diffForHumans(),
                    'is_activated' => $referral->isActivated(),
                    'created_at' => $referral->created_at->toISOString(),
                    'created_at_human' => $referral->created_at->diffForHumans(),
                ];
            });

        // Separate activated and pending referrals
        $activatedReferrals = $referrals->where('is_activated', true)->values();
        $pendingReferrals = $referrals->where('is_activated', false)->values();

        $managedClients = $this->agencyBrandAccessService->managedAgencyClientsForUser($user, $tenant);

        $brandIds = collect($managedClients)
            ->pluck('brands')
            ->flatten(1)
            ->pluck('id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        $readinessById = app(BrandReadinessService::class)->forBrandIds($brandIds);

        $readinessSummary = [
            'brand_count' => count($brandIds),
            'brands_missing_references' => 0,
            'brands_missing_typography' => 0,
            'brands_missing_assets' => 0,
        ];
        foreach ($readinessById as $data) {
            $c = $data['criteria'] ?? [];
            if (! ($c['has_sufficient_references'] ?? false)) {
                $readinessSummary['brands_missing_references']++;
            }
            if (! ($c['has_typography'] ?? false)) {
                $readinessSummary['brands_missing_typography']++;
            }
            if (! ($c['has_sufficient_assets'] ?? false)) {
                $readinessSummary['brands_missing_assets']++;
            }
        }

        foreach ($managedClients as $i => $client) {
            foreach ($client['brands'] as $j => $b) {
                $bid = $b['id'];
                $r = $readinessById[$bid] ?? [
                    'readiness_score' => 0,
                    'readiness_tasks' => [],
                    'readiness_tooltip' => 'Readiness data unavailable.',
                    'reference_alert' => null,
                    'criteria' => [
                        'has_identity_basics' => false,
                        'has_typography' => false,
                        'has_sufficient_assets' => false,
                        'has_sufficient_references' => false,
                        'has_photography_guidelines' => false,
                    ],
                    'counts' => ['assets' => 0, 'tier23_references' => 0],
                ];
                $managedClients[$i]['brands'][$j]['readiness'] = $r;
                $managedClients[$i]['brands'][$j]['actions'] = [
                    'guidelines_builder_path' => '/app/brands/'.$bid.'/brand-guidelines/builder',
                    'assets_path' => '/app/assets',
                    'reference_materials_path' => '/app/assets?source=reference_materials',
                ];
            }
        }

        $readinessBrands = [];
        foreach ($managedClients as $client) {
            foreach ($client['brands'] as $b) {
                $readinessBrands[] = [
                    'tenant_id' => $client['id'],
                    'tenant_name' => $client['name'],
                    'tenant_slug' => $client['slug'],
                    'brand' => $b,
                ];
            }
        }
        usort($readinessBrands, function ($a, $b) {
            $sa = $a['brand']['readiness']['readiness_score'] ?? 0;
            $sb = $b['brand']['readiness']['readiness_score'] ?? 0;

            return $sa <=> $sb;
        });

        $tenant->loadMissing('defaultBrand');
        $defaultBrand = $tenant->defaultBrand;
        $brandForLinks = app()->bound('brand') ? app('brand') : null;
        if (! $brandForLinks && $defaultBrand) {
            $brandForLinks = $defaultBrand;
        }

        $dashLabels = DashboardLinks::workspaceDashboardShortLabels($tenant->name, $brandForLinks?->name);
        $dashboardLinks = [
            'company' => DashboardLinks::companySettingsHref($user, $tenant),
            'company_label' => $dashLabels['company'],
            'brand' => DashboardLinks::brandEditHref($user, $tenant, $brandForLinks),
            'brand_label' => $dashLabels['brand'],
        ];

        $managedAgency = [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'default_brand' => $defaultBrand ? [
                'id' => $defaultBrand->id,
                'name' => $defaultBrand->name,
            ] : null,
        ];

        $agencyTeamPayload = $this->buildAgencyWorkspaceTeamPayload($tenant);
        $agencyBrandsSummary = $this->buildAgencyWorkspaceBrandsSummary($tenant);

        return Inertia::render('Agency/Dashboard', [
            'tenant' => $tenant,
            'agency' => [
                'tier' => [
                    'name' => $tierName,
                    'order' => $tierOrder,
                    'reward_percentage' => $agencyTier?->reward_percentage,
                    // Phase AG-8: Incubation window for informational display
                    'incubation_window_days' => $incubationWindowDays,
                    'max_support_extension_days' => $maxSupportExtensionDays,
                ],
                'incubation_plan_options' => $incubationPlanOptions,
                'activated_client_count' => $activatedCount,
                'next_tier' => $nextTier ? [
                    'name' => $nextTier->name,
                    'threshold' => $nextTierThreshold,
                    'progress_percentage' => $nextTierThreshold
                        ? min(($activatedCount / $nextTierThreshold) * 100, 100)
                        : 100,
                    // Phase AG-8: Computed activations needed for tier progress nudge
                    'activations_to_next_tier' => $activationsToNextTier,
                ] : null,
            ],
            'rewards' => $rewards,
            'clients' => [
                'incubated' => $incubatedClients,
                'activated' => $activatedClients,
                'pending_transfers' => $pendingTransfers,
            ],
            // Phase AG-10: Referral tracking (attribution only, no rewards)
            'referrals' => [
                'total' => $referrals->count(),
                'activated' => $activatedReferrals,
                'pending' => $pendingReferrals,
            ],
            'managed_clients' => $managedClients,
            'brands_readiness' => $readinessBrands,
            'readiness_summary' => $readinessSummary,
            'managed_agency' => $managedAgency,
            'dashboard_links' => $dashboardLinks,
            'can_create_incubated_client' => $canCreateIncubatedClient,
            'agency_team' => $agencyTeamPayload['core'],
            'agency_team_external' => $agencyTeamPayload['external'],
            'agency_brands_summary' => $agencyBrandsSummary,
        ]);
    }

    /**
     * Agency workspace members + per-brand roles. Splits collection-only guests (no brand membership) into `external`.
     *
     * @return array{core: list<array<string, mixed>>, external: list<array<string, mixed>>}
     */
    protected function buildAgencyWorkspaceTeamPayload(Tenant $agencyTenant): array
    {
        $tenantBrandIds = $agencyTenant->brands()->pluck('id')->all();
        if ($tenantBrandIds === []) {
            return ['core' => [], 'external' => []];
        }

        $firstUserId = $agencyTenant->users()->orderBy('tenant_user.created_at')->first()?->id;

        $members = $agencyTenant->users()
            ->with([
                'brands' => fn ($q) => $q->whereIn('brands.id', $tenantBrandIds)->wherePivotNull('removed_at'),
            ])
            ->orderByRaw('CASE WHEN COALESCE(tenant_user.is_agency_managed, 0) = 1 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN tenant_user.agency_tenant_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('tenant_user.agency_tenant_id')
            ->orderBy('users.created_at')
            ->limit(200)
            ->get();

        $agencyIds = $members->pluck('pivot.agency_tenant_id')->filter()->unique()->values();
        $agencyNames = Tenant::whereIn('id', $agencyIds)->pluck('name', 'id');

        $externalUserIds = $members
            ->filter(fn (User $m) => $m->isExternalCollectionAccessOnlyForTenant($agencyTenant))
            ->pluck('id')
            ->all();

        $collectionsByUserId = collect();
        if ($externalUserIds !== []) {
            $grantRows = CollectionUser::query()
                ->whereIn('user_id', $externalUserIds)
                ->whereNotNull('accepted_at')
                ->whereHas('collection', fn ($q) => $q->where('tenant_id', $agencyTenant->id))
                ->with('collection:id,name')
                ->get();

            $collectionsByUserId = $grantRows
                ->groupBy('user_id')
                ->map(function ($rows) {
                    return $rows
                        ->pluck('collection')
                        ->filter()
                        ->unique('id')
                        ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
                        ->values()
                        ->all();
                });
        }

        $mapRow = function (User $member) use ($firstUserId, $agencyNames) {
            $role = $member->pivot->role ?? null;
            if (empty($role)) {
                $role = ($firstUserId && (int) $firstUserId === (int) $member->id) ? 'owner' : 'member';
            }

            $brandRoles = collect($member->brands)->map(function ($brand) {
                return [
                    'brand_id' => $brand->id,
                    'brand_name' => $brand->name,
                    'role' => $brand->pivot->role ?? 'viewer',
                ];
            })->values()->all();

            $agencyTenantId = $member->pivot->agency_tenant_id ? (int) $member->pivot->agency_tenant_id : null;

            return [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'company_role' => strtolower((string) $role),
                'brand_roles' => $brandRoles,
                'joined_at' => $member->pivot->created_at?->toIso8601String(),
                'is_agency_managed' => (bool) ($member->pivot->is_agency_managed ?? false),
                'agency_tenant_id' => $agencyTenantId,
                'agency_tenant_name' => $agencyTenantId ? ($agencyNames[$agencyTenantId] ?? null) : null,
            ];
        };

        $core = [];
        $external = [];
        foreach ($members as $member) {
            $row = $mapRow($member);
            if ($member->isExternalCollectionAccessOnlyForTenant($agencyTenant)) {
                $row['collections'] = $collectionsByUserId->get($member->id, []);
                $external[] = $row;
            } else {
                $core[] = $row;
            }
        }

        return ['core' => $core, 'external' => $external];
    }

    /**
     * Brands in the agency tenant with count of users who have active brand access.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildAgencyWorkspaceBrandsSummary(Tenant $agencyTenant): array
    {
        return $agencyTenant->brands()
            ->withCount([
                'users' => fn ($q) => $q->whereNull('brand_user.removed_at'),
            ])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'is_default' => (bool) $b->is_default,
                'members_with_access' => (int) $b->users_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Create a new client company incubated by the current agency tenant and link the agency for access.
     */
    public function storeIncubatedClient(Request $request): RedirectResponse
    {
        $agencyTenant = app('tenant');
        $user = Auth::user();

        if (! $agencyTenant || ! $agencyTenant->is_agency) {
            abort(403, 'This action is only available to agency partners.');
        }

        if (! $user || ! $this->userCanStartIncubation($user, $agencyTenant)) {
            abort(403, 'You do not have permission to start an incubated client company.');
        }

        $planKeys = array_keys(config('plans', []));
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'incubation_target_plan_key' => ['required', 'string', Rule::in($planKeys)],
        ]);

        $baseSlug = Str::slug($validated['company_name']);
        $slug = $baseSlug;
        $counter = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $agencyTier = $agencyTenant->agencyTier;
        $windowDays = $this->resolveIncubationWindowDays($agencyTier);
        $incubationExpiresAt = $windowDays !== null
            ? now()->addDays($windowDays)
            : null;

        $clientTenant = DB::transaction(function () use ($validated, $slug, $agencyTenant, $user, $incubationExpiresAt) {
            $clientTenant = Tenant::create([
                'name' => $validated['company_name'],
                'slug' => $slug,
                'incubated_at' => now(),
                'incubation_expires_at' => $incubationExpiresAt,
                'incubated_by_agency_id' => $agencyTenant->id,
                'incubation_target_plan_key' => $validated['incubation_target_plan_key'],
            ]);

            $defaultBrand = $clientTenant->defaultBrand;
            if (! $defaultBrand) {
                throw new \RuntimeException('Default brand was not created for the new company.');
            }

            $this->tenantAgencyService->attach(
                $clientTenant,
                $agencyTenant,
                'agency_admin',
                [['brand_id' => $defaultBrand->id, 'role' => 'admin']],
                $user
            );

            $user->setRoleForTenant($clientTenant, 'owner', true);

            return $clientTenant;
        });

        return redirect()
            ->route('agency.dashboard')
            ->with('success', "Company “{$clientTenant->name}” is now incubated. Switch to it from your managed clients when you are ready.");
    }

    /**
     * Update the target (pre-transfer) plan for an incubated client workspace.
     */
    public function updateIncubatedClientTargetPlan(Request $request, Tenant $incubatedClient): RedirectResponse
    {
        $agencyTenant = app('tenant');
        $user = Auth::user();

        if (! $agencyTenant || ! $agencyTenant->is_agency) {
            abort(403);
        }

        if (! $user || ! $this->userCanStartIncubation($user, $agencyTenant)) {
            abort(403);
        }

        $this->assertAgencyOwnsIncubatedClient($agencyTenant, $incubatedClient);

        $planKeys = array_keys(config('plans', []));
        $validated = $request->validate([
            'incubation_target_plan_key' => ['required', 'string', Rule::in($planKeys)],
        ]);

        $incubatedClient->update([
            'incubation_target_plan_key' => $validated['incubation_target_plan_key'],
        ]);

        return redirect()
            ->to(route('agency.dashboard').'?tab=progress')
            ->with('success', 'Target plan updated for '.$incubatedClient->name.'.');
    }

    /**
     * Agency requests a deadline extension (ops/support follows up; max per grant is tier-capped on admin side).
     */
    public function requestIncubationExtension(Request $request, Tenant $incubatedClient): RedirectResponse
    {
        $agencyTenant = app('tenant');
        $user = Auth::user();

        if (! $agencyTenant || ! $agencyTenant->is_agency) {
            abort(403);
        }

        if (! $user || ! $this->userCanStartIncubation($user, $agencyTenant)) {
            abort(403);
        }

        $this->assertAgencyOwnsIncubatedClient($agencyTenant, $incubatedClient);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $incubatedClient->update([
            'incubation_extension_requested_at' => now(),
            'incubation_extension_request_note' => $validated['note'] ?? null,
        ]);

        return redirect()
            ->to(route('agency.dashboard').'?tab=progress')
            ->with('success', 'Extension request submitted for '.$incubatedClient->name.'. Support will follow up.');
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    protected function incubationPlanOptions(): array
    {
        $out = [];
        foreach (config('plans', []) as $key => $cfg) {
            if (! is_array($cfg)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $cfg['name'] ?? ucfirst($key),
            ];
        }

        return $out;
    }

    protected function resolveIncubationWindowDays(?AgencyTier $tier): ?int
    {
        if ($tier === null) {
            return null;
        }
        if ($tier->incubation_window_days !== null && $tier->incubation_window_days > 0) {
            return (int) $tier->incubation_window_days;
        }

        return match ($tier->name) {
            'Silver' => 30,
            'Gold' => 60,
            'Platinum' => 180,
            default => 30,
        };
    }

    protected function assertAgencyOwnsIncubatedClient(Tenant $agencyTenant, Tenant $incubatedClient): void
    {
        if ((int) $incubatedClient->incubated_by_agency_id !== (int) $agencyTenant->id) {
            abort(404);
        }
        if ($incubatedClient->hasCompletedOwnershipTransfer()) {
            abort(422, 'Ownership transfer is already complete.');
        }
    }

    protected function userCanStartIncubation(?User $user, Tenant $tenant): bool
    {
        if (! $user || ! $tenant->is_agency) {
            return false;
        }

        $role = $user->getRoleForTenant($tenant);
        if (in_array($role, ['owner', 'admin'], true)) {
            return true;
        }

        return $user->hasPermissionForTenant($tenant, 'company_settings.edit');
    }
}
