<?php

namespace App\Jobs;

use App\Enums\EventType;
use App\Jobs\Concerns\AppliesQueueSafeModeMiddleware;
use App\Models\Asset;
use App\Services\ActivityRecorder;
use App\Services\Audio\AudioPlaybackOptimizationService;
use App\Support\AudioPipelineQueueResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Transcode an audio asset's source bytes to a 128 kbps stereo MP3 derivative
 * for browser playback. Heavy step, routed to its own queue:
 *
 *   - small/medium audio  -> queue.audio_queue        (default)
 *   - source >= heavy_min -> queue.audio_heavy_queue  (longer worker timeout)
 *
 * The decision of *whether* to transcode lives on
 * {@see AudioPlaybackOptimizationService::decideStrategy()} so it can be
 * unit-tested without spinning up FFmpeg. This job is just the queue wrapper.
 *
 * Failures are non-blocking: if FFmpeg is missing or the transcode fails,
 * the AssetController falls back to streaming the original file directly.
 */
class GenerateAudioWebPlaybackJob implements ShouldQueue
{
    use AppliesQueueSafeModeMiddleware, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 1;

    public int $timeout;

    public function __construct(
        public readonly string $assetId,
    ) {
        $sizeBytes = $this->resolveAssetSizeForRouting($assetId);
        $queue = AudioPipelineQueueResolver::forByteSize($sizeBytes);
        $this->onQueue($queue);

        $heavyMin = (int) config('assets.audio.heavy_queue_min_bytes', 100 * 1024 * 1024);
        $this->timeout = ($heavyMin > 0 && $sizeBytes >= $heavyMin)
            ? (int) config('assets.audio.web_playback_job_timeout_heavy_seconds', 1780)
            : (int) config('assets.audio.web_playback_job_timeout_seconds', 900);
    }

    public function handle(AudioPlaybackOptimizationService $service): void
    {
        Log::info('[GenerateAudioWebPlaybackJob] start', ['asset_id' => $this->assetId]);

        $asset = Asset::find($this->assetId);
        if (! $asset) {
            Log::warning('[GenerateAudioWebPlaybackJob] asset missing', ['asset_id' => $this->assetId]);

            return;
        }

        $fileTypeService = app(\App\Services\FileTypeService::class);
        if ($fileTypeService->detectFileTypeFromAsset($asset) !== 'audio') {
            Log::info('[GenerateAudioWebPlaybackJob] skip — not audio', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        // Timeline: started — surfaced in the asset drawer Timeline + the
        // Activity tab so operators can confirm the transcode actually
        // queued and which lane (audio_queue vs audio_heavy_queue) handled it.
        ActivityRecorder::logAsset($asset, EventType::ASSET_AUDIO_WEB_PLAYBACK_STARTED, [
            'queue' => $this->queue ?: 'audio',
            'source_size_bytes' => (int) ($asset->size_bytes ?? 0),
        ]);

        $result = $service->generateForAsset($asset);
        if (! ($result['success'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'unknown');
            Log::warning('[GenerateAudioWebPlaybackJob] derivative not generated', [
                'asset_id' => $asset->id,
                'reason' => $reason,
            ]);
            ActivityRecorder::logAsset($asset, EventType::ASSET_AUDIO_WEB_PLAYBACK_FAILED, [
                'reason' => $reason,
                'error' => $result['error'] ?? null,
            ]);

            return;
        }

        if ($result['skipped'] ?? false) {
            $reason = (string) ($result['reason'] ?? 'unknown');
            Log::info('[GenerateAudioWebPlaybackJob] skipped — original is browser-friendly', [
                'asset_id' => $asset->id,
                'reason' => $reason,
            ]);
            ActivityRecorder::logAsset($asset, EventType::ASSET_AUDIO_WEB_PLAYBACK_SKIPPED, [
                'reason' => $reason,
            ]);

            return;
        }

        Log::info('[GenerateAudioWebPlaybackJob] derivative generated', [
            'asset_id' => $asset->id,
            'path' => $result['path'] ?? null,
            'size_bytes' => $result['size_bytes'] ?? null,
            'reason' => $result['reason'] ?? null,
        ]);
        ActivityRecorder::logAsset($asset, EventType::ASSET_AUDIO_WEB_PLAYBACK_COMPLETED, [
            'reason' => $result['reason'] ?? null,
            'output_size_bytes' => (int) ($result['size_bytes'] ?? 0),
            'bitrate_kbps' => (int) ($result['bitrate_kbps'] ?? 0),
            'codec' => 'mp3',
        ]);
    }

    /**
     * Resolve the source byte size *without* hydrating the model fully — the
     * constructor must run cheaply because it's invoked at dispatch time.
     */
    protected function resolveAssetSizeForRouting(string $assetId): int
    {
        try {
            /** @var Asset|null $asset */
            $asset = Asset::query()->select(['id', 'size_bytes'])->find($assetId);

            return (int) ($asset?->size_bytes ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
