<?php

namespace App\Jobs;

use App\Services\EventAggregationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 4 Step 2 — Event Aggregation Job
 * 
 * Consumes events from locked phases only.
 * Must not modify event producers.
 * 
 * AggregateEventsJob
 * 
 * Batch job that aggregates raw activity events into time-bucketed aggregates.
 * Processes events in time windows and populates aggregation tables.
 * 
 * Idempotency:
 * - Uses upsert logic based on unique constraints
 * - Safe to re-run on the same time window
 * - Tracks last processed timestamp via cache
 */
class AggregateEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [60, 300, 900]; // 1 minute, 5 minutes, 15 minutes

    /**
     * Default time window to process (minutes).
     * Processes events from (now - window) to now.
     */
    public const DEFAULT_WINDOW_MINUTES = 15;

    /**
     * Cache key for tracking last processed timestamp.
     */
    public const LAST_PROCESSED_CACHE_KEY = 'event_aggregation:last_processed_at';

    /**
     * Cache TTL for last processed timestamp (24 hours).
     */
    public const LAST_PROCESSED_CACHE_TTL = 86400; // 24 hours

    /**
     * Create a new job instance.
     * 
     * @param Carbon|null $startAt Start of time window (defaults to last processed or DEFAULT_WINDOW_MINUTES ago)
     * @param Carbon|null $endAt End of time window (defaults to now)
     */
    public function __construct(
        public readonly ?Carbon $startAt = null,
        public readonly ?Carbon $endAt = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EventAggregationService $service): void
    {
        Log::info('[AggregateEventsJob] Job started', [
            'start_at' => $this->startAt?->toIso8601String(),
            'end_at' => $this->endAt?->toIso8601String(),
        ]);

        // Determine time window
        $endAt = $this->endAt ?: Carbon::now();
        $startAt = $this->startAt ?: $this->getLastProcessedTimestamp($endAt);

        // Ensure we don't process future events
        if ($endAt->isFuture()) {
            $endAt = Carbon::now();
        }

        // Ensure start is before end
        if ($startAt->gte($endAt)) {
            Log::info('[AggregateEventsJob] No events to process', [
                'start_at' => $startAt->toIso8601String(),
                'end_at' => $endAt->toIso8601String(),
            ]);
            return;
        }

        try {
            // Aggregate events in time window
            $stats = $service->aggregateTimeWindow($startAt, $endAt);

            // Update last processed timestamp
            $this->setLastProcessedTimestamp($endAt);

            Log::info('[AggregateEventsJob] Job completed successfully', [
                'start_at' => $startAt->toIso8601String(),
                'end_at' => $endAt->toIso8601String(),
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::error('[AggregateEventsJob] Job failed', [
                'start_at' => $startAt->toIso8601String(),
                'end_at' => $endAt->toIso8601String(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get last processed timestamp from cache.
     * Falls back to DEFAULT_WINDOW_MINUTES ago if not found.
     * 
     * @param Carbon $endAt End of current window
     * @return Carbon
     */
    protected function getLastProcessedTimestamp(Carbon $endAt): Carbon
    {
        $lastProcessed = Cache::get(self::LAST_PROCESSED_CACHE_KEY);

        if ($lastProcessed) {
            try {
                return Carbon::parse($lastProcessed);
            } catch (\Exception $e) {
                Log::warning('[AggregateEventsJob] Invalid last processed timestamp in cache', [
                    'value' => $lastProcessed,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Default: process last DEFAULT_WINDOW_MINUTES
        return $endAt->copy()->subMinutes(self::DEFAULT_WINDOW_MINUTES);
    }

    /**
     * Set last processed timestamp in cache.
     * 
     * @param Carbon $timestamp
     * @return void
     */
    protected function setLastProcessedTimestamp(Carbon $timestamp): void
    {
        Cache::put(
            self::LAST_PROCESSED_CACHE_KEY,
            $timestamp->toIso8601String(),
            self::LAST_PROCESSED_CACHE_TTL
        );
    }

    /**
     * Handle job failure.
     * 
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[AggregateEventsJob] Job failed permanently', [
            'start_at' => $this->startAt?->toIso8601String(),
            'end_at' => $this->endAt?->toIso8601String(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
