<?php

namespace App\Jobs;

use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\ThumbnailGenerationService;
use App\Support\ThumbnailMetadata;
use App\Support\ThumbnailMode;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates smart-cropped "preferred" thumbnails after original thumbnails complete.
 * Runs off the main ProcessAssetJob chain to avoid slowing the upload pipeline.
 */
class GeneratePreferredThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public $tries = 8;

    public int $maxExceptions = 2;

    public $timeout;

    public function __construct(
        public readonly string $assetId,
        public readonly string $versionId,
        public readonly bool $force = false,
    ) {
        $job = (int) config('assets.thumbnail.job_timeout_seconds', 900);
        $large = (int) config('assets.thumbnail.large_asset_timeout_seconds', 1800);
        $this->timeout = max($job, $large);
        $this->tries = max(1, (int) config('assets.processing.pipeline_job_max_tries', 64));
        $this->configureImagesQueue();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(
            max(1, (int) config('assets.processing.pipeline_job_retry_until_minutes', 120))
        );
    }

    public function handle(ThumbnailGenerationService $thumbnailService): void
    {
        if (! config('assets.thumbnail.preferred.enabled', true)) {
            return;
        }

        $asset = Asset::find($this->assetId);
        $version = AssetVersion::find($this->versionId);
        if (! $asset || ! $version || $version->asset_id !== $asset->id) {
            Log::info('[GeneratePreferredThumbnailJob] Missing asset/version, skipping', [
                'asset_id' => $this->assetId,
                'version_id' => $this->versionId,
            ]);

            return;
        }

        $meta = $version->metadata ?? [];
        if (! ThumbnailMetadata::hasThumb($meta)) {
            $this->persistModeTerminal(
                $asset,
                $version,
                'failed',
                null,
                'No original thumbnail metadata; cannot generate preferred thumbnails'
            );

            return;
        }

        $bucket = $asset->storageBucket;
        $s3Client = $bucket ? $this->createS3Client() : null;

        if (! $this->force && $bucket && $s3Client !== null) {
            if ($this->validatePreferredCompleteness($asset, $meta, $s3Client)) {
                return;
            }
        }

        self::markPreferredProcessing($asset, $version, $this->force);

        $mode = ThumbnailMode::Preferred->value;

        try {
            $result = $thumbnailService->generateThumbnailsForVersion($version, $mode);
        } catch (\Throwable $e) {
            Log::warning('[GeneratePreferredThumbnailJob] Thumbnail generation threw', [
                'asset_id' => $asset->id,
                'version_id' => $version->id,
                'error' => $e->getMessage(),
            ]);
            $this->persistModeTerminal($asset, $version, 'failed', null, $e->getMessage());

            return;
        }

        $finalThumbnails = $result['thumbnails'][$mode] ?? [];
        $previewThumbnails = $result['preview_thumbnails'][$mode] ?? [];
        $thumbnailDimensions = $result['thumbnail_dimensions'][$mode] ?? [];

        if (empty($finalThumbnails)) {
            $this->persistModeTerminal($asset, $version, 'failed', $result['thumbnail_modes_meta'] ?? null, 'No preferred thumbnails generated');

            return;
        }

        if (! $bucket) {
            $this->persistModeTerminal($asset, $version, 'failed', $result['thumbnail_modes_meta'] ?? null, 'Asset missing storage bucket');

            return;
        }
        $minValidSize = 50;
        foreach ($finalThumbnails as $styleName => $thumbnailData) {
            if ($styleName === 'preview') {
                continue;
            }
            $thumbnailPath = $thumbnailData['path'] ?? null;
            if (! is_string($thumbnailPath) || $thumbnailPath === '') {
                $this->persistModeTerminal($asset, $version, 'failed', $result['thumbnail_modes_meta'] ?? null, "Missing path for style {$styleName}");

                return;
            }
            try {
                $head = $s3Client->headObject([
                    'Bucket' => $bucket->name,
                    'Key' => $thumbnailPath,
                ]);
                $len = (int) ($head['ContentLength'] ?? 0);
                if ($len < $minValidSize) {
                    $this->persistModeTerminal($asset, $version, 'failed', $result['thumbnail_modes_meta'] ?? null, "Preferred thumbnail too small: {$styleName}");

                    return;
                }
            } catch (S3Exception $e) {
                $this->persistModeTerminal($asset, $version, 'failed', $result['thumbnail_modes_meta'] ?? null, 'S3 verification failed: '.$e->getMessage());

                return;
            }
        }

        foreach ($finalThumbnails as $styleName => $row) {
            $path = $row['path'] ?? '';
            if (is_string($path) && $path !== '' && ! str_contains($path, '/thumbnails/preferred/')) {
                Log::warning('[GeneratePreferredThumbnailJob] Unexpected preferred path shape', [
                    'asset_id' => $asset->id,
                    'style' => $styleName,
                    'path' => $path,
                ]);
            }
        }

        $modesMeta = $result['thumbnail_modes_meta'] ?? [];
        $prefMeta = is_array($modesMeta['preferred'] ?? null) ? $modesMeta['preferred'] : [];
        $cropApplied = (bool) ($prefMeta['applied'] ?? $prefMeta['crop_applied'] ?? false);
        $minConf = (float) config('assets.thumbnail.preferred.min_crop_confidence', 0.55);
        $confidence = $prefMeta['confidence'] ?? null;
        $confidenceF = is_numeric($confidence) ? (float) $confidence : 0.0;
        if ($cropApplied && $confidenceF < $minConf) {
            Log::info('[GeneratePreferredThumbnailJob] Rejecting preferred thumbnails: crop confidence below threshold', [
                'asset_id' => $asset->id,
                'confidence' => $confidenceF,
                'min_crop_confidence' => $minConf,
            ]);
            $this->persistModeTerminal(
                $asset,
                $version,
                'failed',
                $result['thumbnail_modes_meta'] ?? null,
                'Smart crop confidence below threshold; using original thumbnails.'
            );

            return;
        }

        $this->mergePreferredMetadata($asset, $version, $mode, $finalThumbnails, $previewThumbnails, $thumbnailDimensions, $result);

        Log::info('[GeneratePreferredThumbnailJob] Preferred thumbnails complete', [
            'asset_id' => $asset->id,
            'version_id' => $version->id,
            'styles' => array_keys($finalThumbnails),
        ]);
    }

    /**
     * @param  array<string, mixed>  $finalThumbnails
     * @param  array<string, mixed>  $previewThumbnails
     * @param  array<string, mixed>  $thumbnailDimensions
     * @param  array<string, mixed>  $result
     */
    protected function mergePreferredMetadata(
        Asset $asset,
        AssetVersion $version,
        string $mode,
        array $finalThumbnails,
        array $previewThumbnails,
        array $thumbnailDimensions,
        array $result
    ): void {
        $modeBucket = $finalThumbnails;
        if (! empty($previewThumbnails['preview'])) {
            $modeBucket['preview'] = $previewThumbnails['preview'];
        }

        $merge = function (array $base) use ($mode, $modeBucket, $previewThumbnails, $thumbnailDimensions, $result): array {
            $thumbs = $base['thumbnails'] ?? [];
            if (! is_array($thumbs)) {
                $thumbs = [];
            }
            $thumbs[$mode] = $modeBucket;
            $base['thumbnails'] = $thumbs;

            $pt = $base['preview_thumbnails'] ?? [];
            if (! is_array($pt)) {
                $pt = [];
            }
            if (! empty($previewThumbnails)) {
                $pt[$mode] = $previewThumbnails;
            }
            $base['preview_thumbnails'] = $pt;

            $dims = $base['thumbnail_dimensions'] ?? [];
            if (! is_array($dims)) {
                $dims = [];
            }
            $dims[$mode] = $thumbnailDimensions;
            $base['thumbnail_dimensions'] = $dims;

            $modesMeta = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($modesMeta)) {
                $modesMeta = [];
            }
            if (! empty($result['thumbnail_modes_meta']) && is_array($result['thumbnail_modes_meta'])) {
                $modesMeta = array_replace_recursive($modesMeta, $result['thumbnail_modes_meta']);
            }
            if (isset($modesMeta['preferred']) && is_array($modesMeta['preferred'])) {
                unset($modesMeta['preferred']['failure_message'], $modesMeta['preferred']['failed_at']);
            }
            $base['thumbnail_modes_meta'] = $modesMeta;

            $modesStatus = $base['thumbnail_modes_status'] ?? [];
            if (! is_array($modesStatus)) {
                $modesStatus = [];
            }
            $modesStatus['preferred'] = 'complete';
            $base['thumbnail_modes_status'] = $modesStatus;

            $base['preferred_thumbnail_error'] = null;

            return $base;
        };

        $version->refresh();
        $version->update([
            'metadata' => $merge($version->metadata ?? []),
        ]);

        $asset->refresh();
        $asset->update([
            'metadata' => $merge($asset->metadata ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $thumbnailModesMeta
     */
    protected function persistModeTerminal(
        Asset $asset,
        AssetVersion $version,
        string $status,
        ?array $thumbnailModesMeta,
        string $message
    ): void {
        $merge = function (array $base) use ($status, $thumbnailModesMeta, $message): array {
            $modesStatus = $base['thumbnail_modes_status'] ?? [];
            if (! is_array($modesStatus)) {
                $modesStatus = [];
            }
            $modesStatus['preferred'] = $status;
            $base['thumbnail_modes_status'] = $modesStatus;

            $mm = $base['thumbnail_modes_meta'] ?? [];
            if (! is_array($mm)) {
                $mm = [];
            }
            if ($thumbnailModesMeta !== null && $thumbnailModesMeta !== []) {
                $mm = array_replace_recursive($mm, $thumbnailModesMeta);
            }
            if ($status === 'failed') {
                $prevPreferred = is_array($mm['preferred'] ?? null) ? $mm['preferred'] : [];
                $mm['preferred'] = array_replace_recursive($prevPreferred, [
                    'applied' => false,
                    'crop_applied' => false,
                    'failure_message' => $message,
                    'failed_at' => now()->toIso8601String(),
                ]);
            }
            $base['thumbnail_modes_meta'] = $mm;

            if ($status === 'failed') {
                $base['preferred_thumbnail_error'] = $message;
            }

            return $base;
        };

        $version->refresh();
        $version->update(['metadata' => $merge($version->metadata ?? [])]);
        $asset->refresh();
        $asset->update(['metadata' => $merge($asset->metadata ?? [])]);

        Log::warning('[GeneratePreferredThumbnailJob] Terminal state', [
            'asset_id' => $asset->id,
            'version_id' => $version->id,
            'status' => $status,
            'message' => $message,
        ]);
    }

    /**
     * Mark preferred thumbnails as queued/processing (caller dispatches the job right after).
     * Does not downgrade {@see validatePreferredCompleteness} "complete" state.
     */
    public static function markPreferredProcessing(Asset $asset, AssetVersion $version, bool $force = false): void
    {
        $merge = function (array $base) use ($force): array {
            $st = $base['thumbnail_modes_status'] ?? [];
            if (! is_array($st)) {
                $st = [];
            }
            if (! $force && ($st['preferred'] ?? '') === 'complete') {
                return $base;
            }
            $st['preferred'] = 'processing';
            $base['thumbnail_modes_status'] = $st;

            return $base;
        };

        $version->refresh();
        $version->update([
            'metadata' => $merge($version->metadata ?? []),
        ]);

        $asset->refresh();
        $asset->update([
            'metadata' => $merge($asset->metadata ?? []),
        ]);
    }

    /**
     * Idempotent "complete" only when every style present on the original bucket also exists on preferred
     * with non-empty paths, and configured key styles are present in S3.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function validatePreferredCompleteness(Asset $asset, array $metadata, S3Client $s3Client): bool
    {
        $mode = ThumbnailMode::Preferred->value;
        $origMode = ThumbnailMetadata::DEFAULT_MODE;

        $configuredStyles = array_keys(config('assets.thumbnail_styles', []));
        $requiredStyles = [];
        foreach ($configuredStyles as $style) {
            if ($style === 'preview') {
                continue;
            }
            if (ThumbnailMetadata::stylePath($metadata, $style, $origMode) !== null) {
                $requiredStyles[] = $style;
            }
        }

        if ($requiredStyles === []) {
            return false;
        }

        foreach ($requiredStyles as $style) {
            if (ThumbnailMetadata::stylePath($metadata, $style, $mode) === null) {
                return false;
            }
        }

        $bucket = $asset->storageBucket;
        if (! $bucket) {
            return false;
        }

        $verify = config('assets.thumbnail.preferred.completion_verify_styles', ['thumb', 'medium']);
        if (! is_array($verify)) {
            $verify = ['thumb', 'medium'];
        }

        $minBytes = 50;

        foreach ($verify as $style) {
            if (! in_array($style, $requiredStyles, true)) {
                continue;
            }

            $path = ThumbnailMetadata::stylePath($metadata, $style, $mode);
            if ($path === null || $path === '') {
                return false;
            }

            try {
                $head = $s3Client->headObject([
                    'Bucket' => $bucket->name,
                    'Key' => $path,
                ]);
                if ((int) ($head['ContentLength'] ?? 0) < $minBytes) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    protected function createS3Client(): S3Client
    {
        if (! class_exists(S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }
}
