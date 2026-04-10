<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
