<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\TenantModule;
use App\Services\AiUsageService;
use App\Services\CompanyCostService;
use App\Services\CompanyDataService;
use App\Services\FeatureGate;
use App\Services\IncubationWorkspaceService;
use App\Services\PlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company View Controller
 *
 * Displays detailed view of a company including:
 * - Basic company information
 * - Income vs expenses chart
 * - Activity, users, brands overview
 * - Profitability rating
 *
 * Protected by 'company.manage' permission.
 */
class CompanyViewController extends Controller
{
    /**
     * Display the company view page.
     */
    public function show(Tenant $tenant): Response
    {
        $user = Auth::user();

        // Only user ID 1 (Site Owner) can access admin company view
        // This matches the pattern used in other admin controllers for consistency
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can view company details.');
        }

        $planService = app(PlanService::class);
        $incubationWorkspaceService = app(IncubationWorkspaceService::class);
        $costService = app(CompanyCostService::class);
        $aiUsageService = app(AiUsageService::class);

        // Get basic company information
        $owner = $tenant->owner();
        $planName = $planService->getCurrentPlan($tenant);
        $planConfig = config("plans.{$planName}", config('plans.free'));

        // Get subscription info
        $subscription = $tenant->subscription('default');
        $stripeConnected = ! empty($tenant->stripe_id);

        // Calculate costs and income for last 6 months for chart
        $monthlyData = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $month = $date->month;
            $year = $date->year;

            $costs = $costService->calculateMonthlyCosts($tenant, $month, $year);
            $income = $costService->calculateIncome($tenant, $month, $year);

