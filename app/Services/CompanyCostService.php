<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Asset;
use App\Models\AIAgentRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Invoice;
use Stripe\Price;

/**
 * Company Cost Service
 * 
 * Calculates costs associated with a company/tenant:
 * - S3 storage costs (based on asset sizes)
 * - AI agent costs (from agent runs)
 * 
 * TODO: Add more cost categories as needed:
 * - API usage costs
 * - Bandwidth costs
 * - Support ticket costs
 * - Custom feature costs
 */
class CompanyCostService
{
    /**
     * S3 storage pricing (per GB per month)
     * Standard S3 pricing: ~$0.023 per GB/month for standard storage
     * This is an estimate - adjust based on actual AWS pricing or your S3 provider
     */
    private const S3_STORAGE_COST_PER_GB_MONTH = 0.023;

    /**
     * Calculate total monthly costs for a company.
     * 
     * @param Tenant $tenant
     * @param int|null $month Month (1-12), null for current month
     * @param int|null $year Year, null for current year
     * @return array
     */
    public function calculateMonthlyCosts(Tenant $tenant, ?int $month = null, ?int $year = null): array
    {
        $month = $month ?? now()->month;
        $year = $year ?? now()->year;

        $storageCosts = $this->calculateStorageCosts($tenant, $month, $year);
        $aiCosts = $this->calculateAIAgentCosts($tenant, $month, $year);
        $total = ($storageCosts['monthly_cost'] ?? 0) + ($aiCosts['total_cost'] ?? 0);
        
        return [
            'storage' => $storageCosts,
            'ai_agents' => $aiCosts,
            'total' => round($total, 2),
        ];
    }

    /**
     * Calculate S3 storage costs for a company.
     * 
     * @param Tenant $tenant
     * @param int|null $month
     * @param int|null $year
     * @return array
     */
    public function calculateStorageCosts(Tenant $tenant, ?int $month = null, ?int $year = null): array
    {
        try {
            // Get total asset size in bytes for this tenant
            // Only count assets that exist (not soft-deleted)
            $totalBytes = Asset::where('tenant_id', $tenant->id)
                ->whereNull('deleted_at')
                ->sum('size_bytes') ?? 0;

            // Convert bytes to GB
            $totalGB = $totalBytes / (1024 * 1024 * 1024);

            // Calculate monthly cost
            $monthlyCost = $totalGB * self::S3_STORAGE_COST_PER_GB_MONTH;

            return [
                'total_bytes' => $totalBytes,
                'total_gb' => round($totalGB, 4),
                'monthly_cost' => round($monthlyCost, 2),
                'rate_per_gb_month' => self::S3_STORAGE_COST_PER_GB_MONTH,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to calculate storage costs', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'total_bytes' => 0,
                'total_gb' => 0,
                'monthly_cost' => 0,
                'rate_per_gb_month' => self::S3_STORAGE_COST_PER_GB_MONTH,
            ];
        }
    }

