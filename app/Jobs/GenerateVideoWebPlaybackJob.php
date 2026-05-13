<?php

namespace App\Jobs;

use App\Enums\EventType;
use App\Jobs\Concerns\AppliesQueueSafeModeMiddleware;
use App\Models\Asset;
use App\Services\ActivityRecorder;
use App\Services\VideoWebPlaybackGenerationService;
use App\Services\VideoWebPlaybackOptimizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Full-length browser-safe MP4 on the video-heavy queue (not in Bus::chain).
 */
class GenerateVideoWebPlaybackJob implements ShouldQueue
{
    use AppliesQueueSafeModeMiddleware, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $maxExceptions = 1;

    public int $timeout;

    public function __construct(
        public readonly string $assetId,
    ) {
        $this->onQueue((string) config('queue.video_heavy_queue', 'video-heavy'));
        $this->timeout = (int) config('assets.video.web_playback.job_timeout_seconds', 14_400);
    }

    public function handle(
        VideoWebPlaybackOptimizationService $optimizer,
        VideoWebPlaybackGenerationService $generator,
    ): void {
        Log::info('[GenerateVideoWebPlaybackJob] start', ['asset_id' => $this->assetId]);

        if (! (bool) config('assets.video.web_playback.enabled', false)) {
            return;
        }

        $asset = Asset::find($this->assetId);
        if (! $asset) {
            Log::warning('[GenerateVideoWebPlaybackJob] asset missing', ['asset_id' => $this->assetId]);

            return;
        }

        $fileTypeService = app(\App\Services\FileTypeService::class);
        if ($fileTypeService->detectFileTypeFromAsset($asset) !== 'video') {
            Log::info('[GenerateVideoWebPlaybackJob] skip — not video', ['asset_id' => $asset->id]);

            return;
        }

        $decision = $optimizer->decide($asset);

        if (! ($decision['should_generate'] ?? false)) {
            $reason = (string) ($decision['reason'] ?? '');
            if ($reason !== 'likely_browser_safe_or_not_forced') {
                $target = $asset->fresh() ?? $asset;
                $generator->mergeSkippedMetadata($target, $decision);
                try {
                    ActivityRecorder::logAsset($target, EventType::ASSET_VIDEO_WEB_PLAYBACK_SKIPPED, [
                        'reason' => $reason,
                        'strategy' => $decision['strategy'] ?? 'native_skipped',
                    ]);
                } catch (\Throwable) {
                }
            }

            return;
        }

        $version = $asset->currentVersion;
        $budgetSvc = app(AssetProcessingBudgetService::class);
        $budgetDecision = $budgetSvc->classify($asset, $version);
        if (! $budgetDecision->isAllowed()) {
            $budgetSvc->logGuardrail($asset, $version, $budgetDecision, 'GenerateVideoWebPlaybackJob');
            Log::info('[GenerateVideoWebPlaybackJob] skip — worker budget', [
                'asset_id' => $asset->id,
                'decision' => $budgetDecision->kind,
            ]);
            $generator->mergeSkippedMetadata($asset->fresh() ?? $asset, array_merge($decision, [
                'strategy' => 'native_skipped',
                'reason' => 'worker_processing_guardrail',
            ]));
            $this->dispatchDeferredHoverPreviewFromOriginal((string) $asset->id, GenerateVideoPreviewJob::HOVER_PREVIEW_FROM_ORIGINAL);

            return;
        }

        $asset->refresh();
        $vMeta = $asset->metadata['video'] ?? [];
        if (is_array($vMeta)
            && ($vMeta['web_playback_status'] ?? null) === 'ready'
            && ! empty($vMeta['web_playback_path'])) {
            Log::info('[GenerateVideoWebPlaybackJob] already ready', ['asset_id' => $asset->id]);
            $this->dispatchHoverPreviewAfterVideoWebIfNeeded($asset);

            return;
        }

        try {
            ActivityRecorder::logAsset($asset, EventType::ASSET_VIDEO_WEB_PLAYBACK_STARTED, [
                'queue' => $this->queue ?: 'video-heavy',
            ]);
        } catch (\Throwable) {
        }

        $fresh = $asset->fresh();
        if (! $fresh) {
            return;
        }

        $result = $generator->transcodeAndStore($fresh, $decision);
        $assetAfter = $fresh->fresh();

        if (! ($result['success'] ?? false)) {
            $err = (string) ($result['error'] ?? 'unknown');
            Log::warning('[GenerateVideoWebPlaybackJob] derivative not generated', [
                'asset_id' => $this->assetId,
                'error' => $err,
            ]);
            if ($assetAfter) {
                $generator->mergeFailedMetadata($assetAfter, $err);
            }
            $this->dispatchDeferredHoverPreviewFromOriginal(
                (string) $this->assetId,
                GenerateVideoPreviewJob::HOVER_PREVIEW_FROM_ORIGINAL_AFTER_WEB_FAILED
            );
            try {
                ActivityRecorder::logAsset($fresh, EventType::ASSET_VIDEO_WEB_PLAYBACK_FAILED, [
                    'error' => $err,
                ]);
            } catch (\Throwable) {
            }

            return;
        }

        Log::info('[GenerateVideoWebPlaybackJob] completed', [
            'asset_id' => $this->assetId,
            'path' => $result['path'] ?? null,
            'size_bytes' => $result['size_bytes'] ?? null,
        ]);
        try {
            ActivityRecorder::logAsset($fresh->fresh() ?? $fresh, EventType::ASSET_VIDEO_WEB_PLAYBACK_COMPLETED, [
                'path' => $result['path'] ?? null,
                'output_size_bytes' => (int) ($result['size_bytes'] ?? 0),
            ]);
        } catch (\Throwable) {
        }

        $this->dispatchHoverPreviewAfterVideoWebIfNeeded($fresh->fresh() ?? $fresh);
    }

