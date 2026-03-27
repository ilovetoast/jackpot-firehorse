<?php

namespace App\Http\Controllers;

use App\Enums\OwnershipTransferStatus;
use App\Models\AgencyPartnerReferral;
use App\Models\AgencyPartnerReward;
use App\Models\AgencyTier;
use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Services\Agency\BrandReadinessService;
use App\Support\DashboardLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency Dashboard Controller
 *
 * Phase AG-7 — Agency Dashboard & Credits Visibility
 * Phase AG-8 — UX Polish & Transfer Nudges (Non-Blocking)
 * Phase AG-10 — Partner Marketing & Referral Attribution (Foundational)
 *
 * Provides read-only visibility into agency partner program status.
 * Only accessible to tenants with is_agency = true.
 *
 * AG-8: Added computed flags for UI nudges (informational only, no enforcement).
 * AG-10: Added referral tracking (attribution only, no rewards).
 */
class AgencyDashboardController extends Controller
{
    /**
     * Display the agency dashboard.
     *
     * READ-ONLY: No mutation actions allowed.
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
        $incubatedClients = Tenant::query()
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
            ->get(['id', 'name', 'slug', 'incubated_at', 'incubation_expires_at'])
            ->map(function ($client) {
                // Phase AG-8: Compute days remaining for incubation window awareness
                $daysRemaining = null;
                $expiringsSoon = false;
                if ($client->incubation_expires_at) {
                    $daysRemaining = (int) now()->diffInDays($client->incubation_expires_at, false);
                    // Negative means already expired, but we don't enforce - just show warning
                    $expiringsSoon = $daysRemaining >= 0 && $daysRemaining < 7;
                }

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'slug' => $client->slug,
                    'incubated_at' => $client->incubated_at?->toISOString(),
                    'incubation_expires_at' => $client->incubation_expires_at?->toISOString(),
                    // Phase AG-8: Computed flags for UI nudges (no enforcement)
                    'days_remaining' => $daysRemaining,
                    'expiring_soon' => $expiringsSoon,
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

        $companyController = app(CompanyController::class);
        $managedClients = $companyController->managedAgencyClientsForUser($user, $tenant);

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

        $settingsLabels = DashboardLinks::workspaceSettingsLabels($tenant->name, $brandForLinks?->name);
        $dashboardLinks = [
            'company' => DashboardLinks::companyOverviewHref($user, $tenant),
            'company_label' => $settingsLabels['company'],
            'brand' => DashboardLinks::brandOverviewHref($user, $tenant, $brandForLinks),
            'brand_label' => $settingsLabels['brand'],
        ];

        $managedAgency = [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'default_brand' => $defaultBrand ? [
                'id' => $defaultBrand->id,
                'name' => $defaultBrand->name,
            ] : null,
        ];

        return Inertia::render('Agency/Dashboard', [
            'tenant' => $tenant,
            'agency' => [
                'tier' => [
                    'name' => $tierName,
                    'order' => $tierOrder,
                    'reward_percentage' => $agencyTier?->reward_percentage,
                    // Phase AG-8: Incubation window for informational display
                    'incubation_window_days' => $incubationWindowDays,
                ],
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
        ]);
    }
}
