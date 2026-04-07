<?php

namespace App\Jobs;

use App\Enums\AITaskType;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\AIService;
use App\Services\EditorGenerativeImagePersistService;
use App\Services\PresentationPreviewPromptBuilder;
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
 * AI presentation previews (image-edit from preferred/original thumbnail). Drawer/API triggered only.
 */
class GeneratePresentationPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public int $timeout;

    public function __construct(
        public readonly string $assetId,
        public readonly string $versionId,
        public readonly bool $force = false,
    ) {
        $this->timeout = max(180, (int) config('assets.thumbnail.job_timeout_seconds', 900));
    }

    public function handle(
        ThumbnailGenerationService $thumbnailService,
        AIService $aiService,
        PresentationPreviewPromptBuilder $promptBuilder,
        EditorGenerativeImagePersistService $imagePersist,
    ): void {
        $asset = Asset::query()
            ->with(['storageBucket', 'tenant', 'category', 'currentVersion'])
            ->find($this->assetId);
        $version = AssetVersion::find($this->versionId);

        if (! $asset || ! $version || $version->asset_id !== $asset->id) {
            Log::info('[GeneratePresentationPreviewJob] Missing asset/version, skipping', [
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
        $presMeta = is_array($modesMeta['presentation'] ?? null) ? $modesMeta['presentation'] : [];

        $attempts = (int) ($presMeta['attempts'] ?? 0);
        $lastAttemptAt = isset($presMeta['last_attempt_at']) ? strtotime((string) $presMeta['last_attempt_at']) : 0;
        $maxAttempts = (int) config('presentation_preview.max_attempts', 3);
        $cooldown = (int) config('presentation_preview.cooldown_seconds', 120);

        [$sourcePath, $sourceMode] = EnhancedPreviewFingerprint::resolveEnhancedSource($meta);
        if ($sourcePath === null || $sourcePath === '') {
            $this->persistTerminal(
                $asset,
                $version,
                'skipped',
                'No preferred or original thumbnail to use as presentation source'
            );

            return;
        }

        $presentationStatus = (string) ($modesStatus['presentation'] ?? '');

        if (! $this->force
            && $presentationStatus === 'skipped'
            && ($presMeta['skip_reason'] ?? '') === ThumbnailEnhancementAiTaskRecorder::SKIP_REASON_TOO_SMALL) {
            Log::info('[GeneratePresentationPreviewJob] Skipping: previously skipped (source too small); use force with admin', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        if (! $this->force && $presentationStatus !== 'complete') {
            if ($attempts >= $maxAttempts) {
                Log::info('[GeneratePresentationPreviewJob] Skipping: max attempts reached', [
                    'asset_id' => $asset->id,
                    'attempts' => $attempts,
                ]);

                return;
            }
            if ($lastAttemptAt > 0 && (time() - $lastAttemptAt) < $cooldown) {
                Log::info('[GeneratePresentationPreviewJob] Skipping: cooldown', [
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

        $localRaster = null;
        try {
            $localRaster = $thumbnailService->downloadObjectToTemp($bucket, $sourcePath, $asset->id);
        } catch (Throwable $e) {
            Log::warning('[GeneratePresentationPreviewJob] Source download failed', [
                'asset_id' => $asset->id,
                'path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            $this->persistTerminal($asset, $version, 'failed', 'Failed to download source thumbnail: '.$e->getMessage());

            return;
        }

        if ($localRaster === null || ! is_file($localRaster) || filesize($localRaster) === 0) {
            $this->persistTerminal($asset, $version, 'failed', 'Downloaded source thumbnail is empty');

            return;
        }

        $minW = max(1, (int) config('presentation_preview.min_source_width', 400));
        $minH = max(1, (int) config('presentation_preview.min_source_height', 400));
        $sourceDims = @getimagesize($localRaster);
        if ($sourceDims === false || ($sourceDims[0] ?? 0) < $minW || ($sourceDims[1] ?? 0) < $minH) {
            $this->persistTerminal(
                $asset,
                $version,
                'skipped',
                "Source too small for presentation preview (minimum {$minW}×{$minH} pixels).",
                ThumbnailEnhancementAiTaskRecorder::SKIP_REASON_TOO_SMALL
            );
            if (is_string($localRaster) && is_file($localRaster)) {
                @unlink($localRaster);
            }

            return;
        }

        $prompt = $promptBuilder->build($asset);
        $agentId = (string) config('presentation_preview.agent_id', 'presentation_preview');
        $modelKey = (string) config('presentation_preview.model_key', 'gpt-image-1');
        $imageBinary = file_get_contents($localRaster);
        if ($imageBinary === false || $imageBinary === '') {
            $this->persistTerminal($asset, $version, 'failed', 'Could not read source raster');
            if (is_file($localRaster)) {
                @unlink($localRaster);
            }

            return;
        }

        $t0 = microtime(true);

        try {
            $aiOut = $aiService->executeEditorImageEditAgent(
                $agentId,
                AITaskType::THUMBNAIL_PRESENTATION_PREVIEW,
                $prompt,
                [
                    'tenant' => $asset->tenant,
                    'user' => null,
                    'triggering_context' => 'system',
                    'asset_id' => $asset->id,
                    'image_binary' => $imageBinary,
                    'filename' => 'source.png',
                    'model' => $modelKey,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('[GeneratePresentationPreviewJob] AI edit failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $this->persistTerminal($asset, $version, 'failed', $e->getMessage());
            if (is_file($localRaster)) {
                @unlink($localRaster);
            }

            return;
        } finally {
            if (is_string($localRaster) && is_file($localRaster)) {
                @unlink($localRaster);
            }
        }

        $imageRef = (string) ($aiOut['image_ref'] ?? '');
        $aiTaskId = (int) ($aiOut['agent_run_id'] ?? 0);
        $tokensIn = (int) ($aiOut['tokens_in'] ?? 0);
        $tokensOut = (int) ($aiOut['tokens_out'] ?? 0);
        $cost = (float) ($aiOut['cost'] ?? 0);
        $resolvedModelKey = (string) ($aiOut['resolved_model_key'] ?? $modelKey);

        $presentationTemp = null;
        try {
            $bytes = $imagePersist->binaryFromProviderReference($imageRef);
            $presentationTemp = tempnam(sys_get_temp_dir(), 'pres_ai_');
            if ($presentationTemp === false) {
                throw new \RuntimeException('Could not allocate temp file for presentation output');
            }
            file_put_contents($presentationTemp, $bytes);
        } catch (Throwable $e) {
            Log::warning('[GeneratePresentationPreviewJob] Failed to decode AI image', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $this->persistTerminal($asset, $version, 'failed', 'Failed to decode AI image: '.$e->getMessage());

            return;
        }

        try {
            $result = $thumbnailService->generatePresentationPreviewsFromLocalRaster(
                $asset,
                $version,
                $presentationTemp
            );
        } catch (Throwable $e) {
            Log::warning('[GeneratePresentationPreviewJob] Thumbnail resize/upload failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $this->persistTerminal($asset, $version, 'failed', $e->getMessage());
            if (is_string($presentationTemp) && is_file($presentationTemp)) {
                @unlink($presentationTemp);
            }

            return;
        } finally {
            if (is_string($presentationTemp) && is_file($presentationTemp)) {
                @unlink($presentationTemp);
            }
        }

        $durationMs = (int) round((microtime(true) - $t0) * 1000);
        $styles = $result['styles_generated'] ?? [];
        $styleLabel = implode(',', $styles);

        $this->mergeSuccess(
            $asset,
            $version,
            $result,
            $prompt,
            $sourceMode,
            $resolvedModelKey,
            $styleLabel,
            $attemptsNext,
            $aiTaskId,
            $tokensIn,
            $tokensOut,
            $cost,
            $durationMs
        );

        Log::info('[GeneratePresentationPreviewJob] Presentation previews complete', [
            'asset_id' => $asset->id,
            'ai_task_id' => $aiTaskId,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function mergeSuccess(
        Asset $asset,
        AssetVersion $version,
        array $result,
        string $prompt,
        string $inputMode,
        string $model,
        string $style,
        int $attemptsDone,
        int $aiTaskId,
        int $tokensIn,
        int $tokensOut,
        float $cost,
        int $durationMs
    ): void {
        $mode = ThumbnailMode::Presentation->value;
        $finalThumbnails = $result['thumbnails'][$mode] ?? [];
        $thumbnailDimensions = $result['thumbnail_dimensions'][$mode] ?? [];

        $merge = function (array $base) use (
            $mode,
            $finalThumbnails,
            $thumbnailDimensions,
            $prompt,
            $inputMode,
            $model,
            $style,
            $attemptsDone,
            $aiTaskId,
            $tokensIn,
            $tokensOut,
            $cost,
            $durationMs
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
            $st['presentation'] = 'complete';
            $base['thumbnail_modes_status'] = $st;

            $mm = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($mm)) {
                $mm = [];
            }
            $prev = is_array($mm['presentation'] ?? null) ? $mm['presentation'] : [];
            unset($prev['failure_message'], $prev['failed_at'], $prev['skip_reason']);
            $mm['presentation'] = array_merge($prev, [
                'model' => $model,
                'prompt' => $prompt,
                'style' => $style,
                'input_mode' => $inputMode,
                'ai_task_id' => $aiTaskId,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost' => $cost,
                'duration_ms' => $durationMs,
                'attempts' => $attemptsDone,
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

    protected function markProcessing(Asset $asset, AssetVersion $version, int $attempts): void
    {
        $merge = function (array $base) use ($attempts): array {
            $st = $base['thumbnail_modes_status'] ?? [];
            if (! is_array($st)) {
                $st = [];
            }
            $st['presentation'] = 'processing';
            $base['thumbnail_modes_status'] = $st;

            $mm = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($mm)) {
                $mm = [];
            }
            $prev = is_array($mm['presentation'] ?? null) ? $mm['presentation'] : [];
            unset($prev['failure_message'], $prev['failed_at'], $prev['skip_reason']);
            $mm['presentation'] = array_merge($prev, [
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

    protected function persistTerminal(
        Asset $asset,
        AssetVersion $version,
        string $status,
        string $message,
        ?string $skipReason = null
    ): void {
        $merge = function (array $base) use ($status, $message, $skipReason): array {
            $st = $base['thumbnail_modes_status'] ?? [];
            if (! is_array($st)) {
                $st = [];
            }
            $st['presentation'] = $status;
            $base['thumbnail_modes_status'] = $st;

            $mm = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($mm)) {
                $mm = [];
            }
            $prev = is_array($mm['presentation'] ?? null) ? $mm['presentation'] : [];
            $patch = [
                'failure_message' => $message,
                'failed_at' => now()->toIso8601String(),
            ];
            if ($skipReason !== null) {
                $patch['skip_reason'] = $skipReason;
            } else {
                unset($prev['skip_reason']);
            }
            $mm['presentation'] = array_merge($prev, $patch);
            $base['thumbnail_modes_meta'] = $mm;

            return $base;
        };

        $version->refresh();
        $version->update(['metadata' => $merge($version->metadata ?? [])]);
        $asset->refresh();
        $asset->update(['metadata' => $merge($asset->metadata ?? [])]);

        Log::warning('[GeneratePresentationPreviewJob] Terminal state', [
            'asset_id' => $asset->id,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
