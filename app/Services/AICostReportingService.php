<?php

namespace App\Services;

use App\Models\AIAgentRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AI Cost Reporting Service
 *
 * Generates cost reports from AI agent runs.
 * All reports are read-only and derived from existing data.
 */
class AICostReportingService
{
    /**
     * Generate a comprehensive cost report with filters.
     *
     * @param array $filters Filters:
     *   - start_date: Start date (Y-m-d)
     *   - end_date: End date (Y-m-d)
     *   - agent_id: Filter by agent
     *   - model_used: Filter by model
     *   - task_type: Filter by task type
     *   - triggering_context: Filter by context (system, tenant, user)
     *   - environment: Filter by environment
     * @return array Report data
     */
    public function generateReport(array $filters = []): array
    {
        $query = AIAgentRun::query();

        // Apply filters
        if (isset($filters['start_date'])) {
            $query->where('started_at', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }

        if (isset($filters['end_date'])) {
            $query->where('started_at', '<=', Carbon::parse($filters['end_date'])->endOfDay());
        }

        if (isset($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (isset($filters['model_used'])) {
            $query->where('model_used', 'like', '%' . $filters['model_used'] . '%');
        }

        if (isset($filters['task_type'])) {
            $query->where('task_type', $filters['task_type']);
        }

        if (isset($filters['triggering_context'])) {
            $query->where('triggering_context', $filters['triggering_context']);
        }

        if (isset($filters['environment'])) {
            $query->where('environment', $filters['environment']);
        }

        $runs = $query->get();

        // Calculate metrics
        $totalRuns = $runs->count();
        $successfulRuns = $runs->where('status', 'success')->count();
        $failedRuns = $runs->where('status', 'failed')->count();
        $totalCost = $runs->sum('estimated_cost');
        $totalTokensIn = $runs->sum('tokens_in');
        $totalTokensOut = $runs->sum('tokens_out');
        $averageCostPerRun = $totalRuns > 0 ? $totalCost / $totalRuns : 0;
        $errorRate = $totalRuns > 0 ? ($failedRuns / $totalRuns) * 100 : 0;

        return [
            'total_runs' => $totalRuns,
            'successful_runs' => $successfulRuns,
            'failed_runs' => $failedRuns,
            'total_cost' => round($totalCost, 4),
            'total_tokens_in' => $totalTokensIn,
            'total_tokens_out' => $totalTokensOut,
            'total_tokens' => $totalTokensIn + $totalTokensOut,
            'average_cost_per_run' => round($averageCostPerRun, 4),
            'error_rate' => round($errorRate, 2),
            'aggregations' => [
                'by_agent' => $this->aggregateByAgent($runs),
                'by_model' => $this->aggregateByModel($runs),
                'by_task_type' => $this->aggregateByTaskType($runs),
                'by_context' => $this->aggregateByContext($runs),
                'by_time_range' => $this->aggregateByTimeRange($runs, $filters['group_by'] ?? 'day'),
            ],
        ];
    }

    /**
     * Aggregate runs by agent.
     */
    public function aggregateByAgent(Collection $runs): array
    {
        return $runs->groupBy('agent_id')->map(function ($group) {
            return [
                'agent_id' => $group->first()->agent_id,
                'total_runs' => $group->count(),
                'total_cost' => round($group->sum('estimated_cost'), 4),
                'total_tokens' => $group->sum('tokens_in') + $group->sum('tokens_out'),
                'successful_runs' => $group->where('status', 'success')->count(),
                'failed_runs' => $group->where('status', 'failed')->count(),
            ];
        })->values()->toArray();
    }

    /**
     * Aggregate runs by model.
     */
    public function aggregateByModel(Collection $runs): array
    {
        return $runs->groupBy('model_used')->map(function ($group) {
            return [
                'model_used' => $group->first()->model_used,
                'total_runs' => $group->count(),
                'total_cost' => round($group->sum('estimated_cost'), 4),
                'total_tokens' => $group->sum('tokens_in') + $group->sum('tokens_out'),
                'average_cost_per_run' => round($group->avg('estimated_cost'), 4),
            ];
        })->values()->toArray();
    }

    /**
     * Aggregate runs by task type.
     */
    public function aggregateByTaskType(Collection $runs): array
    {
        return $runs->groupBy('task_type')->map(function ($group) {
            return [
                'task_type' => $group->first()->task_type,
                'total_runs' => $group->count(),
                'total_cost' => round($group->sum('estimated_cost'), 4),
                'total_tokens' => $group->sum('tokens_in') + $group->sum('tokens_out'),
            ];
        })->values()->toArray();
    }

    /**
     * Aggregate runs by triggering context.
     */
    public function aggregateByContext(Collection $runs): array
    {
        return $runs->groupBy('triggering_context')->map(function ($group) {
            return [
                'triggering_context' => $group->first()->triggering_context,
                'total_runs' => $group->count(),
                'total_cost' => round($group->sum('estimated_cost'), 4),
                'total_tokens' => $group->sum('tokens_in') + $group->sum('tokens_out'),
            ];
        })->values()->toArray();
    }

    /**
     * Aggregate runs by time range.
     *
     * @param Collection $runs
     * @param string $groupBy 'day', 'week', or 'month'
     * @return array
     */
    public function aggregateByTimeRange(Collection $runs, string $groupBy = 'day'): array
    {
        $grouped = $runs->groupBy(function ($run) use ($groupBy) {
            $date = Carbon::parse($run->started_at);

            return match ($groupBy) {
                'day' => $date->format('Y-m-d'),
                'week' => $date->format('Y-W'),
                'month' => $date->format('Y-m'),
                default => $date->format('Y-m-d'),
            };
        });

        return $grouped->map(function ($group, $key) {
            return [
                'period' => $key,
                'total_runs' => $group->count(),
                'total_cost' => round($group->sum('estimated_cost'), 4),
                'total_tokens' => $group->sum('tokens_in') + $group->sum('tokens_out'),
            ];
        })->values()->toArray();
    }

    /**
     * Get cost trends for a period.
     *
     * @param string $period 'day', 'week', or 'month'
     * @param int $limit Number of periods to return
     * @return array
     */
    public function getCostTrends(string $period = 'day', int $limit = 30): array
    {
        $endDate = Carbon::now();
        $startDate = match ($period) {
            'day' => $endDate->copy()->subDays($limit),
            'week' => $endDate->copy()->subWeeks($limit),
            'month' => $endDate->copy()->subMonths($limit),
            default => $endDate->copy()->subDays($limit),
        };

        $runs = AIAgentRun::whereBetween('started_at', [$startDate, $endDate])
            ->get();

        return $this->aggregateByTimeRange($runs, $period);
    }

    /**
     * Detect cost spikes (unusual cost increases).
     *
     * @param float $threshold Percentage increase threshold (default 50%)
     * @return array Array of detected spikes
     */
    public function detectCostSpikes(float $threshold = 50.0): array
    {
        $currentMonth = Carbon::now()->startOfMonth();
        $previousMonth = $currentMonth->copy()->subMonth();

        $currentRuns = AIAgentRun::whereBetween('started_at', [
            $currentMonth,
            $currentMonth->copy()->endOfMonth(),
        ])->get();

        $previousRuns = AIAgentRun::whereBetween('started_at', [
            $previousMonth,
            $previousMonth->copy()->endOfMonth(),
        ])->get();

        $currentCost = $currentRuns->sum('estimated_cost');
        $previousCost = $previousRuns->sum('estimated_cost');

        if ($previousCost == 0) {
            return []; // No previous data to compare
        }

        $percentIncrease = (($currentCost - $previousCost) / $previousCost) * 100;

        if ($percentIncrease > $threshold) {
            return [
                [
                    'period' => $currentMonth->format('Y-m'),
                    'current_cost' => round($currentCost, 4),
                    'previous_cost' => round($previousCost, 4),
                    'percent_increase' => round($percentIncrease, 2),
                    'threshold' => $threshold,
                ],
            ];
        }

        return [];
    }
}
