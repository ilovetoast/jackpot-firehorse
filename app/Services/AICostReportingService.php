<?php

namespace App\Services;

use App\Models\AIAgentRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AI Cost Reporting Service
 *
 * Generates cost reports from AI agent runs.
 * All reports are read-only and derived from existing data.
 */
class AICostReportingService
{
    /** @var list<string> */
    public const RANGE_PRESETS = ['24h', '7d', '14d', '30d', '90d', '6m', '12m'];

    /**
     * Generate a comprehensive cost report with filters.
     *
     * @param array $filters Filters:
     *   - range_preset: Quick window (24h|7d|14d|30d|90d|6m|12m); when set, start/end dates are ignored
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
        [$rangeStart, $rangeEnd] = $this->resolveReportRange($filters);

        $query = AIAgentRun::query()
            ->where('started_at', '>=', $rangeStart)
            ->where('started_at', '<=', $rangeEnd);

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

        // Never hydrate full rows: metadata/summary/error_message JSON can be huge — was OOM-ing admin /app/admin/ai (tab=reports) on large datasets.
        $runs = $query->select([
            'id',
            'agent_id',
            'model_used',
            'task_type',
            'triggering_context',
            'status',
            'estimated_cost',
            'tokens_in',
            'tokens_out',
            'started_at',
        ])->get();

        // Calculate metrics
        $totalRuns = $runs->count();
        $successfulRuns = $runs->where('status', 'success')->count();
        $failedRuns = $runs->where('status', 'failed')->count();
        $totalCost = $runs->sum('estimated_cost');
        $totalTokensIn = $runs->sum('tokens_in');
        $totalTokensOut = $runs->sum('tokens_out');
        $averageCostPerRun = $totalRuns > 0 ? $totalCost / $totalRuns : 0;
        $errorRate = $totalRuns > 0 ? ($failedRuns / $totalRuns) * 100 : 0;

        $rangePreset = isset($filters['range_preset']) && in_array($filters['range_preset'], self::RANGE_PRESETS, true)
            ? $filters['range_preset']
            : null;

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
            'meta' => [
                'range_preset' => $rangePreset,
                'range_start' => $rangeStart->toIso8601String(),
                'range_end' => $rangeEnd->toIso8601String(),
            ],
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
     * Resolve inclusive [start, end] bounds for reporting (preset or calendar dates).
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    protected function resolveReportRange(array $filters): array
    {
        $now = Carbon::now();
        $preset = $filters['range_preset'] ?? null;

        if (is_string($preset) && in_array($preset, self::RANGE_PRESETS, true)) {
            $end = $now->copy();

            $start = match ($preset) {
                '24h' => $end->copy()->subHours(24),
                '7d' => $end->copy()->subDays(7)->startOfDay(),
                '14d' => $end->copy()->subDays(14)->startOfDay(),
                '30d' => $end->copy()->subDays(30)->startOfDay(),
                '90d' => $end->copy()->subDays(90)->startOfDay(),
                '6m' => $end->copy()->subMonths(6)->startOfDay(),
                '12m' => $end->copy()->subMonths(12)->startOfDay(),
                default => $end->copy()->subDays(30)->startOfDay(),
            };

            return [$start, $end];
        }

        $start = isset($filters['start_date'])
            ? Carbon::parse($filters['start_date'])->startOfDay()
            : $now->copy()->subDays(30)->startOfDay();

        $end = isset($filters['end_date'])
            ? Carbon::parse($filters['end_date'])->endOfDay()
            : $now->copy()->endOfDay();

        return [$start, $end];
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

        // Admin dashboard uses day/7 — never hydrate every row (metadata JSON can exhaust 128MB+).
        if ($period === 'day') {
            return $this->getCostTrendsByDayAggregated($startDate, $endDate);
        }

        $runs = AIAgentRun::query()
            ->whereBetween('started_at', [$startDate, $endDate])
            ->select(['id', 'started_at', 'estimated_cost', 'tokens_in', 'tokens_out'])
            ->get();

        return $this->aggregateByTimeRange($runs, $period);
    }

    /**
     * SQL aggregation by calendar day — avoids loading full ai_agent_runs rows into memory.
     *
     * @return array<int, array{period: string, total_runs: int, total_cost: float, total_tokens: int}>
     */
    protected function getCostTrendsByDayAggregated(Carbon $startDate, Carbon $endDate): array
    {
        $driver = AIAgentRun::query()->getConnection()->getDriverName();

        $dayExpr = match ($driver) {
            'mysql', 'mariadb' => 'DATE(started_at)',
            'sqlite' => 'date(started_at)',
            'pgsql' => 'CAST(started_at AS date)',
            default => null,
        };

        if ($dayExpr === null) {
            $runs = AIAgentRun::query()
                ->whereBetween('started_at', [$startDate, $endDate])
                ->select(['id', 'started_at', 'estimated_cost', 'tokens_in', 'tokens_out'])
                ->get();

            return $this->aggregateByTimeRange($runs, 'day');
        }

        $rows = AIAgentRun::query()
            ->whereBetween('started_at', [$startDate, $endDate])
            ->selectRaw("{$dayExpr} as trend_period")
            ->selectRaw('COUNT(*) as trend_total_runs')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as trend_total_cost')
            ->selectRaw('COALESCE(SUM(tokens_in), 0) + COALESCE(SUM(tokens_out), 0) as trend_total_tokens')
            ->groupBy(DB::raw($dayExpr))
            ->orderBy('trend_period')
            ->get();

        return $rows->map(function ($row) {
            return [
                'period' => (string) $row->trend_period,
                'total_runs' => (int) $row->trend_total_runs,
                'total_cost' => round((float) $row->trend_total_cost, 4),
                'total_tokens' => (int) $row->trend_total_tokens,
            ];
        })->values()->all();
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

        $currentCost = (float) AIAgentRun::query()->whereBetween('started_at', [
            $currentMonth,
            $currentMonth->copy()->endOfMonth(),
        ])->sum('estimated_cost');

        $previousCost = (float) AIAgentRun::query()->whereBetween('started_at', [
            $previousMonth,
            $previousMonth->copy()->endOfMonth(),
        ])->sum('estimated_cost');

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
