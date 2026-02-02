<?php

namespace App\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Tenant;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Usage Service
 *
 * Tracks AI usage by feature (tagging, suggestions) and enforces monthly caps.
 * Prevents runaway AI costs by implementing hard stops when caps are exceeded.
 *
 * Features:
 * - Per-tenant monthly usage tracking
 * - Feature-specific tracking (tagging, suggestions)
 * - Hard stop when cap exceeded
 * - Monthly reset (based on calendar month)
 */
class AiUsageService
{
    /**
     * Track an AI call for a feature.
     *
     * Hard stop: If cap would be exceeded, throws exception instead of tracking.
     * This prevents runaway usage even if multiple requests check simultaneously.
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @param int $callCount Number of calls (default: 1)
     * @return void
     * @throws PlanLimitExceededException If cap would be exceeded
     */
    public function trackUsage(Tenant $tenant, string $feature, int $callCount = 1): void
    {
        $today = now()->toDateString();

        // Use transaction to prevent race conditions and enforce hard stop
        DB::transaction(function () use ($tenant, $feature, $callCount, $today) {
            // Get current month usage (within transaction for accuracy)
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();
            
            $currentUsage = (int) DB::table('ai_usage')
                ->where('tenant_id', $tenant->id)
                ->where('feature', $feature)
                ->whereBetween('usage_date', [$monthStart, $monthEnd])
                ->sum('call_count');

            // Check cap before tracking (hard stop)
            $cap = $this->getMonthlyCap($tenant, $feature);
            if ($cap > 0 && ($currentUsage + $callCount) > $cap) {
                throw new PlanLimitExceededException(
                    "ai_{$feature}",
                    $currentUsage,
                    $cap,
                    "Monthly AI {$feature} cap exceeded. Current: {$currentUsage}, Cap: {$cap}. Usage resets at the start of next month."
                );
            }

            // Use insertOrUpdate pattern for MySQL compatibility
            $existing = DB::table('ai_usage')
                ->where('tenant_id', $tenant->id)
                ->where('feature', $feature)
                ->where('usage_date', $today)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Increment existing count
                DB::table('ai_usage')
                    ->where('id', $existing->id)
                    ->update([
                        'call_count' => DB::raw("call_count + {$callCount}"),
                        'updated_at' => now(),
                    ]);
            } else {
                // Create new record
                DB::table('ai_usage')->insert([
                    'tenant_id' => $tenant->id,
                    'feature' => $feature,
                    'usage_date' => $today,
                    'call_count' => $callCount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Log::debug('[AiUsageService] Tracked AI usage', [
            'tenant_id' => $tenant->id,
            'feature' => $feature,
            'call_count' => $callCount,
            'date' => $today,
        ]);
    }

    /**
     * Track AI usage with cost attribution.
     *
     * Extends trackUsage() to also track actual costs, tokens, and model used.
     * This is used for AI metadata generation where we have detailed cost information.
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @param int $callCount Number of calls (default: 1)
     * @param float $costUsd Actual cost in USD
     * @param int|null $tokensIn Input tokens used
     * @param int|null $tokensOut Output tokens used
     * @param string|null $model Model name used
     * @return void
     * @throws PlanLimitExceededException If cap would be exceeded
     */
    public function trackUsageWithCost(
        Tenant $tenant,
        string $feature,
        int $callCount = 1,
        float $costUsd = 0.0,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?string $model = null
    ): void {
        $today = now()->toDateString();

        // Use transaction to prevent race conditions and enforce hard stop
        DB::transaction(function () use ($tenant, $feature, $callCount, $costUsd, $tokensIn, $tokensOut, $model, $today) {
            // Get current month usage (within transaction for accuracy)
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();
            
            $currentUsage = (int) DB::table('ai_usage')
                ->where('tenant_id', $tenant->id)
                ->where('feature', $feature)
                ->whereBetween('usage_date', [$monthStart, $monthEnd])
                ->sum('call_count');

            // Check cap before tracking (hard stop)
            $cap = $this->getMonthlyCap($tenant, $feature);
            if ($cap > 0 && ($currentUsage + $callCount) > $cap) {
                throw new PlanLimitExceededException(
                    "ai_{$feature}",
                    $currentUsage,
                    $cap,
                    "Monthly AI {$feature} cap exceeded. Current: {$currentUsage}, Cap: {$cap}. Usage resets at the start of next month."
                );
            }

            // Use insertOrUpdate pattern for MySQL compatibility
            $existing = DB::table('ai_usage')
                ->where('tenant_id', $tenant->id)
                ->where('feature', $feature)
                ->where('usage_date', $today)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Increment existing count and add cost
                DB::table('ai_usage')
                    ->where('id', $existing->id)
                    ->update([
                        'call_count' => DB::raw("call_count + {$callCount}"),
                        'cost_usd' => DB::raw("COALESCE(cost_usd, 0) + {$costUsd}"),
                        'tokens_in' => DB::raw("COALESCE(tokens_in, 0) + " . ($tokensIn ?? 0)),
                        'tokens_out' => DB::raw("COALESCE(tokens_out, 0) + " . ($tokensOut ?? 0)),
                        'model' => $model ?? $existing->model, // Update model if provided
                        'updated_at' => now(),
                    ]);
            } else {
                // Create new record with cost tracking
                DB::table('ai_usage')->insert([
                    'tenant_id' => $tenant->id,
                    'feature' => $feature,
                    'usage_date' => $today,
                    'call_count' => $callCount,
                    'cost_usd' => $costUsd,
                    'tokens_in' => $tokensIn,
                    'tokens_out' => $tokensOut,
                    'model' => $model,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Log::debug('[AiUsageService] Tracked AI usage with cost', [
            'tenant_id' => $tenant->id,
            'feature' => $feature,
            'call_count' => $callCount,
            'cost_usd' => $costUsd,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'model' => $model,
            'date' => $today,
        ]);
    }

    /**
     * Get current month's usage for a tenant and feature.
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @return int Total calls this month
     */
    public function getMonthlyUsage(Tenant $tenant, string $feature): int
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        return (int) DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', $feature)
            ->whereBetween('usage_date', [$monthStart, $monthEnd])
            ->sum('call_count');
    }

    /**
     * Get monthly cap for a tenant and feature.
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @return int Monthly cap (0 = unlimited, -1 = disabled, positive number = cap)
     */
    public function getMonthlyCap(Tenant $tenant, string $feature): int
    {
        $planService = app(PlanService::class);
        $limits = $planService->getPlanLimits($tenant);

        // Get feature-specific cap from plan limits
        $capKey = "max_ai_{$feature}_per_month";
        $cap = $limits[$capKey] ?? 0;

        // 0 = unlimited, -1 = disabled, positive number = cap
        return (int) $cap;
    }

    /**
     * Check if tenant can use AI feature (within cap).
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @return bool True if within cap, false if exceeded
     */
    public function canUseFeature(Tenant $tenant, string $feature): bool
    {
        $cap = $this->getMonthlyCap($tenant, $feature);

        // -1 = disabled
        if ($cap === -1) {
            return false;
        }

        // 0 = unlimited
        if ($cap === 0) {
            return true;
        }

        // Check current usage
        $usage = $this->getMonthlyUsage($tenant, $feature);

        return $usage < $cap;
    }

    /**
     * Check if tenant can use AI feature and throw exception if exceeded.
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @param int $requestedCalls Number of calls requested (default: 1)
     * @return void
     * @throws PlanLimitExceededException If cap exceeded
     */
    public function checkUsage(Tenant $tenant, string $feature, int $requestedCalls = 1): void
    {
        $cap = $this->getMonthlyCap($tenant, $feature);

        // -1 = disabled
        if ($cap === -1) {
            throw new PlanLimitExceededException(
                $feature,
                0,
                0,
                "AI {$feature} is disabled for your plan."
            );
        }

        // 0 = unlimited
        if ($cap === 0) {
            return;
        }

        // Check current usage
        $currentUsage = $this->getMonthlyUsage($tenant, $feature);
        $projectedUsage = $currentUsage + $requestedCalls;

        if ($projectedUsage > $cap) {
            throw new PlanLimitExceededException(
                "ai_{$feature}",
                $currentUsage,
                $cap,
                "Monthly AI {$feature} cap exceeded. Current: {$currentUsage}, Cap: {$cap}. Usage resets at the start of next month."
            );
        }
    }

    /**
     * Get usage status for a tenant (all features).
     *
     * @param Tenant $tenant
     * @return array Status for each feature: ['feature' => ['usage' => int, 'cap' => int, 'remaining' => int, 'percentage' => float]]
     */
    public function getUsageStatus(Tenant $tenant): array
    {
        $features = ['tagging', 'suggestions'];
        $status = [];

        foreach ($features as $feature) {
            $usage = $this->getMonthlyUsage($tenant, $feature);
            $cap = $this->getMonthlyCap($tenant, $feature);

            $remaining = $cap > 0 ? max(0, $cap - $usage) : null;
            $percentage = $cap > 0 ? min(100, ($usage / $cap) * 100) : 0;

            $status[$feature] = [
                'usage' => $usage,
                'cap' => $cap,
                'remaining' => $remaining,
                'percentage' => round($percentage, 2),
                'is_unlimited' => $cap === 0,
                'is_disabled' => $cap === -1,
                'is_exceeded' => $cap > 0 && $usage >= $cap,
            ];
        }

        return $status;
    }

    /**
     * Get usage breakdown by date for current month.
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @return array Array of ['date' => string, 'calls' => int]
     */
    public function getUsageBreakdown(Tenant $tenant, string $feature): array
    {
        return $this->getUsageBreakdownForPeriod($tenant, $feature, (int) now()->format('Y'), (int) now()->format('n'));
    }

    /**
     * Get usage for a specific month (for historical paging).
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @param int $year Year (e.g. 2026)
     * @param int $month Month 1-12
     * @return int Total calls in that month
     */
    public function getMonthlyUsageForPeriod(Tenant $tenant, string $feature, int $year, int $month): int
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        return (int) DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', $feature)
            ->whereBetween('usage_date', [$start, $end])
            ->sum('call_count');
    }

    /**
     * Get usage status for a specific month (for historical paging).
     * Uses current plan caps for display; usage is for the requested period.
     *
     * @param Tenant $tenant
     * @param int $year Year (e.g. 2026)
     * @param int $month Month 1-12
     * @return array Same shape as getUsageStatus()
     */
    public function getUsageStatusForPeriod(Tenant $tenant, int $year, int $month): array
    {
        $features = ['tagging', 'suggestions'];
        $status = [];

        foreach ($features as $feature) {
            $usage = $this->getMonthlyUsageForPeriod($tenant, $feature, $year, $month);
            $cap = $this->getMonthlyCap($tenant, $feature);

            $remaining = $cap > 0 ? max(0, $cap - $usage) : null;
            $percentage = $cap > 0 ? min(100, ($usage / $cap) * 100) : 0;

            $status[$feature] = [
                'usage' => $usage,
                'cap' => $cap,
                'remaining' => $remaining,
                'percentage' => round($percentage, 2),
                'is_unlimited' => $cap === 0,
                'is_disabled' => $cap === -1,
                'is_exceeded' => $cap > 0 && $usage >= $cap,
            ];
        }

        return $status;
    }

    /**
     * Get usage breakdown by date for a specific month.
     *
     * @param Tenant $tenant
     * @param string $feature Feature name ('tagging', 'suggestions')
     * @param int $year Year (e.g. 2026)
     * @param int $month Month 1-12
     * @return array Array of ['date' => string, 'calls' => int]
     */
    public function getUsageBreakdownForPeriod(Tenant $tenant, string $feature, int $year, int $month): array
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $usage = DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', $feature)
            ->whereBetween('usage_date', [$start, $end])
            ->orderBy('usage_date')
            ->get(['usage_date', 'call_count']);

        return $usage->map(function ($row) {
            return [
                'date' => $row->usage_date,
                'calls' => (int) $row->call_count,
            ];
        })->toArray();
    }
}
