<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Services\CompanyCostService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        
        // Check permission: user must have 'company.manage' permission
        if (!$user->can('company.manage')) {
            abort(403, 'You do not have permission to view company details.');
        }

        $planService = app(PlanService::class);
        $costService = app(CompanyCostService::class);

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

        // Get users overview (first 5) - using standardized query method
        $users = $this->getCompanyUsers($tenant, 5);

        // Get all brands for the add user form (not just first 5)
        $allBrands = $tenant->brands()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get()
            ->map(function ($brand) {
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'is_default' => $brand->is_default,
                ];
            });

        // Get brands overview (first 5) for display
        $brands = $allBrands->take(5);

        // Get asset count and total storage
        // Use direct query since Tenant model doesn't have assets() relationship
        $assetCount = Asset::where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->count();
        
        $totalStorageBytes = Asset::where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->sum('size_bytes') ?? 0;
        
        $totalStorageGB = round($totalStorageBytes / (1024 * 1024 * 1024), 2);

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
        ]);
    }

    /**
     * Get company users using standardized query method.
     * This ensures we avoid orphan issues by properly querying through the tenant relationship.
     * 
     * @param Tenant $tenant
     * @param int|null $limit
     * @return \Illuminate\Support\Collection
     */
    protected function getCompanyUsers(Tenant $tenant, ?int $limit = null)
    {
        $query = $tenant->users()
            ->orderBy('tenant_user.created_at', 'desc');
        
        if ($limit) {
            $query->limit($limit);
        }
        
        return $query->get()->map(function ($user) use ($tenant) {
            return [
                'id' => $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email,
                'role' => $user->getRoleForTenant($tenant),
                'is_owner' => $tenant->isOwner($user),
            ];
        });
    }
}