    /**
     * After VIDEO_WEB is stored, run hover preview on the images queue from the derivative (not the risky original).
     */
    private function dispatchHoverPreviewAfterVideoWebIfNeeded(?Asset $asset): void
    {
        if (! $asset) {
            return;
        }
        $raw = $asset->getAttributes()['video_preview_url'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            Log::info('[GenerateVideoWebPlaybackJob] Hover preview already exists; skip GenerateVideoPreviewJob dispatch', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        Log::info('[GenerateVideoWebPlaybackJob] Dispatching hover preview from VIDEO_WEB derivative', [
            'asset_id' => $asset->id,
            'web_playback_path' => $asset->metadata['video']['web_playback_path'] ?? null,
            'preview_deferred_for_web_playback' => (bool) (($asset->metadata['video'] ?? [])['preview_deferred_for_web_playback'] ?? false),
        ]);

        GenerateVideoPreviewJob::dispatch((string) $asset->id, GenerateVideoPreviewJob::HOVER_PREVIEW_FROM_VIDEO_WEB)
            ->onQueue((string) config('queue.images_queue', 'images'));
    }

    /**
     * When hover was deferred for VIDEO_WEB but full transcode did not complete, still try the short hover clip
     * from the original (non-fatal). {@see GenerateVideoPreviewJob} records metadata.video.preview_source.
     */
    private function dispatchDeferredHoverPreviewFromOriginal(string $assetId, string $previewSourceMode): void
    {
        $asset = Asset::find($assetId);
        if (! $asset) {
            return;
        }
        $v = is_array($asset->metadata['video'] ?? null) ? $asset->metadata['video'] : [];
        if (empty($v['preview_deferred_for_web_playback'])) {
            return;
        }

        $raw = $asset->getAttributes()['video_preview_url'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            return;
        }

        Log::info('[GenerateVideoWebPlaybackJob] Dispatching deferred hover preview from original (VIDEO_WEB did not produce a derivative)', [
            'asset_id' => $assetId,
            'preview_source_mode' => $previewSourceMode,
        ]);

        GenerateVideoPreviewJob::dispatch($assetId, $previewSourceMode)
            ->onQueue((string) config('queue.images_queue', 'images'));
    }
}