            $monthlyData[] = [
                'month' => $date->format('M Y'),
                'month_num' => $month,
                'year' => $year,
                'income' => $income['total_income'] ?? 0,
                'storage_cost' => $costs['storage']['monthly_cost'] ?? 0,
                'ai_cost' => $costs['ai_agents']['total_cost'] ?? 0,
                'total_cost' => ($costs['storage']['monthly_cost'] ?? 0) + ($costs['ai_agents']['total_cost'] ?? 0),
            ];
        }

        // Get current month costs and income
        $currentCosts = $costService->calculateMonthlyCosts($tenant);
        $currentIncome = $costService->calculateIncome($tenant);
        $profitability = $costService->calculateProfitabilityRating($tenant);

        // Get recent activity (last 10 events)
        // TODO: Add activityEvents relationship to Tenant model if needed
        // For now, query ActivityEvent directly by tenant_id
        $recentActivity = ActivityEvent::where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($event) {
                return [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'description' => $event->description,
                    'created_at' => $event->created_at?->toIso8601String(),
                    'metadata' => $event->metadata ?? [],
                ];
            });

        // Get users overview (first 5) - using standardized service method
        $companyDataService = app(CompanyDataService::class);
        $users = $companyDataService->getCompanyUsers($tenant, 5)->values()->toArray();

        // Ensure owner is connected to default brand (data integrity enforcement)
        $companyDataService->ensureOwnerConnectedToDefaultBrand($tenant);

        // Get all brands for the add user form (not just first 5)
        $brandsQuery = $tenant->brands()
            ->orderBy('is_default', 'desc')
            ->orderBy('name');

        $brandsCollection = $brandsQuery->get();

        Log::info('CompanyViewController - Loading brands', [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'brands_count' => $brandsCollection->count(),
            'brand_ids' => $brandsCollection->pluck('id')->toArray(),
        ]);

        $allBrands = $brandsCollection->map(function ($brand) {
            return [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'is_default' => $brand->is_default,
            ];
        })
            ->values() // Reset keys for proper array serialization
            ->toArray(); // Convert to array for Inertia

        // Get brands overview (first 5) for display
        // $allBrands is already an array, so use array_slice
        $brands = array_slice($allBrands, 0, 5);

        Log::info('CompanyViewController - Brands processed', [
            'tenant_id' => $tenant->id,
            'all_brands_count' => count($allBrands),
            'display_brands_count' => count($brands),
            'all_brands' => $allBrands,
            'display_brands' => $brands,
        ]);

        // Get asset count and total storage
        // Use direct query since Tenant model doesn't have assets() relationship
        $assetCount = Asset::where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->count();

        $totalStorageBytes = Asset::where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->sum('size_bytes') ?? 0;

        $totalStorageGB = round($totalStorageBytes / (1024 * 1024 * 1024), 2);

        // Get plan management info
        $planManagementSource = $planService->getPlanManagementSource($tenant);
        $isExternallyManaged = $planService->isExternallyManaged($tenant);

        // Get plan change history (last plan update activity)
        // EventType is a class with constants, not an enum, so use the constant directly
        $lastPlanUpdate = ActivityEvent::where('tenant_id', $tenant->id)
            ->where('event_type', EventType::PLAN_UPDATED)
            ->orderBy('created_at', 'desc')
            ->first();

        $planChangeInfo = null;
        if ($lastPlanUpdate) {
            $metadata = $lastPlanUpdate->metadata ?? [];
            $actor = $lastPlanUpdate->getActorModel(); // Use safe method to get actor
            $planChangeInfo = [
                'changed_at' => $lastPlanUpdate->created_at?->toIso8601String(),
                'changed_by' => $actor ? [
                    'id' => $actor->id ?? null,
                    'name' => trim(($actor->first_name ?? '').' '.($actor->last_name ?? '')) ?: $actor->email ?? $metadata['admin_name'] ?? 'System',
                    'email' => $actor->email ?? $metadata['admin_email'] ?? null,
                ] : [
                    'name' => $metadata['admin_name'] ?? ($lastPlanUpdate->actor_type === 'system' ? 'System' : 'System Administrator'),
                    'email' => $metadata['admin_email'] ?? null,
                ],
                'old_plan' => $metadata['previous_plan'] ?? $metadata['old_plan'] ?? null,
                'new_plan' => $metadata['new_plan'] ?? $planName,
                'billing_status' => $metadata['new_billing_status'] ?? $tenant->billing_status,
                'expiration_date' => $metadata['new_expiration'] ?? ($tenant->billing_status_expires_at?->toIso8601String()),
            ];
        }

        // Get AI usage and billing estimates (unified credit system)
        $aiUsageData = null;
        try {
            $usageStatus = $aiUsageService->getUsageStatus($tenant);

            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();

            $spendDetail = $this->buildAdminTenantAiSpendDetail($tenant->id, $monthStart, $monthEnd);

            $totalCost = $spendDetail['combined_total_usd'];
            $totalRuns = $spendDetail['agent_run_count'];

            $creditsUsed = $usageStatus['credits_used'];
            $creditsCap = $usageStatus['credits_cap'];

            $daysInMonth = $monthEnd->day;
            $daysElapsed = now()->day;
            $dailyAverageCredits = $daysElapsed > 0 ? $creditsUsed / $daysElapsed : 0;
            $projectedMonthlyCredits = $dailyAverageCredits * $daysInMonth;

            $dailyAverageCost = $daysElapsed > 0 ? $totalCost / $daysElapsed : 0;
            $projectedMonthlyCost = $dailyAverageCost * $daysInMonth;

            $usagePercentage = $creditsCap > 0 ? min(100, ($projectedMonthlyCredits / $creditsCap) * 100) : 0;

            $featureCalls = [];
            foreach ($usageStatus['per_feature'] ?? [] as $feature => $row) {
                if (is_array($row) && array_key_exists('calls', $row)) {
                    $featureCalls[$feature] = (int) $row['calls'];
                }
            }

            $aiUsageData = [
                'current_usage' => [
                    'credits_used' => $creditsUsed,
                    'credits_cap' => $creditsCap,
                    'per_feature' => $usageStatus['per_feature'],
                    'cost_to_date' => round($totalCost, 4),
                    'total_runs' => $totalRuns,
                    'total_calls' => $totalRuns,
                    'features' => $featureCalls,
                    'agent_runs_cost_usd' => round($spendDetail['agent_runs_total_usd'], 4),
                    'metered_usage_cost_usd' => round($spendDetail['metered_total_usd'], 4),
                ],
                'projections' => [
                    'monthly_credits' => round($projectedMonthlyCredits),
                    'monthly_cost' => round($projectedMonthlyCost, 2),
                    'usage_percentage' => round($usagePercentage, 1),
                    'monthly_usage' => round($projectedMonthlyCredits),
                ],
                'usage_status' => $usageStatus,
                'cost_detail' => $spendDetail,
                'status' => 'success',
            ];
        } catch (\Exception $e) {
            Log::warning('[CompanyViewController] Failed to load AI usage data', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            $aiUsageData = [
                'status' => 'error',
                'message' => 'Unable to load AI usage data',
            ];
        }

        // Phase AG-11: Load agency information
        $agencyInfo = null;
        if ($tenant->is_agency) {
            $agencyTier = $tenant->agencyTier;
            $agencyInfo = [
                'is_agency' => true,
                'tier' => $agencyTier?->name ?? 'None',
                'tier_id' => $tenant->agency_tier_id,
                'activated_client_count' => $tenant->activated_client_count ?? 0,
                'is_approved' => $tenant->agency_approved_at !== null,
                'approved_at' => $tenant->agency_approved_at?->toIso8601String(),
            ];
        }

        // Phase AG-11: Load incubation/referral information
        $incubationInfo = null;
        if ($tenant->incubated_by_agency_id) {
            $incubatingAgency = Tenant::find($tenant->incubated_by_agency_id);
            $agencyTier = $incubatingAgency?->agencyTier;
            $maxExtend = $agencyTier?->max_support_extension_days;
            if ($maxExtend === null && $agencyTier) {
                $maxExtend = match ($agencyTier->name) {
                    'Silver' => 14,
                    'Gold' => 30,
                    'Platinum' => 180,
                    default => 14,
                };
            }
            $incubationInfo = [
                'incubated_by' => $incubatingAgency ? [
                    'id' => $incubatingAgency->id,
                    'name' => $incubatingAgency->name,
                ] : null,
                'incubated_at' => $tenant->incubated_at?->toIso8601String(),
                'incubation_expires_at' => $tenant->incubation_expires_at?->toIso8601String(),
                'incubation_target_plan_key' => $tenant->incubation_target_plan_key,
                'incubation_locked' => $incubationWorkspaceService->isWorkspaceLocked($tenant),
                'max_support_extension_days' => $maxExtend,
            ];
        }

        $referralInfo = null;
        if ($tenant->referred_by_agency_id) {
            $referringAgency = Tenant::find($tenant->referred_by_agency_id);
            $referralInfo = [
                'referred_by' => $referringAgency ? [
                    'id' => $referringAgency->id,
                    'name' => $referringAgency->name,
                ] : null,
                'referral_source' => $tenant->referral_source,
            ];
        }

        $linkedAgencies = $this->buildLinkedAgenciesPayload($tenant);

        $creatorModuleRow = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->first();

        return Inertia::render('Admin/CompanyView', [
            'linked_agencies' => $linkedAgencies,
            'company' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'plan' => ucfirst($planName),
                'plan_name' => $planName,
                'stripe_connected' => $stripeConnected,
                'subscription_status' => $subscription ? $subscription->stripe_status : null,
                'billing_status' => $tenant->billing_status,
                'billing_status_expires_at' => $tenant->billing_status_expires_at?->toIso8601String(),
                'plan_management' => [
                    'source' => $planManagementSource,
                    'is_externally_managed' => $isExternallyManaged,
                    'manual_plan_override' => $tenant->manual_plan_override,
                ],
                'infrastructure_tier' => $tenant->infrastructure_tier ?? 'shared',
                'can_manage_plan' => ! $stripeConnected, // Allow non-Stripe plans to be managed
                'plan_change_info' => $planChangeInfo,
                'creator_module' => [
                    'has_row' => $creatorModuleRow !== null,
                    'enabled' => app(FeatureGate::class)->creatorModuleEnabled($tenant),
                    'status' => $creatorModuleRow?->status,
                    'expires_at' => $creatorModuleRow?->expires_at?->toIso8601String(),
                    'granted_by_admin' => (bool) ($creatorModuleRow?->granted_by_admin),
                    'seats_limit' => $creatorModuleRow?->seats_limit,
                ],
                'owner' => $owner ? [
                    'id' => $owner->id,
                    'name' => trim(($owner->first_name ?? '').' '.($owner->last_name ?? '')),
                    'email' => $owner->email,
                ] : null,
                // Phase AG-11: Agency information
                'agency' => $agencyInfo,
                'incubation' => $incubationInfo,
                'referral' => $referralInfo,
            ],
            'monthlyData' => $monthlyData,
            'currentCosts' => $currentCosts,
            'currentIncome' => $currentIncome,
            'profitability' => $profitability,
            'recentActivity' => $recentActivity,
            'users' => $users,
            'brands' => $brands,
            'all_brands' => $allBrands, // All brands for add user form
            'stats' => [
                'total_users' => $tenant->users()->count(),
                'total_brands' => $tenant->brands()->count(),
                'total_assets' => $assetCount,
                'total_storage_gb' => $totalStorageGB,
            ],
            'aiUsage' => $aiUsageData,
        ]);
    }

    /**
     * Admin-only rollup of tenant AI spend for the calendar month: agent runs (estimated API cost)
     * plus optional metered rows from `ai_usage` when cost_usd is populated.
     *
     * @return array<string, mixed>
     */
    protected function buildAdminTenantAiSpendDetail(int $tenantId, Carbon $monthStart, Carbon $monthEnd): array
    {
        $driver = DB::connection()->getDriverName();
        $daySelect = match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', started_at) as day",
            'pgsql' => "(started_at::date)::text as day",
            default => 'DATE(started_at) as day',
        };
        $dayGroup = match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', started_at)",
            'pgsql' => '(started_at::date)',
            default => 'DATE(started_at)',
        };

        $agentRunsTotal = (float) (AIAgentRun::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$monthStart, $monthEnd])
            ->sum('estimated_cost') ?? 0);

        $agentRunCount = (int) AIAgentRun::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$monthStart, $monthEnd])
            ->count();

        $meteredTotal = 0.0;
        $meteredByFeature = [];
        if (DB::getSchemaBuilder()->hasColumn('ai_usage', 'cost_usd')) {
            $meteredTotal = (float) (DB::table('ai_usage')
                ->where('tenant_id', $tenantId)
                ->whereBetween('usage_date', [$monthStart->toDateString(), now()->toDateString()])
                ->sum(DB::raw('COALESCE(cost_usd, 0)')) ?? 0);

            $meteredByFeature = DB::table('ai_usage')
                ->where('tenant_id', $tenantId)
                ->whereBetween('usage_date', [$monthStart->toDateString(), now()->toDateString()])
                ->select('feature', DB::raw('SUM(call_count) as calls'), DB::raw('SUM(COALESCE(cost_usd, 0)) as cost_usd'))
                ->groupBy('feature')
                ->orderByDesc(DB::raw('SUM(COALESCE(cost_usd, 0))'))
                ->get()
                ->map(fn ($row) => [
                    'feature' => (string) $row->feature,
                    'calls' => (int) $row->calls,
                    'cost_usd' => round((float) $row->cost_usd, 4),
                ])
                ->values()
                ->all();
        }

        $labelExpr = "COALESCE(NULLIF(TRIM(agent_name), ''), '(Unnamed agent)')";

        $byAgent = DB::table('ai_agent_runs')
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$monthStart, $monthEnd])
            ->selectRaw("{$labelExpr} as label, SUM(estimated_cost) as cost_usd, COUNT(*) as run_count")
            ->groupBy(DB::raw($labelExpr))
            ->orderByDesc('cost_usd')
            ->limit(40)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'cost_usd' => round((float) $row->cost_usd, 4),
                'run_count' => (int) $row->run_count,
            ])
            ->values()
            ->all();

        $taskLabel = "COALESCE(NULLIF(TRIM(task_type), ''), '(unspecified task)')";
        $byTask = DB::table('ai_agent_runs')
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$monthStart, $monthEnd])
            ->selectRaw("{$taskLabel} as label, SUM(estimated_cost) as cost_usd, COUNT(*) as run_count")
            ->groupBy(DB::raw($taskLabel))
            ->orderByDesc('cost_usd')
            ->limit(40)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'cost_usd' => round((float) $row->cost_usd, 4),
                'run_count' => (int) $row->run_count,
            ])
            ->values()
            ->all();

        $modelLabel = "COALESCE(NULLIF(TRIM(model_used), ''), '(model not recorded)')";
        $byModel = DB::table('ai_agent_runs')
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$monthStart, $monthEnd])
            ->selectRaw("{$modelLabel} as label, SUM(estimated_cost) as cost_usd, COUNT(*) as run_count")
            ->groupBy(DB::raw($modelLabel))
            ->orderByDesc('cost_usd')
            ->limit(40)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'cost_usd' => round((float) $row->cost_usd, 4),
                'run_count' => (int) $row->run_count,
            ])
            ->values()
            ->all();

        $byDay = DB::table('ai_agent_runs')
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$monthStart, $monthEnd])
            ->selectRaw("{$daySelect}, SUM(estimated_cost) as cost_usd, COUNT(*) as run_count")
            ->groupBy(DB::raw($dayGroup))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'cost_usd' => round((float) $row->cost_usd, 4),
                'run_count' => (int) $row->run_count,
            ])
            ->values()
            ->all();

        $recentRuns = AIAgentRun::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$monthStart, $monthEnd])
            ->orderByDesc('started_at')
            ->limit(100)
            ->get(['id', 'started_at', 'agent_name', 'task_type', 'model_used', 'estimated_cost', 'status', 'tokens_in', 'tokens_out'])
            ->map(fn (AIAgentRun $r) => [
                'id' => (string) $r->id,
                'started_at' => $r->started_at?->toIso8601String(),
                'agent_name' => $r->agent_name,
                'task_type' => $r->task_type,
                'model_used' => $r->model_used,
                'estimated_cost_usd' => round((float) $r->estimated_cost, 4),
                'status' => $r->status,
                'tokens_in' => (int) ($r->tokens_in ?? 0),
                'tokens_out' => (int) ($r->tokens_out ?? 0),
            ])
            ->values()
            ->all();

        $combined = $agentRunsTotal + $meteredTotal;

        return [
            'combined_total_usd' => round($combined, 4),
            'agent_runs_total_usd' => round($agentRunsTotal, 4),
            'metered_total_usd' => round($meteredTotal, 4),
            'agent_run_count' => $agentRunCount,
            'period_start' => $monthStart->toIso8601String(),
            'period_end' => $monthEnd->toIso8601String(),
            'by_agent' => $byAgent,
            'by_task_type' => $byTask,
            'by_model' => $byModel,
            'by_day' => $byDay,
            'metered_by_feature' => $meteredByFeature,
            'recent_runs' => $recentRuns,
            'methodology' => 'Dollar amounts are internal estimates recorded when each AI job ran (model pricing × tokens / provider quotes). They approximate your variable AI spend but are not Stripe invoices or provider bills. Metered rows add any per-call costs stored on the ai_usage ledger for capped features (tagging, suggestions, etc.).',
        ];
    }

    /**
     * Agency partners linked to this company (client tenant) via tenant_agencies, with managed users and brand roles.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildLinkedAgenciesPayload(Tenant $tenant): array
    {
        return TenantAgency::query()
            ->where('tenant_id', $tenant->id)
            ->with('agencyTenant')
            ->orderBy('created_at')
            ->get()
            ->map(function (TenantAgency $ta) use ($tenant) {
                $base = $ta->toApiArray();
                $agencyTenantId = $ta->agency_tenant_id;

                $managedUsers = DB::table('tenant_user')
                    ->join('users', 'users.id', '=', 'tenant_user.user_id')
                    ->where('tenant_user.tenant_id', $tenant->id)
                    ->where('tenant_user.agency_tenant_id', $agencyTenantId)
                    ->where('tenant_user.is_agency_managed', true)
                    ->select('users.id', 'users.first_name', 'users.last_name', 'users.email', 'tenant_user.role as tenant_role')
                    ->orderBy('users.email')
                    ->get()
                    ->map(function ($row) use ($tenant) {
                        $uid = (int) $row->id;
                        $brandAccess = DB::table('brand_user')
                            ->join('brands', 'brands.id', '=', 'brand_user.brand_id')
                            ->where('brand_user.user_id', $uid)
                            ->where('brands.tenant_id', $tenant->id)
                            ->whereNull('brand_user.removed_at')
                            ->select('brands.name as brand_name', 'brand_user.role as brand_role')
                            ->orderBy('brands.name')
                            ->get()
                            ->map(fn ($b) => [
                                'brand_name' => $b->brand_name,
                                'role' => $b->brand_role,
                            ])
                            ->values()
                            ->all();

                        return [
                            'id' => $uid,
                            'name' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: $row->email,
                            'email' => $row->email,
                            'tenant_role' => $row->tenant_role,
                            'brand_access' => $brandAccess,
                        ];
                    })
                    ->values()
                    ->all();

                return array_merge($base, ['managed_users' => $managedUsers]);
            })
            ->values()
            ->all();
    }
}
