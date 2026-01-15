<?php

namespace App\Services;

use App\Enums\MetricType;
use App\Models\AssetMetric;
use App\Models\MetricAggregate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MetricAggregationService
 * 
 * Service for aggregating individual asset metrics into periodic aggregates.
 * Runs as scheduled job to reduce query load on individual metrics table.
 */
class MetricAggregationService
{
    /**
     * Aggregate daily metrics for a specific date.
     * 
     * Aggregates all metrics from the given date (00:00:00 to 23:59:59)
     * into daily aggregate records.
     * 
     * @param Carbon|null $date Date to aggregate (defaults to previous day)
     * @return void
     */
    public function aggregateDaily(?Carbon $date = null): void
    {
        // Default to previous day
        if (!$date) {
            $date = Carbon::yesterday();
        }

        $periodStart = $date->copy()->startOfDay();
        $periodEnd = $date->copy()->endOfDay();

        Log::info('[MetricAggregationService] Starting daily aggregation', [
            'period_start' => $periodStart->toDateTimeString(),
            'period_end' => $periodEnd->toDateTimeString(),
        ]);

        // Get all metrics for this date
        $metrics = AssetMetric::whereBetween('created_at', [$periodStart, $periodEnd])
            ->get()
            ->groupBy(function ($metric) {
                return $metric->asset_id . '|' . $metric->metric_type->value;
            });

        $aggregated = 0;

        foreach ($metrics as $key => $assetMetrics) {
            // Parse grouping key (asset_id and metric_type)
            [$assetId, $metricTypeValue] = explode('|', $key, 2);
            
            $firstMetric = $assetMetrics->first();
            if (!$firstMetric) {
                continue;
            }

            $metricTypeEnum = MetricType::from($metricTypeValue);
            
            // Calculate aggregates
            $count = $assetMetrics->count();
            $uniqueUsers = $assetMetrics->whereNotNull('user_id')
                ->pluck('user_id')
                ->unique()
                ->count();

            // Update or create aggregate record
            MetricAggregate::updateOrCreate(
                [
                    'asset_id' => $assetId,
                    'metric_type' => $metricTypeEnum,
                    'period' => 'daily',
                    'period_start' => $periodStart->format('Y-m-d'),
                ],
                [
                    'tenant_id' => $firstMetric->tenant_id,
                    'brand_id' => $firstMetric->brand_id,
                    'count' => $count,
                    'unique_users' => $uniqueUsers,
                ]
            );

            $aggregated++;
        }

        Log::info('[MetricAggregationService] Daily aggregation completed', [
            'period_start' => $periodStart->toDateTimeString(),
            'aggregated_groups' => $aggregated,
        ]);
    }

    /**
     * Aggregate weekly metrics for a specific week.
     * 
     * Aggregates all metrics from the week starting at $weekStart
     * into weekly aggregate records.
     * 
     * @param Carbon|null $weekStart Week start date (defaults to previous week start)
     * @return void
     */
    public function aggregateWeekly(?Carbon $weekStart = null): void
    {
        // Default to previous week start (Monday)
        if (!$weekStart) {
            $weekStart = Carbon::now()->subWeek()->startOfWeek();
        } else {
            $weekStart = $weekStart->copy()->startOfWeek();
        }

        $periodEnd = $weekStart->copy()->endOfWeek();

        Log::info('[MetricAggregationService] Starting weekly aggregation', [
            'period_start' => $weekStart->toDateTimeString(),
            'period_end' => $periodEnd->toDateTimeString(),
        ]);

        // Get all metrics for this week
        $metrics = AssetMetric::whereBetween('created_at', [$weekStart, $periodEnd])
            ->get()
            ->groupBy(function ($metric) {
                return $metric->asset_id . '|' . $metric->metric_type->value;
            });

        $aggregated = 0;

        foreach ($metrics as $key => $assetMetrics) {
            [$assetId, $metricTypeValue] = explode('|', $key, 2);
            
            $firstMetric = $assetMetrics->first();
            if (!$firstMetric) {
                continue;
            }

            $metricTypeEnum = MetricType::from($metricTypeValue);
            
            // Calculate aggregates
            $count = $assetMetrics->count();
            $uniqueUsers = $assetMetrics->whereNotNull('user_id')
                ->pluck('user_id')
                ->unique()
                ->count();

            // Update or create aggregate record
            MetricAggregate::updateOrCreate(
                [
                    'asset_id' => $assetId,
                    'metric_type' => $metricTypeEnum,
                    'period' => 'weekly',
                    'period_start' => $weekStart->format('Y-m-d'),
                ],
                [
                    'tenant_id' => $firstMetric->tenant_id,
                    'brand_id' => $firstMetric->brand_id,
                    'count' => $count,
                    'unique_users' => $uniqueUsers,
                ]
            );

            $aggregated++;
        }

        Log::info('[MetricAggregationService] Weekly aggregation completed', [
            'period_start' => $weekStart->toDateTimeString(),
            'aggregated_groups' => $aggregated,
        ]);
    }

    /**
     * Aggregate monthly metrics for a specific month.
     * 
     * Aggregates all metrics from the month starting at $monthStart
     * into monthly aggregate records.
     * 
     * @param Carbon|null $monthStart Month start date (defaults to previous month start)
     * @return void
     */
    public function aggregateMonthly(?Carbon $monthStart = null): void
    {
        // Default to previous month start
        if (!$monthStart) {
            $monthStart = Carbon::now()->subMonth()->startOfMonth();
        } else {
            $monthStart = $monthStart->copy()->startOfMonth();
        }

        $periodEnd = $monthStart->copy()->endOfMonth();

        Log::info('[MetricAggregationService] Starting monthly aggregation', [
            'period_start' => $monthStart->toDateTimeString(),
            'period_end' => $periodEnd->toDateTimeString(),
        ]);

        // Get all metrics for this month
        $metrics = AssetMetric::whereBetween('created_at', [$monthStart, $periodEnd])
            ->get()
            ->groupBy(function ($metric) {
                return $metric->asset_id . '|' . $metric->metric_type->value;
            });

        $aggregated = 0;

        foreach ($metrics as $key => $assetMetrics) {
            [$assetId, $metricTypeValue] = explode('|', $key, 2);
            
            $firstMetric = $assetMetrics->first();
            if (!$firstMetric) {
                continue;
            }

            $metricTypeEnum = MetricType::from($metricTypeValue);
            
            // Calculate aggregates
            $count = $assetMetrics->count();
            $uniqueUsers = $assetMetrics->whereNotNull('user_id')
                ->pluck('user_id')
                ->unique()
                ->count();

            // Update or create aggregate record
            MetricAggregate::updateOrCreate(
                [
                    'asset_id' => $assetId,
                    'metric_type' => $metricTypeEnum,
                    'period' => 'monthly',
                    'period_start' => $monthStart->format('Y-m-d'),
                ],
                [
                    'tenant_id' => $firstMetric->tenant_id,
                    'brand_id' => $firstMetric->brand_id,
                    'count' => $count,
                    'unique_users' => $uniqueUsers,
                ]
            );

            $aggregated++;
        }

        Log::info('[MetricAggregationService] Monthly aggregation completed', [
            'period_start' => $monthStart->toDateTimeString(),
            'aggregated_groups' => $aggregated,
        ]);
    }
}
