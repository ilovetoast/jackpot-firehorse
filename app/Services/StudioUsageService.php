<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Roll up Studio (composition editor) events into one row per tenant × day × metric.
 *
 * - event_count: number of operations (creates, batch items, manual checkpoints).
 * - sum_duration_ms: optional client-reported time (e.g. session open → save); avg = sum / count when count > 0.
 * - sum_cost_usd: reserved for future attribution (e.g. sum of generative edits on that save); usually 0 in v1.
 *
 * AI dollar costs for image edits remain in {@see AiUsageService} / ai_usage / ai_agent_runs.
 */
class StudioUsageService
{
    public const METRIC_COMPOSITION_CREATE = 'composition_create';

    public const METRIC_COMPOSITION_BATCH = 'composition_batch';

    public const METRIC_MANUAL_CHECKPOINT = 'composition_manual_checkpoint';

    /**
     * @param  int  $count  Usually 1; for batch, number of compositions created.
     * @param  int  $durationMs  Client-reported duration (ms), 0 if unknown.
     * @param  float  $costUsd  Optional attributed provider cost for this event.
     */
    public function record(
        Tenant $tenant,
        string $metric,
        int $count = 1,
        int $durationMs = 0,
        float $costUsd = 0.0,
    ): void {
        if ($count < 1) {
            return;
        }
        $metric = $this->normalizeMetric($metric);
        if ($metric === '') {
            return;
        }

        $durationMs = max(0, min($durationMs, 172_800_000)); // cap 48h — ignore pathological values
        $today = now()->toDateString();

        DB::transaction(function () use ($tenant, $metric, $count, $durationMs, $costUsd, $today): void {
            $existing = DB::table('studio_usage_daily')
                ->where('tenant_id', $tenant->id)
                ->where('usage_date', $today)
                ->where('metric', $metric)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                DB::table('studio_usage_daily')
                    ->where('id', $existing->id)
                    ->update([
                        'event_count' => (int) $existing->event_count + $count,
                        'sum_duration_ms' => (int) $existing->sum_duration_ms + $durationMs,
                        'sum_cost_usd' => round((float) $existing->sum_cost_usd + $costUsd, 6),
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('studio_usage_daily')->insert([
                    'tenant_id' => $tenant->id,
                    'usage_date' => $today,
                    'metric' => $metric,
                    'event_count' => $count,
                    'sum_duration_ms' => $durationMs,
                    'sum_cost_usd' => round($costUsd, 6),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function normalizeMetric(string $metric): string
    {
        $metric = strtolower(trim($metric));
        $allowed = [
            self::METRIC_COMPOSITION_CREATE,
            self::METRIC_COMPOSITION_BATCH,
            self::METRIC_MANUAL_CHECKPOINT,
        ];

        return in_array($metric, $allowed, true) ? $metric : '';
    }
}
