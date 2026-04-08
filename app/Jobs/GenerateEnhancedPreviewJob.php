<?php

namespace App\Jobs;

use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\PrintLayoutCropService;
use App\Services\TemplateRenderer;
use App\Services\ThumbnailEnhancementAiTaskRecorder;
use App\Services\ThumbnailGenerationService;
use App\Support\EnhancedPreviewFingerprint;
use App\Support\ThumbnailMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Async enhanced (template-composited) previews. Never auto-dispatched — drawer or API only.
 *
 * @see \App\Services\TemplateRenderer
 * @see \App\Services\ThumbnailGenerationService::generateEnhancedPreviewsFromLocalRaster()
 */
class GenerateEnhancedPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public int $timeout;

    public function __construct(
        public readonly string $assetId,
        public readonly string $versionId,
        public readonly bool $force = false,
        public readonly bool $debugBboxOverlay = false,
    ) {
        $this->timeout = max(120, (int) config('assets.thumbnail.job_timeout_seconds', 900));
    }

    public function handle(
        ThumbnailGenerationService $thumbnailService,
        TemplateRenderer $templateRenderer,
        ThumbnailEnhancementAiTaskRecorder $aiRecorder,
    ): void {
        $asset = Asset::query()
            ->with(['storageBucket', 'tenant', 'category', 'currentVersion'])
            ->find($this->assetId);
        $version = AssetVersion::find($this->versionId);

        if (! $asset || ! $version || $version->asset_id !== $asset->id) {
            Log::info('[GenerateEnhancedPreviewJob] Missing asset/version, skipping', [
                'asset_id' => $this->assetId,
                'version_id' => $this->versionId,
            ]);

            return;
        }

        $meta = $version->metadata ?? [];
        if (! is_array($meta)) {
            $meta = [];
        }

        $modesStatus = is_array($meta['thumbnail_modes_status'] ?? null) ? $meta['thumbnail_modes_status'] : [];
        $modesMeta = is_array($meta['thumbnail_modes_meta'] ?? null) ? $meta['thumbnail_modes_meta'] : [];
        $enhMeta = is_array($modesMeta['enhanced'] ?? null) ? $modesMeta['enhanced'] : [];

        $attempts = (int) ($enhMeta['attempts'] ?? 0);
        $lastAttemptAt = isset($enhMeta['last_attempt_at']) ? strtotime((string) $enhMeta['last_attempt_at']) : 0;
        $maxAttempts = max(1, (int) config('enhanced_preview.max_attempts', 2));
        $cooldown = max(0, (int) config('enhanced_preview.cooldown_seconds', 60));

        [$sourcePath, $sourceMode] = EnhancedPreviewFingerprint::resolveEnhancedSource($meta);
        if ($sourcePath === null || $sourcePath === '') {
            $this->persistTerminal(
                $asset,
                $version,
                'skipped',
                'No preferred or original thumbnail to use as enhanced source'
            );

            return;
        }

        $enhancedStatus = (string) ($modesStatus['enhanced'] ?? '');

        if (! $this->force
            && $enhancedStatus === 'skipped'
            && ($enhMeta['skip_reason'] ?? '') === ThumbnailEnhancementAiTaskRecorder::SKIP_REASON_TOO_SMALL) {
            Log::info('[GenerateEnhancedPreviewJob] Skipping: enhanced previously skipped (source too small); use force with tenant admin', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        $stillFresh = $enhancedStatus === 'complete'
            && EnhancedPreviewFingerprint::isCompleteOutputStillFresh(
                $asset,
                $meta,
                $enhMeta,
                $templateRenderer,
                $thumbnailService
            );

        if (! $this->force && $stillFresh) {
            Log::info('[GenerateEnhancedPreviewJob] Skipping: enhanced output still matches source + template', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        if (! $this->force && $enhancedStatus !== 'complete') {
            if ($attempts >= $maxAttempts) {
                Log::info('[GenerateEnhancedPreviewJob] Skipping: max attempts reached', [
                    'asset_id' => $asset->id,
                    'attempts' => $attempts,
                ]);

                return;
            }
            if ($lastAttemptAt > 0 && (time() - $lastAttemptAt) < $cooldown) {
                Log::info('[GenerateEnhancedPreviewJob] Skipping: cooldown', [
                    'asset_id' => $asset->id,
                ]);

                return;
            }
        }

        $attemptsNext = $attempts + 1;
        $this->markProcessing($asset, $version, $attemptsNext);

        $bucket = $asset->storageBucket;
        if (! $bucket) {
            $this->persistTerminal($asset, $version, 'failed', 'Asset missing storage bucket');

            return;
        }

        $templateId = $templateRenderer->selectTemplateForAsset($asset);
        $templateVersion = EnhancedPreviewFingerprint::templateVersionFor($templateId);
        $aiRun = $aiRecorder->start($asset, $version, $sourceMode, $templateId, [
            'template_version' => $templateVersion,
            'attempt' => $attemptsNext,
        ]);
        $this->mergeEnhancedMetaPatch($asset, $version, ['ai_task_id' => $aiRun->id]);

        $t0 = microtime(true);

        $localRaster = null;
        try {
            $localRaster = $thumbnailService->downloadObjectToTemp($bucket, $sourcePath, $asset->id);
        } catch (Throwable $e) {
            Log::warning('[GenerateEnhancedPreviewJob] Source download failed', [
                'asset_id' => $asset->id,
                'path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            $this->persistTerminal($asset, $version, 'failed', 'Failed to download source thumbnail: '.$e->getMessage(), $aiRun);

            return;
        }

        if ($localRaster === null || ! is_file($localRaster) || filesize($localRaster) === 0) {
            $this->persistTerminal($asset, $version, 'failed', 'Downloaded source thumbnail is empty', $aiRun);

            return;
        }

        $sourceDims = @getimagesize($localRaster);
        if ($sourceDims === false || ($sourceDims[0] ?? 0) < 400 || ($sourceDims[1] ?? 0) < 400) {
            $this->persistTerminal(
                $asset,
                $version,
                'skipped',
                'Source too small for enhanced preview (minimum 400×400 pixels).',
                $aiRun,
                ThumbnailEnhancementAiTaskRecorder::SKIP_REASON_TOO_SMALL
            );

            return;
        }

        $inputHash = EnhancedPreviewFingerprint::computeInputHash($thumbnailService, $bucket, $sourcePath);
        $aiRecorder->mergeMetadata($aiRun, ['input_hash' => $inputHash]);

        $rasterForGen = $localRaster;
        $overlayTemp = null;
        if ($this->debugBboxOverlay) {
            $overlayTemp = app(PrintLayoutCropService::class)->renderFullImageWithBboxOverlayPng($localRaster);
            if ($overlayTemp !== null && is_file($overlayTemp)) {
                $rasterForGen = $overlayTemp;
                Log::info('[GenerateEnhancedPreviewJob] Using source raster with red print-bbox overlay for enhanced preview', [
                    'asset_id' => $asset->id,
                ]);
            } else {
                Log::warning('[GenerateEnhancedPreviewJob] debug_bbox requested but bbox overlay could not be rendered; using plain source', [
                    'asset_id' => $asset->id,
                ]);
            }
        }

        try {
            $result = $thumbnailService->generateEnhancedPreviewsFromLocalRaster(
                $asset,
                $version,
                $rasterForGen,
                $templateId,
                $sourceMode
            );
        } catch (Throwable $e) {
            Log::warning('[GenerateEnhancedPreviewJob] Generation failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $this->persistTerminal($asset, $version, 'failed', $e->getMessage(), $aiRun);

            return;
        } finally {
            if ($overlayTemp !== null && is_string($overlayTemp) && is_file($overlayTemp) && $overlayTemp !== $localRaster) {
                @unlink($overlayTemp);
            }
            if (is_string($localRaster) && is_file($localRaster)) {
                @unlink($localRaster);
            }
        }

        $durationMs = (int) round((microtime(true) - $t0) * 1000);
        $aiRun->refresh();
        $aiRecorder->succeed($aiRun, $durationMs);

        $this->mergeSuccess(
            $asset,
            $version,
            $result,
            $templateId,
            $templateVersion,
            $sourceMode,
            $inputHash,
            $attemptsNext,
            $aiRun->id,
            $this->debugBboxOverlay
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function mergeSuccess(
        Asset $asset,
        AssetVersion $version,
        array $result,
        string $templateId,
        string $templateVersion,
        string $sourceMode,
        string $inputHash,
        int $attemptsDone,
        int $aiTaskId,
        bool $debugBboxOverlay = false,
    ): void {
        $mode = ThumbnailMode::Enhanced->value;
        $finalThumbnails = $result['thumbnails'][$mode] ?? [];
        $thumbnailDimensions = $result['thumbnail_dimensions'][$mode] ?? [];

        $merge = function (array $base) use ($mode, $finalThumbnails, $thumbnailDimensions, $templateId, $templateVersion, $sourceMode, $inputHash, $attemptsDone, $aiTaskId, $debugBboxOverlay): array {
            $thumbs = $base['thumbnails'] ?? [];
            if (! is_array($thumbs)) {
                $thumbs = [];
            }
            $thumbs[$mode] = $finalThumbnails;
            $base['thumbnails'] = $thumbs;

            $dims = $base['thumbnail_dimensions'] ?? [];
            if (! is_array($dims)) {
                $dims = [];
            }
            $dims[$mode] = $thumbnailDimensions;
            $base['thumbnail_dimensions'] = $dims;

            $st = $base['thumbnail_modes_status'] ?? [];
            if (! is_array($st)) {
                $st = [];
            }
            $st['enhanced'] = 'complete';
            $base['thumbnail_modes_status'] = $st;

            $mm = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($mm)) {
                $mm = [];
            }
            $prev = is_array($mm['enhanced'] ?? null) ? $mm['enhanced'] : [];
            unset($prev['failure_message'], $prev['failed_at'], $prev['skip_reason']);
            $mm['enhanced'] = array_merge($prev, [
                'template' => $templateId,
                'template_version' => $templateVersion,
                'source_mode' => $sourceMode,
                'input_hash' => $inputHash,
                'ai_task_id' => $aiTaskId,
                'attempts' => $attemptsDone,
                'last_attempt_at' => now()->toIso8601String(),
                'debug_bbox_overlay' => $debugBboxOverlay,
            ]);
            $base['thumbnail_modes_meta'] = $mm;

            return $base;
        };

        $version->refresh();
        $version->update(['metadata' => $merge($version->metadata ?? [])]);
        $asset->refresh();
        $asset->update(['metadata' => $merge($asset->metadata ?? [])]);

        Log::info('[GenerateEnhancedPreviewJob] Enhanced previews complete', [
            'asset_id' => $asset->id,
            'template' => $templateId,
            'source_mode' => $sourceMode,
        ]);
    }

    protected function markProcessing(Asset $asset, AssetVersion $version, int $attempts): void
    {
        $merge = function (array $base) use ($attempts): array {
            $st = $base['thumbnail_modes_status'] ?? [];
            if (! is_array($st)) {
                $st = [];
            }
            $st['enhanced'] = 'processing';
            $base['thumbnail_modes_status'] = $st;

            $mm = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($mm)) {
                $mm = [];
            }
            $prev = is_array($mm['enhanced'] ?? null) ? $mm['enhanced'] : [];
            unset($prev['failure_message'], $prev['failed_at'], $prev['skip_reason']);
            $mm['enhanced'] = array_merge($prev, [
                'attempts' => $attempts,
                'last_attempt_at' => now()->toIso8601String(),
            ]);
            $base['thumbnail_modes_meta'] = $mm;

            return $base;
        };

        $version->refresh();
        $version->update(['metadata' => $merge($version->metadata ?? [])]);
        $asset->refresh();
        $asset->update(['metadata' => $merge($asset->metadata ?? [])]);
    }

    /**
     * @param  string|null  $aiSkipReason  When set with {@see $aiRun}, records AI run as skipped (guardrail); otherwise failed.
     */
    protected function persistTerminal(
        Asset $asset,
        AssetVersion $version,
        string $status,
        string $message,
        ?AIAgentRun $aiRun = null,
        ?string $aiSkipReason = null
    ): void {
        if ($aiRun !== null) {
            $recorder = app(ThumbnailEnhancementAiTaskRecorder::class);
            if ($aiSkipReason !== null) {
                $recorder->skip($aiRun, $message, $aiSkipReason);
            } else {
                $recorder->fail($aiRun, $message);
            }
        }

        $merge = function (array $base) use ($status, $message, $aiRun, $aiSkipReason): array {
            $st = $base['thumbnail_modes_status'] ?? [];
            if (! is_array($st)) {
                $st = [];
            }
            $st['enhanced'] = $status;
            $base['thumbnail_modes_status'] = $st;

            $mm = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($mm)) {
                $mm = [];
            }
            $prev = is_array($mm['enhanced'] ?? null) ? $mm['enhanced'] : [];
            $patch = [
                'failure_message' => $message,
                'failed_at' => now()->toIso8601String(),
            ];
            if ($aiSkipReason !== null) {
                $patch['skip_reason'] = $aiSkipReason;
            } else {
                unset($prev['skip_reason']);
            }
            if ($aiRun !== null) {
                $patch['ai_task_id'] = $aiRun->id;
            }
            $mm['enhanced'] = array_merge($prev, $patch);
            $base['thumbnail_modes_meta'] = $mm;

            return $base;
        };

        $version->refresh();
        $version->update(['metadata' => $merge($version->metadata ?? [])]);
        $asset->refresh();
        $asset->update(['metadata' => $merge($asset->metadata ?? [])]);

        Log::warning('[GenerateEnhancedPreviewJob] Terminal state', [
            'asset_id' => $asset->id,
            'status' => $status,
            'message' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $patch  Keys merged into thumbnail_modes_meta.enhanced
     */
    protected function mergeEnhancedMetaPatch(Asset $asset, AssetVersion $version, array $patch): void
    {
        $merge = function (array $base) use ($patch): array {
            $mm = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($mm)) {
                $mm = [];
            }
            $prev = is_array($mm['enhanced'] ?? null) ? $mm['enhanced'] : [];
            $mm['enhanced'] = array_merge($prev, $patch);
            $base['thumbnail_modes_meta'] = $mm;

            return $base;
        };

        $version->refresh();
        $version->update(['metadata' => $merge($version->metadata ?? [])]);
        $asset->refresh();
        $asset->update(['metadata' => $merge($asset->metadata ?? [])]);
    }
}
