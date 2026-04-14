<?php

namespace App\Jobs;

use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\StudioViewCropService;
use App\Services\TemplateRenderer;
use App\Services\ThumbnailEnhancementAiTaskRecorder;
use App\Services\ThumbnailGenerationService;
use App\Support\EnhancedPreviewFingerprint;
use App\Support\StudioViewSourceResolver;
use App\Support\ThumbnailMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Studio View: user-defined crop on the large source thumbnail, then template compositing into the enhanced mode bucket.
 * Never auto-dispatched — queued only from the Studio save API.
 *
 * @see \App\Services\TemplateRenderer
 * @see \App\Services\ThumbnailGenerationService::generateEnhancedPreviewsFromLocalRaster()
 */
class GenerateEnhancedPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public int $timeout;

    /**
     * @param  array<string, float|int>  $studioCropNormalized  x, y, width, height in 0–1 relative to canvas image
     * @param  array<string, float|int>|null  $poiNormalized  optional x, y in 0–1 relative to full canvas image
     */
    public function __construct(
        public readonly string $assetId,
        public readonly string $versionId,
        public readonly array $studioCropNormalized,
        public readonly ?array $poiNormalized = null,
        public readonly bool $force = false,
    ) {
        $this->timeout = max(120, (int) config('assets.thumbnail.job_timeout_seconds', 900));
    }

    public function handle(
        ThumbnailGenerationService $thumbnailService,
        TemplateRenderer $templateRenderer,
        StudioViewCropService $cropService,
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

        [$canvasPath, $canvasMode, $canvasStyle] = StudioViewSourceResolver::resolveLargeRasterPath($meta);
        if ($canvasPath === null || $canvasPath === '') {
            $this->persistTerminal(
                $asset,
                $version,
                'skipped',
                'No large or medium source thumbnail available for Studio View'
            );

            return;
        }

        $cropNorm = $cropService->normalizeCropRect($this->studioCropNormalized);
        if ($cropNorm === null) {
            $this->persistTerminal($asset, $version, 'failed', 'Invalid Studio crop rectangle');

            return;
        }

        $poiNorm = $cropService->normalizePoi($this->poiNormalized);

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
            Log::info('[GenerateEnhancedPreviewJob] Skipping: Studio output still matches crop + source + template', [
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
        $aiRun = $aiRecorder->start($asset, $version, $canvasMode, $templateId, [
            'template_version' => $templateVersion,
            'attempt' => $attemptsNext,
        ]);
        $this->mergeEnhancedMetaPatch($asset, $version, ['ai_task_id' => $aiRun->id]);

        $t0 = microtime(true);

        $localRaster = null;
        try {
            $localRaster = $thumbnailService->downloadObjectToTemp($bucket, $canvasPath, $asset->id);
        } catch (Throwable $e) {
            Log::warning('[GenerateEnhancedPreviewJob] Canvas download failed', [
                'asset_id' => $asset->id,
                'path' => $canvasPath,
                'error' => $e->getMessage(),
            ]);
            $this->persistTerminal($asset, $version, 'failed', 'Failed to download source for Studio View: '.$e->getMessage(), $aiRun);

            return;
        }

        if ($localRaster === null || ! is_file($localRaster) || filesize($localRaster) === 0) {
            $this->persistTerminal($asset, $version, 'failed', 'Downloaded Studio canvas is empty', $aiRun);

            return;
        }

        $sourceDims = @getimagesize($localRaster);
        if ($sourceDims === false || ($sourceDims[0] ?? 0) < 32 || ($sourceDims[1] ?? 0) < 32) {
            $this->persistTerminal(
                $asset,
                $version,
                'skipped',
                'Studio canvas is too small to crop.',
                $aiRun,
                ThumbnailEnhancementAiTaskRecorder::SKIP_REASON_TOO_SMALL
            );
            if (is_file($localRaster)) {
                @unlink($localRaster);
            }

            return;
        }

        $croppedPath = $cropService->cropNormalizedToTemp($localRaster, $cropNorm);
        if ($croppedPath === null || ! is_file($croppedPath)) {
            $this->persistTerminal($asset, $version, 'failed', 'Could not apply Studio crop', $aiRun);
            if (is_file($localRaster)) {
                @unlink($localRaster);
            }

            return;
        }

        $cropDims = @getimagesize($croppedPath);
        if ($cropDims === false || ($cropDims[0] ?? 0) < 32 || ($cropDims[1] ?? 0) < 32) {
            $this->persistTerminal(
                $asset,
                $version,
                'failed',
                'Cropped region is too small after Studio crop.',
                $aiRun
            );
            @unlink($croppedPath);
            if (is_file($localRaster)) {
                @unlink($localRaster);
            }

            return;
        }

        $inputHash = EnhancedPreviewFingerprint::computeStudioInputHash(
            $thumbnailService,
            $bucket,
            $canvasPath,
            $cropNorm,
            $poiNorm,
            $templateId
        );
        $aiRecorder->mergeMetadata($aiRun, ['input_hash' => $inputHash, 'studio_canvas' => $canvasStyle]);

        try {
            $result = $thumbnailService->generateEnhancedPreviewsFromLocalRaster(
                $asset,
                $version,
                $croppedPath,
                $templateId,
                $canvasMode
            );
        } catch (Throwable $e) {
            Log::warning('[GenerateEnhancedPreviewJob] Generation failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $this->persistTerminal($asset, $version, 'failed', $e->getMessage(), $aiRun);
            @unlink($croppedPath);
            if (is_file($localRaster)) {
                @unlink($localRaster);
            }

            return;
        } finally {
            if (is_string($croppedPath) && is_file($croppedPath)) {
                @unlink($croppedPath);
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
            $canvasMode,
            $canvasStyle,
            $cropNorm,
            $poiNorm,
            $inputHash,
            $attemptsNext,
            $aiRun->id,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array{x:float,y:float,width:float,height:float}  $cropNorm
     * @param  array{x:float,y:float}|null  $poiNorm
     */
    protected function mergeSuccess(
        Asset $asset,
        AssetVersion $version,
        array $result,
        string $templateId,
        string $templateVersion,
        string $canvasMode,
        string $canvasStyle,
        array $cropNorm,
        ?array $poiNorm,
        string $inputHash,
        int $attemptsDone,
        int $aiTaskId,
    ): void {
        $mode = ThumbnailMode::Enhanced->value;
        $finalThumbnails = $result['thumbnails'][$mode] ?? [];
        $thumbnailDimensions = $result['thumbnail_dimensions'][$mode] ?? [];

        $merge = function (array $base) use (
            $mode,
            $finalThumbnails,
            $thumbnailDimensions,
            $templateId,
            $templateVersion,
            $canvasMode,
            $canvasStyle,
            $cropNorm,
            $poiNorm,
            $inputHash,
            $attemptsDone,
            $aiTaskId,
        ): array {
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
            $row = [
                'template' => $templateId,
                'template_version' => $templateVersion,
                'source_mode' => $canvasMode,
                'studio_canvas_style' => $canvasStyle,
                'manual_studio' => true,
                'studio_crop' => $cropNorm,
                'input_hash' => $inputHash,
                'ai_task_id' => $aiTaskId,
                'attempts' => $attemptsDone,
                'last_attempt_at' => now()->toIso8601String(),
            ];
            if ($poiNorm !== null) {
                $row['poi'] = $poiNorm;
            } else {
                unset($prev['poi']);
            }
            $mm['enhanced'] = array_merge($prev, $row);
            $base['thumbnail_modes_meta'] = $mm;

            return $base;
        };

        $version->refresh();
        $version->update(['metadata' => $merge($version->metadata ?? [])]);
        $asset->refresh();
        $asset->update(['metadata' => $merge($asset->metadata ?? [])]);

        Log::info('[GenerateEnhancedPreviewJob] Studio View (enhanced mode) complete', [
            'asset_id' => $asset->id,
            'template' => $templateId,
            'canvas_mode' => $canvasMode,
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
