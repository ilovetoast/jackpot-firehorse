<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * AI Budget Model
 *
 * Represents an AI budget definition (system-wide, per-agent, or per-task type).
 * Budgets are defined in config/ai.php with optional database overrides.
 */
class AIBudget extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_budgets';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'budget_type',
        'scope_key',
        'amount',
        'period',
        'warning_threshold_percent',
        'hard_limit_enabled',
        'environment',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'warning_threshold_percent' => 'integer',
            'hard_limit_enabled' => 'boolean',
        ];
    }

    /**
     * Get all overrides for this budget.
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(AIBudgetOverride::class, 'budget_id');
    }

    /**
     * Get all usage records for this budget.
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(AIBudgetUsage::class, 'budget_id');
    }

    /**
     * Scope a query to only include system budgets.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('budget_type', 'system');
    }

    /**
     * Scope a query to only include agent budgets.
     */
    public function scopeForAgent(Builder $query, string $agentId): Builder
    {
        return $query->where('budget_type', 'agent')
            ->where('scope_key', $agentId);
    }

    /**
     * Scope a query to only include task type budgets.
     */
    public function scopeForTask(Builder $query, string $taskType): Builder
    {
        return $query->where('budget_type', 'task_type')
            ->where('scope_key', $taskType);
    }

    /**
     * Scope a query to filter by environment.
     */
    public function scopeByEnvironment(Builder $query, ?string $environment): Builder
    {
        return $query->where(function ($q) use ($environment) {
            $q->whereNull('environment')
                ->when($environment, fn ($q) => $q->orWhere('environment', $environment));
        });
    }

    /**
     * Scope a query to only include monthly budgets.
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('period', 'monthly');
    }

    /**
     * Get the effective budget amount (considering overrides).
     */
    public function getEffectiveAmount(?string $environment = null): float
    {
        $override = $this->overrides()
            ->byEnvironment($environment)
            ->first();

        if ($override && $override->amount !== null) {
            return (float) $override->amount;
        }

        return (float) $this->amount;
    }

    /**
     * Current-period spend derived from {@see AIAgentRun} (authoritative), not {@see AIBudgetUsage}.
     *
     * The usage table was updated incrementally via {@see AIBudgetService::recordUsage}; if no
     * {@see AIBudget} row existed, recording never ran and the dashboard showed $0 while runs had cost.
     * Summing runs matches the AI Dashboard “Total cost” and enforces limits against real spend.
     *
     * System budget = all runs in the month (global, all tenants). Agent/task budgets = filtered runs.
     */
    public function getCurrentUsage(?string $environment = null): float
    {
        $environment = $environment ?? app()->environment();
        $periodStart = Carbon::now()->startOfMonth();
        $periodEnd = Carbon::now()->endOfMonth();

        $query = AIAgentRun::query()
            ->whereBetween('started_at', [$periodStart, $periodEnd])
            ->where(function ($q) use ($environment) {
                $q->where('environment', $environment)->orWhereNull('environment');
            });

        if ($this->budget_type === 'agent' && $this->scope_key !== null && $this->scope_key !== '') {
            $query->where('agent_id', $this->scope_key);
        } elseif ($this->budget_type === 'task_type' && $this->scope_key !== null && $this->scope_key !== '') {
            $query->where('task_type', $this->scope_key);
        }

        return (float) $query->sum('estimated_cost');
    }

    /**
     * Get remaining budget for the current period.
     */
    public function getRemaining(?string $environment = null): float
    {
        $effectiveAmount = $this->getEffectiveAmount($environment);
        $currentUsage = $this->getCurrentUsage($environment);

        return max(0, $effectiveAmount - $currentUsage);
    }

    /**
     * Check if budget is over the limit.
     */
    public function isOverBudget(?string $environment = null): bool
    {
        $effectiveAmount = $this->getEffectiveAmount($environment);
        $currentUsage = $this->getCurrentUsage($environment);

        return $currentUsage >= $effectiveAmount;
    }

    /**
     * Check if budget is near the warning threshold.
     */
    public function isNearBudget(?string $environment = null): bool
    {
        $effectiveAmount = $this->getEffectiveAmount($environment);
        $currentUsage = $this->getCurrentUsage($environment);
        $warningThreshold = $this->getEffectiveWarningThreshold($environment);

        if ($effectiveAmount <= 0) {
            return false;
        }

        $thresholdAmount = $effectiveAmount * ($warningThreshold / 100);

        return $currentUsage >= $thresholdAmount;
    }

    /**
     * Get the effective warning threshold (considering overrides).
     */
    public function getEffectiveWarningThreshold(?string $environment = null): int
    {
        $override = $this->overrides()
            ->byEnvironment($environment)
            ->first();

        if ($override && $override->warning_threshold_percent !== null) {
            return $override->warning_threshold_percent;
        }

        return $this->warning_threshold_percent;
    }

    /**
     * Check if hard limit is enabled (considering overrides).
     */
    public function isHardLimitEnabled(?string $environment = null): bool
    {
        $override = $this->overrides()
            ->byEnvironment($environment)
            ->first();

        if ($override && $override->hard_limit_enabled !== null) {
            return $override->hard_limit_enabled;
        }

        return $this->hard_limit_enabled;
    }
}
