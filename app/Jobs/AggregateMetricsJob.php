<?php

namespace App\Jobs;

use App\Services\MetricAggregationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AggregateMetricsJob
 * 
 * Scheduled job to aggregate asset metrics into periodic aggregates.
 * Runs daily to aggregate the previous day's metrics.
 */
class AggregateMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     * 
     * Aggregates previous day's metrics into daily aggregates.
     * Also aggregates weekly and monthly metrics if applicable.
     */
    public function handle(MetricAggregationService $aggregationService): void
    {
        try {
            Log::info('[AggregateMetricsJob] Starting metric aggregation');

            // Aggregate previous day's metrics into daily aggregates
            $aggregationService->aggregateDaily();

            // Check if we should aggregate weekly (if it's Monday, aggregate last week)
            $today = now();
            if ($today->isMonday()) {
                $aggregationService->aggregateWeekly();
            }

            // Check if we should aggregate monthly (if it's the 1st of the month, aggregate last month)
            if ($today->day === 1) {
                $aggregationService->aggregateMonthly();
            }

            Log::info('[AggregateMetricsJob] Metric aggregation completed successfully');
        } catch (\Exception $e) {
            Log::error('[AggregateMetricsJob] Failed to aggregate metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw to mark job as failed
            throw $e;
        }
    }
}
