<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\EventType;
use App\Models\Tenant;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Services\CompanyCostService;
use App\Services\CompanyDataService;
use App\Services\PlanService;
use App\Services\AiUsageService;
use App\Services\AICostReportingService;
use Illuminate\Http\Request;
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
     * 
     * @param Tenant $tenant
     * @return Response
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
        $costService = app(CompanyCostService::class);
        $aiUsageService = app(AiUsageService::class);
        $aiCostReportingService = app(AICostReportingService::class);

        // Get basic company information
        $owner = $tenant->owner();
        $planName = $planService->getCurrentPlan($tenant);
        $planConfig = config("plans.{$planName}", config('plans.free'));
        
        // Get subscription info
        $subscription = $tenant->subscription('default');
        $stripeConnected = !empty($tenant->stripe_id);

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
                    'name' => trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: $actor->email ?? $metadata['admin_name'] ?? 'System',
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

        // Get AI usage and billing estimates
        $aiUsageData = null;
        try {
            // Get usage status and breakdown
            $usageStatus = $aiUsageService->getUsageStatus($tenant);
            
            // Get individual feature usage for current month
            $taggingUsage = $aiUsageService->getMonthlyUsage($tenant, 'tagging');
            $suggestionsUsage = $aiUsageService->getMonthlyUsage($tenant, 'suggestions');
            $totalUsage = $taggingUsage + $suggestionsUsage;
            
            // Get AI cost report for current month (filter by tenant in query)
            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();
            
            // Query AIAgentRun directly for tenant-specific cost data
            $tenantCostData = \App\Models\AIAgentRun::where('tenant_id', $tenant->id)
                ->whereBetween('started_at', [$monthStart, $monthEnd])
                ->get();
            
            $totalCost = $tenantCostData->sum('estimated_cost');
            $totalRuns = $tenantCostData->count();
            
            // Get monthly cap data
            $taggingCap = $aiUsageService->getMonthlyCap($tenant, 'tagging');
            $suggestionsCap = $aiUsageService->getMonthlyCap($tenant, 'suggestions');
            
            // Calculate projections based on current usage
            $daysInMonth = $monthEnd->day;
            $daysElapsed = now()->day;
            $dailyAverageUsage = $daysElapsed > 0 ? $totalUsage / $daysElapsed : 0;
            $projectedMonthlyUsage = $dailyAverageUsage * $daysInMonth;
            
            // Calculate estimated costs
            $dailyAverageCost = $daysElapsed > 0 ? $totalCost / $daysElapsed : 0;
            $projectedMonthlyCost = $dailyAverageCost * $daysInMonth;
            
            // Calculate usage percentage against highest cap
            $maxCap = max($taggingCap ?: 0, $suggestionsCap ?: 0);
            $usagePercentage = $maxCap > 0 ? min(100, ($projectedMonthlyUsage / $maxCap) * 100) : 0;
            
            $aiUsageData = [
                'current_usage' => [
                    'total_calls' => $totalUsage,
                    'features' => [
                        'tagging' => $taggingUsage,
                        'suggestions' => $suggestionsUsage,
                    ],
                    'cost_to_date' => round($totalCost, 4),
                    'total_runs' => $totalRuns,
                ],
                'caps' => [
                    'tagging' => $taggingCap,
                    'suggestions' => $suggestionsCap,
                ],
                'projections' => [
                    'monthly_usage' => round($projectedMonthlyUsage),
                    'monthly_cost' => round($projectedMonthlyCost, 2),
                    'usage_percentage' => round($usagePercentage, 1),
                ],
                'usage_status' => $usageStatus,
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

        return Inertia::render('Admin/CompanyView', [
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
                'can_manage_plan' => !$stripeConnected, // Allow non-Stripe plans to be managed
                'plan_change_info' => $planChangeInfo,
                'owner' => $owner ? [
                    'id' => $owner->id,
                    'name' => trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')),
                    'email' => $owner->email,
                ] : null,
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

}
