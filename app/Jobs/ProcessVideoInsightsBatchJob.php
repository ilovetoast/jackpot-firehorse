<?php

namespace App\Jobs;

use App\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches {@see GenerateVideoInsightsJob} in chunks with delays to avoid queue spikes.
 *
 * @param  list<string>  $assetIds
 */
class ProcessVideoInsightsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly array $assetIds
    ) {
        $this->onQueue(config('queue.default'));
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $ids = array_values(array_filter(array_map('strval', $this->assetIds)));
        if ($ids === []) {
            return;
        }

        if (! config('assets.video_ai.enabled', true)) {
            Log::info('[ProcessVideoInsightsBatchJob] Skipped — video AI disabled', ['count' => count($ids)]);
            foreach ($ids as $assetId) {
                $asset = Asset::find($assetId);
                if ($asset === null) {
                    continue;
                }
                $meta = $asset->metadata ?? [];
                if (in_array($meta['ai_video_status'] ?? null, ['queued', 'processing'], true)) {
                    $meta['ai_video_status'] = 'skipped';
                    $meta['ai_video_insights_skip_reason'] = 'video_ai_disabled';
                    $meta['ai_video_insights_skipped_at'] = now()->toIso8601String();
                    $asset->update(['metadata' => $meta]);
                }
            }

            return;
        }

        $size = max(1, (int) config('assets.video_ai.batch_size', 5));
        $delaySeconds = max(0, (int) config('assets.video_ai.batch_delay_seconds', 2));
        $targetQueue = (string) config('assets.video_ai.queue', config('queue.ai_low_queue', 'ai-low'));

        foreach (array_chunk($ids, $size) as $batchIndex => $chunk) {
            $batchDelay = $batchIndex * $delaySeconds;
            foreach ($chunk as $assetId) {
                $pending = GenerateVideoInsightsJob::dispatch($assetId)->onQueue($targetQueue);
                if ($batchDelay > 0) {
                    $pending->delay(now()->addSeconds($batchDelay));
                }
            }
        }
    }
}
