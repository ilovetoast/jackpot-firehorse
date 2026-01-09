<?php

namespace App\Services;

use App\Exceptions\AIBudgetExceededException;
use App\Models\AIBudget;
use App\Models\AIBudgetUsage;
use App\Services\ActivityRecorder;
use App\Enums\EventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * AI Budget Service
 *
 * Manages AI budgets, checks limits, and records usage.
 * Handles both soft limits (warnings) and hard limits (blocks).
 */
class AIBudgetService
{
    public function __construct(
        protected AIConfigService $configService,
        protected ActivityRecorder $activityRecorder
    ) {
    }

    /**
     * Get system-wide budget for environment.
     */
    public function getSystemBudget(?string $environment = null): ?AIBudget
    {
        $environment = $environment ?? app()->environment();

        return AIBudget::system()
            ->monthly()
            ->byEnvironment($environment)
            ->first();
    }

    /**
     * Get agent-specific budget.
     */
    public function getAgentBudget(string $agentId, ?string $environment = null): ?AIBudget
    {
        $environment = $environment ?? app()->environment();

        return AIBudget::forAgent($agentId)
            ->monthly()
            ->byEnvironment($environment)
            ->first();
    }

    /**
     * Get task-specific budget.
     */
    public function getTaskBudget(string $taskType, ?string $environment = null): ?AIBudget
    {
        $environment = $environment ?? app()->environment();

        return AIBudget::forTask($taskType)
            ->monthly()
            ->byEnvironment($environment)
            ->first();
    }

    /**
     * Check if execution is allowed based on budget limits.
     *
     * @param AIBudget|null $budget Budget to check
     * @param float $estimatedCost Estimated cost of the operation
     * @param string|null $environment Environment name
     * @throws AIBudgetExceededException If hard limit is enabled and would be exceeded
     */
    public function checkBudget(?AIBudget $budget, float $estimatedCost, ?string $environment = null): void
    {
        if (!$budget) {
            return; // No budget defined, allow execution
        }

        $environment = $environment ?? app()->environment();
        $effectiveAmount = $budget->getEffectiveAmount($environment);
        $currentUsage = $budget->getCurrentUsage($environment);
        $projectedUsage = $currentUsage + $estimatedCost;

        // Check hard limit
        if ($budget->isHardLimitEnabled($environment)) {
            if ($projectedUsage > $effectiveAmount) {
                $reason = sprintf(
                    'Hard budget limit exceeded: %s budget is $%.2f, current usage is $%.2f, projected usage would be $%.2f',
                    $this->getBudgetDescription($budget),
                    $effectiveAmount,
                    $currentUsage,
                    $projectedUsage
                );

                // Log the block
                Log::warning('AI execution blocked by hard budget limit', [
                    'budget_id' => $budget->id,
                    'budget_type' => $budget->budget_type,
                    'scope_key' => $budget->scope_key,
                    'effective_amount' => $effectiveAmount,
                    'current_usage' => $currentUsage,
                    'estimated_cost' => $estimatedCost,
                    'projected_usage' => $projectedUsage,
                    'environment' => $environment,
                ]);

                // Record activity event
                $this->activityRecorder->record(EventType::AI_BUDGET_BLOCKED, [
                    'budget_id' => $budget->id,
                    'budget_type' => $budget->budget_type,
                    'scope_key' => $budget->scope_key,
                    'effective_amount' => $effectiveAmount,
                    'current_usage' => $currentUsage,
                    'estimated_cost' => $estimatedCost,
                    'projected_usage' => $projectedUsage,
                    'environment' => $environment,
                ]);

                throw new AIBudgetExceededException($reason, $budget, $effectiveAmount, $currentUsage, $estimatedCost);
            }
        }

        // Check warning threshold
        if ($budget->isNearBudget($environment)) {
            $warningThreshold = $budget->getEffectiveWarningThreshold($environment);
            $thresholdAmount = $effectiveAmount * ($warningThreshold / 100);

            if ($currentUsage >= $thresholdAmount) {
                Log::warning('AI budget warning threshold reached', [
                    'budget_id' => $budget->id,
                    'budget_type' => $budget->budget_type,
                    'scope_key' => $budget->scope_key,
                    'effective_amount' => $effectiveAmount,
                    'current_usage' => $currentUsage,
                    'warning_threshold_percent' => $warningThreshold,
                    'environment' => $environment,
                ]);

                $this->activityRecorder->record(EventType::AI_BUDGET_WARNING_TRIGGERED, [
                    'budget_id' => $budget->id,
                    'budget_type' => $budget->budget_type,
                    'scope_key' => $budget->scope_key,
                    'effective_amount' => $effectiveAmount,
                    'current_usage' => $currentUsage,
                    'warning_threshold_percent' => $warningThreshold,
                    'environment' => $environment,
                ]);
            }
        }

        // Check if over soft limit (but not hard limit)
        if ($budget->isOverBudget($environment) && !$budget->isHardLimitEnabled($environment)) {
            Log::warning('AI budget soft limit exceeded', [
                'budget_id' => $budget->id,
                'budget_type' => $budget->budget_type,
                'scope_key' => $budget->scope_key,
                'effective_amount' => $effectiveAmount,
                'current_usage' => $currentUsage,
                'environment' => $environment,
            ]);

            $this->activityRecorder->record(EventType::AI_BUDGET_EXCEEDED, [
                'budget_id' => $budget->id,
                'budget_type' => $budget->budget_type,
                'scope_key' => $budget->scope_key,
                'effective_amount' => $effectiveAmount,
                'current_usage' => $currentUsage,
                'environment' => $environment,
            ]);
        }
    }

