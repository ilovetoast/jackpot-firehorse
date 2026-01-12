<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\CompanyCostService;
use App\Services\AWSCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Billing Controller
 * 
 * Shows aggregate billing/accounting information for all companies.
 * Protected by 'company.manage' permission.
 * 
 * TODO: Implement actual expense data fetching:
 * - AWS/S3 storage costs (query AWS Cost Explorer API or billing reports)
 * - EC2, RDS, Lambda compute costs
 * - Email services costs
 * - Monitoring/logging costs (CloudWatch, etc.)
 * - CI/CD compute costs
 * - SaaS tools costs (vendors we use to run the business)
 */
class BillingController extends Controller
{
    /**
     * Display the admin billing overview page.
     * 
     * @return Response
     */
    public function index(): Response
    {
        $user = Auth::user();
        
        // Check permission: user must have 'company.manage' permission
        // Also allow site_owner or site_admin roles
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        $hasPermission = $user->can('company.manage');
        
        if (!$isSiteOwner && !$isSiteAdmin && !$hasPermission) {
            abort(403, 'You do not have permission to view billing information.');
        }

        $costService = app(CompanyCostService::class);
        $awsCostService = app(AWSCostService::class);

        // Get all tenants with their subscription info
        $tenants = Tenant::with(['subscriptions' => function ($query) {
            $query->where('name', 'default')
                ->orderBy('created_at', 'desc');
        }])->get();

        // Calculate aggregate income from Stripe subscriptions
        // TODO: Implement actual Stripe invoice fetching for accurate income data
        // For now, estimate based on subscription prices
        // 
        // Accounting rules:
        // - 'paid' accounts (null billing_status with Stripe): Generate revenue
        // - 'trial' accounts: No revenue during trial, expenses still apply
        // - 'comped' accounts: No revenue ever, expenses still apply
        // - Do NOT count equivalent_plan_value as revenue (sales insight only)
        $totalMonthlyIncome = 0;
        $totalMonthlyCosts = 0;
        $compedAccountsCount = 0;
        $trialAccountsCount = 0;
        $paidAccountsCount = 0;
        $totalEquivalentPlanValue = 0; // Sales insight only - NOT revenue

        foreach ($tenants as $tenant) {
            $costs = $costService->calculateMonthlyCosts($tenant);
            $income = $costService->calculateIncome($tenant);
            
            $totalMonthlyCosts += $costs['total'];
            
            // Count income based on billing_status
            // Accounting: Only 'paid' accounts (null billing_status with Stripe) generate revenue
            // 'trial' and 'comped' accounts have $0 revenue
            // TODO: Fetch actual Stripe invoice amounts for paid accounts
            if ($tenant->billing_status === 'comped') {
                $compedAccountsCount++;
                // Track equivalent_plan_value for sales insights (NOT real revenue)
                $totalEquivalentPlanValue += $tenant->equivalent_plan_value ?? 0;
                // Income is already $0 for comped accounts (handled in service)
            } elseif ($tenant->billing_status === 'trial') {
                $trialAccountsCount++;
                // Income is already $0 for trial accounts (handled in service)
            } else {
                // Paid account (null or 'paid' with Stripe subscription)
                $totalMonthlyIncome += $income['total_income'] ?? 0;
                if ($tenant->subscriptions->where('stripe_status', 'active')->count() > 0) {
                    $paidAccountsCount++;
                }
            }
        }

        // Fetch AWS costs from Cost Explorer API
        $awsCosts = $awsCostService->getMonthlyCostsByCategory();
        $awsCostsError = $awsCosts['error'] ?? null;
        
        // Calculate infrastructure expenses
        // Accounting: Deduct ALL expenses regardless of account billing_status
        // Do NOT invent revenue or discounts - only count actual Stripe income
        $infrastructureExpenses = [
            'aws_storage' => $awsCosts['by_category']['storage'] ?? 0,
            'aws_compute' => $awsCosts['by_category']['compute'] ?? 0,
            'aws_database' => $awsCosts['by_category']['database'] ?? 0,
            'aws_networking' => $awsCosts['by_category']['networking'] ?? 0,
            'aws_monitoring' => $awsCosts['by_category']['monitoring'] ?? 0,
            'aws_other' => $awsCosts['by_category']['other'] ?? 0,
            'email_services' => 0, // TODO: Query email provider costs (SendGrid, SES, etc.)
            'cicd' => 0, // TODO: Query CI/CD platform costs (GitHub Actions, CircleCI, etc.)
            'saas_tools' => 0, // TODO: Query SaaS vendor costs (Intercom, analytics, etc.)
            'contractors' => 0, // TODO: Track contractor payments (separate system needed)
        ];

        // Last 12 months of data for chart
        $monthlyData = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $month = $date->month;
            $year = $date->year;
            
            // Calculate income and costs for this month
            $monthIncome = 0;
            $monthCosts = 0;
            
            foreach ($tenants as $tenant) {
            // Calculate income based on billing_status
            // Only 'paid' accounts generate revenue (trial and comped have $0)
            // Do NOT count equivalent_plan_value as revenue (sales insight only)
            $income = $costService->calculateIncome($tenant, $month, $year);
            $monthIncome += $income['total_income'] ?? 0;
            
            // Calculate costs (expenses apply to ALL accounts, including trial and comped)
            // Expenses include: AWS/S3, Compute, SaaS tools, Contractors, etc.
            $costs = $costService->calculateMonthlyCosts($tenant, $month, $year);
            $monthCosts += $costs['total'];
            }
            
            // Fetch AWS costs for this month
            $monthAwsCosts = $awsCostService->getMonthlyCostsByCategory($month, $year);
            $monthInfraExpenses = $monthAwsCosts['total'] ?? 0; // Only AWS for now, add other providers later
            
            $monthlyData[] = [
                'month' => $date->format('M Y'),
                'month_num' => $month,
                'year' => $year,
                'income' => round($monthIncome, 2),
                'company_costs' => round($monthCosts, 2),
                'infrastructure_expenses' => round($monthInfraExpenses, 2),
                'total_expenses' => round($monthCosts + $monthInfraExpenses, 2),
            ];
        }

        // Calculate profitability
        $netProfit = $totalMonthlyIncome - $totalMonthlyCosts;
        $margin = $totalMonthlyIncome > 0 
            ? (($netProfit / $totalMonthlyIncome) * 100) 
            : 0;

        return Inertia::render('Admin/Billing', [
            'summary' => [
                'total_monthly_income' => round($totalMonthlyIncome, 2),
                'total_monthly_costs' => round($totalMonthlyCosts, 2),
                'total_infrastructure_expenses' => array_sum($infrastructureExpenses),
                'net_profit' => round($netProfit, 2),
                'margin_percent' => round($margin, 1),
                'paid_accounts' => $paidAccountsCount,
                'trial_accounts' => $trialAccountsCount,
                'comped_accounts' => $compedAccountsCount,
                'total_accounts' => $tenants->count(),
                'total_equivalent_plan_value' => round($totalEquivalentPlanValue, 2), // Sales insight only - NOT revenue
            ],
            'monthlyData' => $monthlyData,
            'infrastructureExpenses' => $infrastructureExpenses,
            'awsCosts' => [
                'total' => $awsCosts['total'] ?? 0,
                'by_category' => $awsCosts['by_category'] ?? [],
                'by_service' => $awsCosts['by_service'] ?? [],
                'error' => $awsCostsError,
            ],
            'compedAccounts' => $tenants->where('billing_status', 'comped')->count(),
            'trialAccounts' => $tenants->where('billing_status', 'trial')->count(),
        ]);
    }
}