    /**
     * Calculate AI agent costs for a company.
     * 
     * TODO: Implement actual AI agent cost calculation based on:
     * - Token usage
     * - Model used
     * - API provider pricing
     * 
     * For now, this is a placeholder that sums up costs from agent runs.
     * 
     * @param Tenant $tenant
     * @param int|null $month
     * @param int|null $year
     * @return array
     */
    public function calculateAIAgentCosts(Tenant $tenant, ?int $month = null, ?int $year = null): array
    {
        try {
            $startDate = now()->setMonth($month ?? now()->month)
                ->setYear($year ?? now()->year)
                ->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // Sum up costs from AI agent runs for this tenant in the specified month
            $totalCost = AIAgentRun::where('tenant_id', $tenant->id)
                ->whereBetween('started_at', [$startDate, $endDate])
                ->sum('estimated_cost') ?? 0;

            // Convert from cents to dollars if stored in cents, or keep as dollars
            // Assuming cost is stored in dollars (adjust if needed)
            $totalCostDollars = $totalCost;

            return [
                'total_cost' => round($totalCostDollars, 2),
                'runs_count' => AIAgentRun::where('tenant_id', $tenant->id)
                    ->whereBetween('started_at', [$startDate, $endDate])
                    ->count(),
                'period' => [
                    'month' => $month ?? now()->month,
                    'year' => $year ?? now()->year,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Failed to calculate AI agent costs', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'total_cost' => 0,
                'runs_count' => 0,
                'period' => [
                    'month' => $month ?? now()->month,
                    'year' => $year ?? now()->year,
                ],
            ];
        }
    }

    /**
     * Get income/revenue for a company from Stripe subscriptions.
     * 
     * @param Tenant $tenant
     * @param int|null $month
     * @param int|null $year
     * @return array
     */
    public function calculateIncome(Tenant $tenant, ?int $month = null, ?int $year = null): array
    {
        try {
            $startDate = now()->setMonth($month ?? now()->month)
                ->setYear($year ?? now()->year)
                ->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // Check billing status - comped and trial accounts have $0 revenue
            // billing_status values:
            // - null/'paid': Normal billing (Stripe subscription) - generates revenue
            // - 'trial': Trial period - no revenue during trial
            // - 'comped': Free account - no revenue ever
            // 
            // This is NOT frontend-facing - for accounting purposes only
            // TODO: Make sure billing_status is never exposed to frontend or customers
            if (in_array($tenant->billing_status, ['comped', 'trial'])) {
                return [
                    'total_income' => 0,
                    'subscription_active' => false,
                    'billing_status' => $tenant->billing_status,
                    'is_comped' => $tenant->billing_status === 'comped',
                    'is_trial' => $tenant->billing_status === 'trial',
                    'equivalent_plan_value' => $tenant->equivalent_plan_value, // Sales insight only - NOT revenue
                    'period' => [
                        'month' => $month ?? now()->month,
                        'year' => $year ?? now()->year,
                    ],
                ];
            }

            // If no Stripe customer ID, cannot fetch invoices
            if (!$tenant->stripe_id) {
                return [
                    'total_income' => 0,
                    'subscription_active' => false,
                    'billing_status' => $tenant->billing_status ?? 'paid',
                    'is_comped' => false,
                    'is_trial' => false,
                    'invoices_count' => 0,
                    'period' => [
                        'month' => $month ?? now()->month,
                        'year' => $year ?? now()->year,
                    ],
                ];
            }

            // Fetch actual paid invoices from Stripe API
            // This matches the pattern used in SiteAdminController::stripeStatus()
            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                Log::warning('Stripe secret not configured - cannot fetch invoice data', [
                    'tenant_id' => $tenant->id,
                ]);
                
                // Fallback to subscription estimate
                return $this->calculateIncomeFromSubscription($tenant, $month, $year);
            }

            Stripe::setApiKey($stripeSecret);
            
            // Fetch paid invoices for this customer in the specified month
            $totalIncome = 0;
            $invoicesCount = 0;
            
            try {
                // Get invoices for this customer
                // Note: Stripe invoice.created timestamp is used, but we filter by status='paid'
                $invoices = Invoice::all([
                    'customer' => $tenant->stripe_id,
                    'status' => 'paid',
                    'created' => [
                        'gte' => $startDate->timestamp,
                        'lte' => $endDate->timestamp,
                    ],
                    'limit' => 100, // Stripe API limit
                ]);

                foreach ($invoices->data as $invoice) {
                    // Only count invoices that were actually paid in this period
                    if ($invoice->status === 'paid' && $invoice->amount_paid > 0) {
                        // Convert from cents to dollars
                        $amount = ($invoice->amount_paid ?? 0) / 100;
                        $totalIncome += $amount;
                        $invoicesCount++;
                    }
                }

                // If no invoices found for this month, fall back to subscription amount estimate
                // This handles cases where invoice hasn't been generated yet or is in a different month
                if ($invoicesCount === 0) {
                    return $this->calculateIncomeFromSubscription($tenant, $month, $year);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch Stripe invoices, falling back to subscription estimate', [
                    'tenant_id' => $tenant->id,
                    'stripe_id' => $tenant->stripe_id,
                    'error' => $e->getMessage(),
                ]);
                
                // Fallback to subscription estimate
                return $this->calculateIncomeFromSubscription($tenant, $month, $year);
            }

            // Check if subscription is active
            $subscription = $tenant->subscription('default');
            $subscriptionActive = $subscription && $subscription->stripe_status === 'active';

            return [
                'total_income' => round($totalIncome, 2),
                'subscription_active' => $subscriptionActive,
                'billing_status' => $tenant->billing_status ?? 'paid',
                'is_comped' => false,
                'is_trial' => false,
                'invoices_count' => $invoicesCount,
                'period' => [
                    'month' => $month ?? now()->month,
                    'year' => $year ?? now()->year,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Failed to calculate income', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Fallback to subscription estimate
            return $this->calculateIncomeFromSubscription($tenant, $month, $year);
        }
    }

    /**
     * Calculate income from subscription price (fallback method).
     * 
     * Used when Stripe invoice data is not available.
     * Estimates income based on subscription price.
     * Matches the pattern used in SiteAdminController::stripeStatus()
     * 
     * @param Tenant $tenant
     * @param int|null $month
     * @param int|null $year
     * @return array
     */
    protected function calculateIncomeFromSubscription(Tenant $tenant, ?int $month = null, ?int $year = null): array
    {
        $subscription = $tenant->subscription('default');
        $monthlyIncome = 0;

        if ($subscription && $subscription->stripe_status === 'active') {
            // Fetch actual price from Stripe API (matches pattern from SiteAdminController)
            $stripeSecret = config('services.stripe.secret');
            if ($stripeSecret && $subscription->stripe_price) {
                try {
                    Stripe::setApiKey($stripeSecret);
                    $price = Price::retrieve($subscription->stripe_price);
                    
                    // Calculate monthly revenue based on interval
                    if ($price->recurring->interval === 'month') {
                        $monthlyIncome = ($price->unit_amount ?? 0) / 100;
                    } elseif ($price->recurring->interval === 'year') {
                        // Annual subscription - divide by 12 for monthly
                        $monthlyIncome = (($price->unit_amount ?? 0) / 100) / 12;
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to fetch Stripe price for subscription estimate', [
                        'tenant_id' => $tenant->id,
                        'price_id' => $subscription->stripe_price,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'total_income' => round($monthlyIncome, 2),
            'subscription_active' => $subscription && $subscription->stripe_status === 'active',
            'billing_status' => $tenant->billing_status ?? 'paid',
            'is_comped' => false,
            'is_trial' => false,
            'invoices_count' => 0, // Estimated, not actual count
            'period' => [
                'month' => $month ?? now()->month,
                'year' => $year ?? now()->year,
            ],
        ];
    }

    /**
     * Calculate profitability rating for a company.
     * 
     * Returns a rating based on income vs expenses:
     * - 'profitable': Income > Expenses * 1.2 (20% margin)
     * - 'break_even': Income >= Expenses * 0.9 and <= Expenses * 1.2
     * - 'losing': Income < Expenses * 0.9
     * 
     * @param Tenant $tenant
     * @param int|null $month
     * @param int|null $year
     * @return array
     */
    public function calculateProfitabilityRating(Tenant $tenant, ?int $month = null, ?int $year = null): array
    {
        $costs = $this->calculateMonthlyCosts($tenant, $month, $year);
        $income = $this->calculateIncome($tenant, $month, $year);

        $totalCosts = ($costs['storage']['monthly_cost'] ?? 0) + ($costs['ai_agents']['total_cost'] ?? 0);
        $totalIncome = $income['total_income'] ?? 0;

        // Calculate profit margin
        $profit = $totalIncome - $totalCosts;
        $margin = $totalIncome > 0 ? ($profit / $totalIncome) * 100 : 0;

        // Determine rating
        $rating = 'unknown';
        $ratingLabel = 'Unknown';
        $ratingColor = 'gray';

        if ($totalIncome === 0 && $totalCosts === 0) {
            $rating = 'no_data';
            $ratingLabel = 'No Data';
            $ratingColor = 'gray';
        } elseif ($totalIncome === 0) {
            $rating = 'losing';
            $ratingLabel = 'Losing Money';
            $ratingColor = 'red';
        } elseif ($totalIncome > $totalCosts * 1.2) {
            $rating = 'profitable';
            $ratingLabel = 'Profitable';
            $ratingColor = 'green';
        } elseif ($totalIncome >= $totalCosts * 0.9) {
            $rating = 'break_even';
            $ratingLabel = 'Break Even';
            $ratingColor = 'yellow';
        } else {
            $rating = 'losing';
            $ratingLabel = 'Losing Money';
            $ratingColor = 'red';
        }

        return [
            'rating' => $rating,
            'label' => $ratingLabel,
            'color' => $ratingColor,
            'income' => $totalIncome,
            'costs' => $totalCosts,
            'profit' => $profit,
            'margin_percent' => round($margin, 1),
        ];
    }
}