    /**
     * Record cost against budget.
     */
    public function recordUsage(AIBudget $budget, float $cost, ?string $environment = null): void
    {
        $environment = $environment ?? app()->environment();
        $now = Carbon::now();
        $periodStart = $now->copy()->startOfMonth();
        $periodEnd = $now->copy()->endOfMonth();

        $usage = AIBudgetUsage::firstOrCreate(
            [
                'budget_id' => $budget->id,
                'period_start' => $periodStart->format('Y-m-d'),
            ],
            [
                'period_end' => $periodEnd->format('Y-m-d'),
                'amount_used' => 0,
                'last_updated_at' => now(),
            ]
        );

        $usage->incrementUsage($cost);
    }

    /**
     * Get budget status (on-track, warning, over).
     */
    public function getBudgetStatus(AIBudget $budget, ?string $environment = null): string
    {
        $environment = $environment ?? app()->environment();

        if ($budget->isOverBudget($environment)) {
            return 'over';
        }

        if ($budget->isNearBudget($environment)) {
            return 'warning';
        }

        return 'on-track';
    }

    /**
     * Reset monthly budgets for new month.
     * Called by scheduled command on 1st of each month.
     */
    public function resetMonthlyBudgets(): void
    {
        $now = Carbon::now();
        $periodStart = $now->copy()->startOfMonth();
        $periodEnd = $now->copy()->endOfMonth();

        $budgets = AIBudget::monthly()->get();

        foreach ($budgets as $budget) {
            $usage = AIBudgetUsage::where('budget_id', $budget->id)
                ->where('period_start', $periodStart->format('Y-m-d'))
                ->first();

            if (!$usage) {
                // Create new usage record for this month
                AIBudgetUsage::create([
                    'budget_id' => $budget->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'amount_used' => 0,
                    'last_updated_at' => now(),
                ]);
            } else {
                // Reset existing usage record
                $usage->resetForPeriod($periodStart, $periodEnd);
            }
        }

        Log::info('Monthly AI budgets reset', [
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
            'budgets_reset' => $budgets->count(),
        ]);
    }

    /**
     * Get human-readable budget description.
     */
    protected function getBudgetDescription(AIBudget $budget): string
    {
        if ($budget->budget_type === 'system') {
            return 'System-wide';
        }

        if ($budget->budget_type === 'agent') {
            return "Agent '{$budget->scope_key}'";
        }

        if ($budget->budget_type === 'task_type') {
            return "Task type '{$budget->scope_key}'";
        }

        return 'Unknown';
    }
}
