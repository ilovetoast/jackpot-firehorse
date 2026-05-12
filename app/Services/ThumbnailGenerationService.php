<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\Logging\ThumbnailProfilingRecorder;
use App\Support\ThumbnailMode;
use App\Support\VideoDisplayProbe;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Thumbnail Generation Service
 *
 * Enterprise-grade thumbnail generation service that handles multiple file types
 * and generates predefined thumbnail styles atomically per asset.
 *
 * File type support:
 * - Images: jpg, png, webp, gif (direct thumbnail generation via GD library) ✓ IMPLEMENTED
 * - HEIC/HEIF: thumbnail generation via Imagick (requires HEIF delegate) ✓ IMPLEMENTED
 * - TIFF: thumbnail generation via Imagick (requires Imagick PHP extension) ✓ IMPLEMENTED
 * - AVIF: thumbnail generation via Imagick (requires Imagick PHP extension) ✓ IMPLEMENTED
 * - PDF: first page extraction (page 1 only, via spatie/pdf-to-image) ✓ IMPLEMENTED
 * - Office (Word / Excel / PowerPoint): LibreOffice headless → PDF → same stack as PDF ✓ IMPLEMENTED
 *
 * Thumbnail output format:
 * - Configurable: WebP (default, better compression) or JPEG (maximum compatibility)
 * - WebP offers 25-35% smaller file sizes with similar quality
 * - Modern browser support is excellent (Chrome, Firefox, Safari, Edge)
 * - PSD/PSB: flattened preview (best-effort) - @todo Implement
 * - AI: best-effort preview - @todo Implement
 * - Video: mp4, mov → first frame extraction (FFmpeg) ✓ IMPLEMENTED
 *
 * All thumbnails are stored in S3 alongside the original asset.
 * Thumbnail paths follow pattern: {asset_path_base}/thumbnails/{mode}/{style}/{filename}
 *
 * PDF thumbnail generation:
 * - Uses spatie/pdf-to-image with ImageMagick/Ghostscript backend
 * - Only page 1 is processed (enforced by config and safety guards)
 * - Safety guards: max file size (100MB default), timeout (60s default), page limit (1)
 * - Thumbnails are resized using GD library (same as image thumbnails for consistency)
 *
 * @todo PSD / PSB thumbnail generation (Imagick)
 * @todo PDF multi-page previews (future enhancement - currently page 1 only)
 * @todo Asset versioning (future phase)
 * @todo Activity timeline integration
 *
 * CDN Migration Prep (when moving to CDN):
 * - large: cache aggressively (modal preview, high quality)
 * - preview: can be low quality (hover/quick view)
 * - medium: optimize for grid display
 * - thumb: smallest, grid thumbnails
 * Current structure already supports per-style caching policies.
 */
class ThumbnailGenerationService
{
    /**
     * S3 client instance.
     */
    protected ?S3Client $s3Client = null;

    protected string $generationMode = 'original';

    /**
     * @var array{
     *   path: string,
     *   applied: bool,
     *   confidence: float,
     *   skip_reason?: string,
     *   crop_type?: string,
     *   detection_confidence?: float,
     *   signals?: array<string, mixed>
     * }|null
     */
    protected ?array $preferredCropSummary = null;

    protected ?string $preferredPdfRasterCachePath = null;

    protected ?string $preferredVideoRasterCachePath = null;

    /**
     * One LibreOffice conversion per {@see generateThumbnails} run; PDF path lives under {@see $officeLibreOfficeWorkDir}.
     */
    protected ?string $officeLibreOfficeWorkDir = null;

    protected ?string $officeIntermediatePdfPath = null;

    /** @var array<string, mixed>|null First raster orientation profile for this generateThumbnails run (profiling). */
    protected ?array $rasterOrientationProfile = null;

    /** Cached upright PNG path for GD raster thumbnails (one physical source per temp download). */
    protected ?string $gdOrientCacheKey = null;

    protected ?string $gdOrientWorkPath = null;

    protected bool $gdOrientWorkCleanup = false;

    /**
     * When set, {@see generateImageThumbnail} records per-phase timings for diagnostics (assets:profile-thumbnail).
     *
     * @var array<string, float>|null
     */
    protected ?array $diagnosticThumbnailSegmentMs = null;

    /** @var array<string, mixed>|null */
    protected ?array $thumbnailProfilingRun = null;

    /**
     * Convert technical error messages to user-friendly messages.
     *
     * This sanitizes exception messages and technical details that users shouldn't see,
     * replacing them with clear, actionable error messages.
     *
     * @param  string  $errorMessage  The raw error message
     * @param  string|null  $fileType  Optional file type for type-specific errors
     * @return string User-friendly error message
     */
    protected function sanitizeErrorMessage(string $errorMessage, ?string $fileType = null): string
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);

        return $fileTypeService->sanitizeErrorMessage($errorMessage, $fileType);
    }

    /**
     * Create a new ThumbnailGenerationService instance.
     *
     * @param  S3Client|null  $s3Client  Optional S3 client for testing
     */
    public function __construct(
        ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Enable per-segment timing inside {@see generateImageThumbnail} (GD raster path only).
     *
     * @internal Used by {@see \App\Services\ThumbnailProfilingService}
     */
    public function beginDiagnosticThumbnailTimings(): void
    {
        $this->diagnosticThumbnailSegmentMs = [
            'file_meta_ms' => 0.0,
            'decode_ms' => 0.0,
            'normalize_ms' => 0.0,
            'resize_ms' => 0.0,
            'encode_ms' => 0.0,
        ];
    }

    /**
     * @return array<string, float>|null
     */
    public function takeDiagnosticThumbnailTimings(): ?array
    {
        $out = $this->diagnosticThumbnailSegmentMs;
        $this->diagnosticThumbnailSegmentMs = null;

        return $out;
    }

    protected function thumbnailProfilingEnabled(): bool
    {
        return ThumbnailProfilingRecorder::enabled();
    }

    protected function resetGdRasterOrientationCache(): void
    {
        if ($this->gdOrientWorkCleanup && is_string($this->gdOrientWorkPath) && is_file($this->gdOrientWorkPath)) {
            @unlink($this->gdOrientWorkPath);
        }
        $this->gdOrientCacheKey = null;
        $this->gdOrientWorkPath = null;
        $this->gdOrientWorkCleanup = false;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function mergeRasterOrientationProfileOnce(array $profile): void
    {
        if ($this->rasterOrientationProfile !== null) {
            return;
        }
        $enriched = $profile;
        if ($this->thumbnailProfilingRun !== null) {
            $enriched['profiling_asset_id'] = $this->thumbnailProfilingRun['asset_id'] ?? null;
            $enriched['original_filename'] = $this->thumbnailProfilingRun['original_filename'] ?? null;
            $enriched['mime_type'] = $this->thumbnailProfilingRun['mime_type'] ?? null;
        }
        $this->rasterOrientationProfile = $enriched;
    }

    /**
     * @return array{0: \Imagick, 1: array<string, mixed>}
     */
    protected function normalizePrintTiffFrameForWebThumbnail(\Imagick $frame): array
    {
        $orient = ImageOrientationNormalizer::imagickAutoOrientAndResetOrientation($frame);

        if (! (bool) config('assets.thumbnail.tiff.normalize_for_web', true)) {
            $orient['width_after'] = (int) $frame->getImageWidth();
            $orient['height_after'] = (int) $frame->getImageHeight();

            return [$frame, $orient];
        }

        try {
            $frame->setImageBackgroundColor(new \ImagickPixel('#ffffff'));
        } catch (\Throwable) {
        }

        if (method_exists($frame, 'mergeImageLayers') && defined('Imagick::LAYERMETHOD_FLATTEN')) {
            try {
                $flat = $frame->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                if ($flat instanceof \Imagick) {
                    $frame->clear();
                    $frame->destroy();
                    $frame = $flat;
                }
            } catch (\Throwable $e) {
                Log::debug('[ThumbnailGenerationService] TIFF mergeImageLayers skipped', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            if (method_exists($frame, 'transformImageColorspace') && defined('Imagick::COLORSPACE_SRGB')) {
                $frame->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
        } catch (\Throwable $e) {
            Log::debug('[ThumbnailGenerationService] TIFF colorspace normalize skipped', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $frame->setImageBackgroundColor(new \ImagickPixel('#ffffff'));
            if (method_exists($frame, 'setImageAlphaChannel') && defined('Imagick::ALPHACHANNEL_FLATTEN')) {
                $frame->setImageAlphaChannel(\Imagick::ALPHACHANNEL_FLATTEN);
            }
        } catch (\Throwable $e) {
            Log::debug('[ThumbnailGenerationService] TIFF alpha flatten skipped', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if (method_exists($frame, 'stripImage')) {
                $frame->stripImage();
            }
        } catch (\Throwable) {
        }

        $orient['width_after'] = (int) $frame->getImageWidth();
        $orient['height_after'] = (int) $frame->getImageHeight();

        return [$frame, $orient];
    }

    /**
     * Cached upright raster for GD thumbnails (JPEG/PNG/WebP/GIF) — one decode per downloaded source per run.
     *
     * @return array{path: string, imagetype: int, profile: array<string, mixed>}
     */
    protected function resolveGdThumbnailRaster(string $sourcePath): array
    {
        if ($this->gdOrientCacheKey === $sourcePath
            && $this->gdOrientWorkPath !== null
            && is_file($this->gdOrientWorkPath)) {
            $info = @getimagesize($this->gdOrientWorkPath);
            $type = is_array($info) ? (int) $info[2] : IMAGETYPE_PNG;

            return [
                'path' => $this->gdOrientWorkPath,
                'imagetype' => $type,
                'profile' => [],
            ];
        }

        $prep = ImageOrientationNormalizer::prepareFlatRasterForGdThumbnail($sourcePath);
        $this->mergeRasterOrientationProfileOnce($prep['profile']);
        $this->gdOrientCacheKey = $sourcePath;
        $this->gdOrientWorkPath = $prep['path'];
        $this->gdOrientWorkCleanup = $prep['cleanup'];

        return [
            'path' => $prep['path'],
            'imagetype' => $prep['imagetype'],
            'profile' => $prep['profile'],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function imagickNormalizeThumbnailOrientation(\Imagick $imagick, string $pipeline, array $extra = []): void
    {
        $diag = ImageOrientationNormalizer::imagickAutoOrientAndResetOrientation($imagick);
        $this->mergeRasterOrientationProfileOnce(array_merge(['pipeline' => $pipeline], $extra, $diag));
    }

    protected function beginThumbnailProfiling(Asset $asset, ?AssetVersion $version, string $mode): void
    {
        $this->resetGdRasterOrientationCache();
        $this->rasterOrientationProfile = null;
        if (! $this->thumbnailProfilingEnabled()) {
            $this->thumbnailProfilingRun = null;

            return;
        }
        $this->thumbnailProfilingRun = [
            '_t0' => microtime(true),
            '_mark' => microtime(true),
            'run_id' => (string) Str::uuid(),
            'kind' => 'thumbnail_generation',
            'asset_id' => (string) $asset->id,
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'upload_session_id' => $asset->upload_session_id,
            'asset_version_id' => $version?->id,
            'mime_type' => $version?->mime_type ?? $asset->mime_type,
            'original_filename' => $asset->original_filename,
            'thumbnail_mode' => $mode,
            'segments' => [],
            'derivatives' => [],
        ];
    }

    protected function thumbProfilingLap(string $segmentName): void
    {
        if ($this->thumbnailProfilingRun === null) {
            return;
        }
        $now = microtime(true);
        $deltaMs = (int) round(($now - (float) $this->thumbnailProfilingRun['_mark']) * 1000.0);
        $this->thumbnailProfilingRun['segments'][$segmentName] = $deltaMs;
        $this->thumbnailProfilingRun['_mark'] = $now;
    }

    protected function thumbProfilingDerivative(string $style, int $generateMs, int $uploadMs, ?int $outputBytes, ?int $thumbOutW = null, ?int $thumbOutH = null): void
    {
        if ($this->thumbnailProfilingRun === null) {
            return;
        }
        $row = [
            'style' => $style,
            'generate_ms' => $generateMs,
            'upload_ms' => $uploadMs,
            'output_bytes' => $outputBytes,
        ];
        if ($thumbOutW !== null) {
            $row['thumb_output_width'] = $thumbOutW;
        }
        if ($thumbOutH !== null) {
            $row['thumb_output_height'] = $thumbOutH;
        }
        $this->thumbnailProfilingRun['derivatives'][] = $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function completeThumbnailProfiling(
        ?int $sourceFileSize,
        ?int $sourceImageWidth,
        ?int $sourceImageHeight,
        bool $success,
    ): ?array {
        if ($this->thumbnailProfilingRun === null) {
            return null;
        }
        $run = $this->thumbnailProfilingRun;
        $this->thumbnailProfilingRun = null;
        $t0 = (float) ($run['_t0'] ?? microtime(true));
        unset($run['_t0'], $run['_mark']);
        $run['source_file_size'] = $sourceFileSize;
        $run['source_image_width'] = $sourceImageWidth;
        $run['source_image_height'] = $sourceImageHeight;
        if ($this->rasterOrientationProfile !== null) {
            $run['raster_orientation'] = $this->rasterOrientationProfile;
            $this->rasterOrientationProfile = null;
        }
        $run['total_service_ms'] = (int) round((microtime(true) - $t0) * 1000.0);
        $run['success'] = $success;
        $run['finished_at'] = now()->toIso8601String();
        $run = array_merge(ThumbnailProfilingRecorder::consumeJobContext(), $run);
        if (isset($run['raster_orientation']) && is_array($run['raster_orientation'])) {
            foreach (['job_class', 'worker_queue', 'queue_job_id', 'queue_wait_ms'] as $jk) {
                if (! array_key_exists($jk, $run['raster_orientation']) && array_key_exists($jk, $run)) {
                    $run['raster_orientation'][$jk] = $run[$jk];
                }
            }
        }
        ThumbnailProfilingRecorder::log($run);

        return $run;
    }

    /**
     * Called when {@see \App\Jobs\GenerateThumbnailsJob} catches an exception so incomplete runs are logged.
     */
    public function abandonProfilingAfterJobFailure(): void
    {
        $this->abandonThumbnailProfiling();
    }

    protected function abandonThumbnailProfiling(): void
    {
        $this->resetGdRasterOrientationCache();
        if ($this->thumbnailProfilingRun === null) {
            $this->rasterOrientationProfile = null;
            ThumbnailProfilingRecorder::consumeJobContext();

            return;
        }
        $run = $this->thumbnailProfilingRun;
        $this->thumbnailProfilingRun = null;
        $t0 = (float) ($run['_t0'] ?? microtime(true));
        unset($run['_t0'], $run['_mark']);
        if ($this->rasterOrientationProfile !== null) {
            $run['raster_orientation'] = $this->rasterOrientationProfile;
        }
        $this->rasterOrientationProfile = null;
        $run['success'] = false;
        $run['incomplete'] = true;
        $run['total_service_ms'] = (int) round((microtime(true) - $t0) * 1000.0);
        $run['finished_at'] = now()->toIso8601String();
        $run = array_merge(ThumbnailProfilingRecorder::consumeJobContext(), $run);
        if (isset($run['raster_orientation']) && is_array($run['raster_orientation'])) {
            foreach (['job_class', 'worker_queue', 'queue_job_id', 'queue_wait_ms'] as $jk) {
                if (! array_key_exists($jk, $run['raster_orientation']) && array_key_exists($jk, $run)) {
                    $run['raster_orientation'][$jk] = $run[$jk];
                }
            }
        }
        ThumbnailProfilingRecorder::log($run);
    }

    /**
     * Download original bytes to a temp file using the same routing as {@see generateThumbnails} (diagnostics only).
     */
    public function downloadOriginalToTempForDiagnostics(Asset $asset, string $sourceS3Path): string
    {
        $asset->loadMissing('storageBucket');
        $bucket = $asset->storageBucket;
        if ($bucket !== null) {
            return $this->downloadFromS3($bucket, $sourceS3Path, $asset->id);
        }

        return $this->downloadSourceToTempForThumbnails($asset, $sourceS3Path);
    }

    /**
     * Align internal generation state with {@see generateThumbnails} before preferred-crop / raster steps (diagnostics only).
     */
    public function resetDiagnosticsGenerationState(string $mode): void
    {
        $this->generationMode = ThumbnailMode::normalize($mode);
        $this->preferredCropSummary = null;
        $this->preferredPdfRasterCachePath = null;
        $this->preferredVideoRasterCachePath = null;
        $this->officeLibreOfficeWorkDir = null;
        $this->officeIntermediatePdfPath = null;
    }

    /**
     * @return array Same shape as {@see applyPreferredSmartOrPrintCrop}
     */
    public function applyPreferredCropForDiagnostics(string $imagePath): array
    {
        return $this->applyPreferredSmartOrPrintCrop($imagePath);
    }

    public function detectFileTypeForDiagnostics(Asset $asset, ?AssetVersion $version = null): string
    {
        return $this->detectFileType($asset, $version);
    }

    /**
     * Generate a single configured style to a temp file (diagnostics only; does not upload or persist metadata).
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function generateOneThumbnailStyleForDiagnostics(Asset $asset, string $localSourcePath, string $styleName, string $fileType): string
    {
        $styles = config('assets.thumbnail_styles', []);
        if (! isset($styles[$styleName]) || ! is_array($styles[$styleName])) {
            throw new \InvalidArgumentException("Unknown thumbnail style \"{$styleName}\" (configure assets.thumbnail_styles).");
        }
        $styleConfig = $styles[$styleName];
        $out = $this->generateThumbnail($asset, $localSourcePath, $styleName, $styleConfig, $fileType, false);
        if ($out === null || ! is_file($out)) {
            throw new \RuntimeException('Thumbnail generation produced no output file for style '.$styleName.'.');
        }

        return $out;
    }

    /**
     * Regenerate specific thumbnail styles for an asset.
     *
     * Admin-only method for regenerating specific thumbnail styles.
     * Used for troubleshooting or testing new file types.
     *
     * @param  Asset  $asset  The asset to regenerate thumbnails for
     * @param  array  $styleNames  Array of style names to regenerate (e.g., ['thumb', 'medium', 'large'])
     * @param  bool  $forceImageMagick  If true, bypass file type checks and force ImageMagick usage (admin override for testing)
     * @return array Array of regenerated thumbnail metadata
     *
     * @throws \RuntimeException If regeneration fails
     */
    public function regenerateThumbnailStyles(Asset $asset, array $styleNames, bool $forceImageMagick = false, string $mode = 'original'): array
    {
        $mode = ThumbnailMode::normalize($mode);

        if (! $asset->storage_root_path) {
            throw new \RuntimeException('Asset missing storage path');
        }

        $asset->loadMissing('storageBucket');
        $sourceS3Path = $asset->storage_root_path;
        $bucket = $asset->storageBucket;
        $fallbackUploadDisk = null;
        if (! $bucket) {
            $fallbackUploadDisk = EditorAssetOriginalBytesLoader::resolveFallbackDiskForObjectKey($asset, $sourceS3Path);
            if ($fallbackUploadDisk === null) {
                throw new \RuntimeException('Asset missing storage path or bucket');
            }
        }

        $allStyles = config('assets.thumbnail_styles', []);

        // Filter to only requested styles
        $styles = [];
        foreach ($styleNames as $styleName) {
            if (! isset($allStyles[$styleName])) {
                throw new \RuntimeException("Invalid thumbnail style: {$styleName}");
            }
            $styles[$styleName] = $allStyles[$styleName];
        }

        if (empty($styles)) {
            throw new \RuntimeException('No valid thumbnail styles specified');
        }

        // Download source file (same logic as generateThumbnails)
        $outputBasePath = dirname($sourceS3Path);
        $tempPath = $bucket !== null
            ? $this->downloadFromS3($bucket, $sourceS3Path, $asset->id)
            : $this->downloadSourceToTempForThumbnails($asset, $sourceS3Path);

        if (! file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Downloaded source file is invalid or empty');
        }

        // Admin override: If forceImageMagick is true, bypass file type detection and use ImageMagick
        $fileType = $forceImageMagick ? 'imagick_override' : $this->detectFileType($asset, null);
        $regenerated = [];

        try {
            // Generate only requested styles (no version - legacy path)
            foreach ($styles as $styleName => $styleConfig) {
                try {
                    $thumbnailPath = $this->generateThumbnail(
                        $asset,
                        $tempPath,
                        $styleName,
                        $styleConfig,
                        $fileType,
                        $forceImageMagick
                    );

                    if ($thumbnailPath && file_exists($thumbnailPath)) {
                        // Upload to S3 (no version - uses outputBasePath)
                        $s3ThumbnailPath = $this->uploadThumbnailToS3(
                            $bucket,
                            $asset,
                            $thumbnailPath,
                            $styleName,
                            $outputBasePath,
                            null,
                            $mode,
                            $fallbackUploadDisk
                        );

                        // Get metadata
                        $thumbnailInfo = $this->getThumbnailMetadata($thumbnailPath);

                        $regenerated[$styleName] = [
                            'path' => $s3ThumbnailPath,
                            'width' => $thumbnailInfo['width'] ?? null,
                            'height' => $thumbnailInfo['height'] ?? null,
                            'size_bytes' => $thumbnailInfo['size_bytes'] ?? filesize($thumbnailPath),
                            'generated_at' => now()->toIso8601String(),
                        ];

                        @unlink($thumbnailPath);
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to regenerate thumbnail style '{$styleName}'", [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with other styles
                }
            }

            // Build structured return (caller persists - no Asset mutation)
            $previewStyles = [];
            $finalStyles = [];
            $thumbnailDimensions = [];

            foreach ($regenerated as $styleName => $styleData) {
                if ($styleName === 'preview') {
                    $previewStyles[$styleName] = $styleData;
                } else {
                    $finalStyles[$styleName] = $styleData;
                    if (isset($styleData['width'], $styleData['height'])) {
                        $thumbnailDimensions[$styleName] = [
                            'width' => $styleData['width'],
                            'height' => $styleData['height'],
                        ];
                    }
                }
            }

            return [
                'thumbnails' => [$mode => $finalStyles],
                'preview_thumbnails' => [$mode => $previewStyles],
                'thumbnail_dimensions' => [$mode => $thumbnailDimensions],
                'regenerated' => $regenerated,
            ];
        } finally {
            // Clean up temporary file
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Generate thumbnails for a version (Phase 3A). No model mutation.
     *
     * @param  AssetVersion  $version  The version to generate thumbnails for
     * @return array Array of thumbnail metadata
     */
    public function generateThumbnailsForVersion(AssetVersion $version, string $mode = 'original'): array
    {
        return $this->generateThumbnails(
            $version->asset,
            $version->file_path,
            dirname($version->file_path),
            $version,
            $mode
        );
    }

    /**
     * Generate all thumbnail styles for an asset atomically.
     *
     * Downloads the asset from S3, generates all configured thumbnail styles,
     * uploads thumbnails to S3, and returns metadata about generated thumbnails.
     *
     * @param  Asset  $asset  The asset to generate thumbnails for
     * @param  string|null  $sourceS3Path  Override source path (default: asset->storage_root_path)
     * @param  string|null  $outputBasePath  Override output base for thumbnails (default: dirname of source)
     * @param  AssetVersion|null  $version  When provided, use version->mime_type for file type detection (version-aware)
     * @param  string  $mode  Thumbnail mode ({@see ThumbnailMode}); default {@see ThumbnailMode::Original}
     * @return array Nested thumbnail metadata: thumbnails, preview_thumbnails, thumbnail_dimensions keyed by mode
     *
     * @throws \RuntimeException If thumbnail generation fails
     */
    public function generateThumbnails(Asset $asset, ?string $sourceS3Path = null, ?string $outputBasePath = null, ?AssetVersion $version = null, string $mode = 'original'): array
    {
        $mode = ThumbnailMode::normalize($mode);
        $this->generationMode = $mode;
        $this->preferredCropSummary = null;
        $this->preferredPdfRasterCachePath = null;
        $this->preferredVideoRasterCachePath = null;
        $this->officeLibreOfficeWorkDir = null;
        $this->officeIntermediatePdfPath = null;

        $sourceS3Path = $sourceS3Path ?? $asset->storage_root_path;
        $outputBasePath = $outputBasePath ?? ($sourceS3Path ? dirname($sourceS3Path) : null);

        if (! $sourceS3Path) {
            throw new \RuntimeException('Asset missing storage path');
        }

        $asset->loadMissing('storageBucket');
        $bucket = $asset->storageBucket;
        $fallbackUploadDisk = null;
        if (! $bucket) {
            $fallbackUploadDisk = EditorAssetOriginalBytesLoader::resolveFallbackDiskForObjectKey($asset, $sourceS3Path);
            if ($fallbackUploadDisk === null) {
                throw new \RuntimeException('Asset missing storage path or bucket');
            }
        }

        $styles = config('assets.thumbnail_styles', []);

        if (empty($styles)) {
            throw new \RuntimeException('No thumbnail styles configured');
        }

        $this->beginThumbnailProfiling($asset, $version, $mode);

        Log::info('[ThumbnailGenerationService] Generating thumbnails from source', [
            'asset_id' => $asset->id,
            'source_s3_path' => $sourceS3Path,
            'bucket' => $bucket?->name,
            'fallback_disk' => $fallbackUploadDisk,
        ]);

        // Download original file to temporary location
        // This is the SAME source file used for metadata extraction
        $tempPath = $bucket !== null
            ? $this->downloadFromS3($bucket, $sourceS3Path, $asset->id)
            : $this->downloadSourceToTempForThumbnails($asset, $sourceS3Path);

        // Verify downloaded file is valid
        if (! file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Downloaded source file is invalid or empty');
        }

        $sourceFileSize = filesize($tempPath);
        $this->thumbProfilingLap('download_original_ms');

        // Detect file type: use version->mime_type when version-aware (from FileInspectionService)
        $fileType = $this->detectFileType($asset, $version);

        // Capture source image dimensions ONLY for image file types (not PDFs, videos, or other types)
        // Dimensions are read from the ORIGINAL source file, not from thumbnails
        // Some file types (PDFs, videos, documents) do not have pixel dimensions
        $sourceImageWidth = null;
        $sourceImageHeight = null;
        $pdfPageCount = null;

        if ($fileType === 'pdf') {
            // PDF validation: Check file size and basic PDF structure
            // Full PDF validation happens during thumbnail generation
            // PDFs do not have pixel dimensions - skip dimension capture
            try {
                $pdfPageCount = $this->detectPdfPageCount($tempPath);
            } catch (\Throwable $e) {
                Log::warning('[ThumbnailGenerationService] Failed to detect PDF page count; defaulting to page 1', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
                $pdfPageCount = 1;
            }
            Log::info('[ThumbnailGenerationService] Source PDF file downloaded and verified', [
                'asset_id' => $asset->id,
                'temp_path' => $tempPath,
                'source_file_size' => $sourceFileSize,
                'file_type' => 'pdf',
                'pdf_page_count' => $pdfPageCount,
            ]);
        } elseif ($fileType === 'tiff') {
            // TIFF validation: Use Imagick pingImage (headers only, no pixel load) to get dimensions
            // Avoids loading 700MB+ TIFF into memory just to read dimensions
            if (! extension_loaded('imagick')) {
                throw new \RuntimeException('TIFF file processing requires Imagick PHP extension');
            }

            try {
                $imagick = new \Imagick;
                $imagick->pingImage($tempPath);
                $imagick->setIteratorIndex(0);
                $sourceImageWidth = (int) $imagick->getImageWidth();
                $sourceImageHeight = (int) $imagick->getImageHeight();
                $imagick->clear();
                $imagick->destroy();

                if ($sourceImageWidth === 0 || $sourceImageHeight === 0) {
                    throw new \RuntimeException("TIFF file has invalid dimensions (size: {$sourceFileSize} bytes)");
                }

                Log::info('[ThumbnailGenerationService] Source TIFF file downloaded and verified', [
                    'asset_id' => $asset->id,
                    'temp_path' => $tempPath,
                    'source_file_size' => $sourceFileSize,
                    'source_image_width' => $sourceImageWidth,
                    'source_image_height' => $sourceImageHeight,
                    'file_type' => 'tiff',
                ]);
            } catch (\ImagickException $e) {
                throw new \RuntimeException("Downloaded file is not a valid TIFF image: {$e->getMessage()}");
            }
        } elseif ($fileType === 'cr2') {
            if (! extension_loaded('imagick')) {
                throw new \RuntimeException('Canon CR2 processing requires Imagick PHP extension');
            }

            try {
                $imagick = new \Imagick;
                $imagick->pingImage($tempPath.'[0]');
                $imagick->setIteratorIndex(0);
                $sourceImageWidth = (int) $imagick->getImageWidth();
                $sourceImageHeight = (int) $imagick->getImageHeight();
                $imagick->clear();
                $imagick->destroy();

                if ($sourceImageWidth === 0 || $sourceImageHeight === 0) {
                    throw new \RuntimeException("CR2 file has invalid dimensions (size: {$sourceFileSize} bytes)");
                }

                Log::info('[ThumbnailGenerationService] Source CR2 file downloaded and verified', [
                    'asset_id' => $asset->id,
                    'temp_path' => $tempPath,
                    'source_file_size' => $sourceFileSize,
                    'source_image_width' => $sourceImageWidth,
                    'source_image_height' => $sourceImageHeight,
                    'file_type' => 'cr2',
                ]);
            } catch (\ImagickException $e) {
                throw new \RuntimeException("Downloaded file is not a valid CR2 image: {$e->getMessage()}");
            }
        } elseif ($fileType === 'avif') {
            // AVIF validation: Use Imagick pingImage (headers only) to get dimensions
            if (! extension_loaded('imagick')) {
                throw new \RuntimeException('AVIF file processing requires Imagick PHP extension');
            }

            try {
                $imagick = new \Imagick;
                $imagick->pingImage($tempPath);
                $imagick->setIteratorIndex(0);
                $sourceImageWidth = (int) $imagick->getImageWidth();
                $sourceImageHeight = (int) $imagick->getImageHeight();
                $imagick->clear();
                $imagick->destroy();

                if ($sourceImageWidth === 0 || $sourceImageHeight === 0) {
                    throw new \RuntimeException("AVIF file has invalid dimensions (size: {$sourceFileSize} bytes)");
                }

                Log::info('[ThumbnailGenerationService] Source AVIF file downloaded and verified', [
                    'asset_id' => $asset->id,
                    'temp_path' => $tempPath,
                    'source_file_size' => $sourceFileSize,
                    'source_image_width' => $sourceImageWidth,
                    'source_image_height' => $sourceImageHeight,
                    'file_type' => 'avif',
                ]);
            } catch (\ImagickException $e) {
                throw new \RuntimeException("Downloaded file is not a valid AVIF image: {$e->getMessage()}");
            }
        } elseif ($fileType === 'heic') {
            if (! extension_loaded('imagick')) {
                throw new \RuntimeException('HEIC file processing requires Imagick PHP extension');
            }

            try {
                $imagick = new \Imagick;
                $imagick->pingImage($tempPath.'[0]');
                $imagick->setIteratorIndex(0);
                $sourceImageWidth = (int) $imagick->getImageWidth();
                $sourceImageHeight = (int) $imagick->getImageHeight();
                $imagick->clear();
                $imagick->destroy();

                if ($sourceImageWidth === 0 || $sourceImageHeight === 0) {
                    throw new \RuntimeException("HEIC file has invalid dimensions (size: {$sourceFileSize} bytes)");
                }

                Log::info('[ThumbnailGenerationService] Source HEIC file downloaded and verified', [
                    'asset_id' => $asset->id,
                    'temp_path' => $tempPath,
                    'source_file_size' => $sourceFileSize,
                    'source_image_width' => $sourceImageWidth,
                    'source_image_height' => $sourceImageHeight,
                    'file_type' => 'heic',
                ]);
            } catch (\ImagickException $e) {
                throw new \RuntimeException("Downloaded file is not a valid HEIC image: {$e->getMessage()}");
            }
        } elseif ($fileType === 'svg') {
            // SVG validation: Basic XML structure check
            $content = file_get_contents($tempPath, false, null, 0, 500);
            if ($content === false || ! preg_match('/<\s*(svg|\?xml)/i', $content)) {
                throw new \RuntimeException("Downloaded file does not appear to be a valid SVG (size: {$sourceFileSize} bytes)");
            }
            Log::info('[ThumbnailGenerationService] Source SVG file downloaded and verified', [
                'asset_id' => $asset->id,
                'temp_path' => $tempPath,
                'source_file_size' => $sourceFileSize,
                'file_type' => 'svg',
            ]);
        } elseif ($fileType === 'image') {
            // Image validation: Verify file is actually an image (not corrupted or wrong format)
            // CRITICAL: Get dimensions from ORIGINAL source file using getimagesize()
            // These are the actual pixel dimensions of the original image, not thumbnails
            $imageInfo = @getimagesize($tempPath);
            if ($imageInfo === false) {
                // Fallback: File may be SVG misdetected as image (wrong MIME/extension after replace).
                // SVG is XML, not raster - getimagesize() fails. Check content and re-route to SVG path.
                $content = file_get_contents($tempPath, false, null, 0, 500);
                if ($content !== false && preg_match('/<\s*(svg|\?xml)/i', $content)) {
                    Log::info('[ThumbnailGenerationService] File detected as SVG via content (was misdetected as image)', [
                        'asset_id' => $asset->id,
                        'source_file_size' => $sourceFileSize,
                    ]);
                    $fileType = 'svg';
                } else {
                    throw new \RuntimeException("Downloaded file is not a valid image (size: {$sourceFileSize} bytes)");
                }
            }

            // Capture source dimensions from original image file (raster only; SVG has no dimensions here)
            if ($imageInfo !== false) {
                $sourceImageWidth = (int) ($imageInfo[0] ?? 0);
                $sourceImageHeight = (int) ($imageInfo[1] ?? 0);
                Log::info('[ThumbnailGenerationService] Source image file downloaded and verified', [
                    'asset_id' => $asset->id,
                    'temp_path' => $tempPath,
                    'source_file_size' => $sourceFileSize,
                    'source_image_width' => $sourceImageWidth,
                    'source_image_height' => $sourceImageHeight,
                    'image_type' => $imageInfo[2] ?? null,
                ]);
            }
        } else {
            // Other file types (videos, documents, etc.) - no pixel dimensions available
            Log::info('[ThumbnailGenerationService] Source file downloaded (non-image type)', [
                'asset_id' => $asset->id,
                'temp_path' => $tempPath,
                'source_file_size' => $sourceFileSize,
                'file_type' => $fileType,
            ]);
        }

        $this->thumbProfilingLap('type_probe_and_dimensions_ms');

        if ($mode === ThumbnailMode::Preferred->value
            && in_array($fileType, ['image', 'tiff', 'avif', 'cr2', 'heic'], true)
        ) {
            $crop = $this->applyPreferredSmartOrPrintCrop($tempPath);
            $this->preferredCropSummary = $crop;
            if (($crop['applied'] ?? false)
                && ($crop['path'] ?? '') !== ''
                && $crop['path'] !== $tempPath
                && is_file($crop['path'])) {
                @unlink($tempPath);
                $tempPath = $crop['path'];
            }

            if (($crop['applied'] ?? false) && $fileType === 'image') {
                $imageInfo = @getimagesize($tempPath);
                if ($imageInfo !== false) {
                    $sourceImageWidth = (int) ($imageInfo[0] ?? 0);
                    $sourceImageHeight = (int) ($imageInfo[1] ?? 0);
                }
            } elseif (($crop['applied'] ?? false) && in_array($fileType, ['tiff', 'avif', 'cr2', 'heic'], true) && extension_loaded('imagick')) {
                try {
                    $imagickProbe = new \Imagick;
                    $probePath = in_array($fileType, ['cr2', 'heic'], true) ? $tempPath.'[0]' : $tempPath;
                    $imagickProbe->pingImage($probePath);
                    $imagickProbe->setIteratorIndex(0);
                    $sourceImageWidth = (int) $imagickProbe->getImageWidth();
                    $sourceImageHeight = (int) $imagickProbe->getImageHeight();
                    $imagickProbe->clear();
                    $imagickProbe->destroy();
                } catch (\Throwable) {
                }
            }
        }

        $this->thumbProfilingLap('preferred_crop_pipeline_ms');

        try {
            // Determine file type and generate thumbnails
            // File type was already detected above, reuse it
            // This ensures consistent file type detection throughout the method
            $thumbnails = [];
            $officePreviewPdfPath = null;

            // Step 6: Generate preview thumbnail FIRST (before final thumbnails)
            // This provides immediate visual feedback while final thumbnails process
            // Preview is optional but preferred - if it fails, continue with final generation
            // CRITICAL: Preview must be generated from the SAME source file as final thumbnails
            // to ensure it resembles the original image (blurred, not garbage)
            $previewThumbnails = [];
            if (isset($styles['preview'])) {
                try {
                    Log::info('[ThumbnailGenerationService] Generating preview thumbnail', [
                        'asset_id' => $asset->id,
                        'source_temp_path' => $tempPath,
                        'source_file_size' => filesize($tempPath),
                        'file_type' => $fileType,
                    ]);

                    $previewPath = $this->generateThumbnail(
                        $asset,
                        $tempPath, // SAME source as final thumbnails
                        'preview',
                        $styles['preview'],
                        $fileType,
                        false // Never force ImageMagick for normal generation
                    );

                    if ($previewPath && file_exists($previewPath)) {
                        $previewFileSize = filesize($previewPath);
                        Log::info('[ThumbnailGenerationService] Preview thumbnail generated', [
                            'asset_id' => $asset->id,
                            'preview_path' => $previewPath,
                            'preview_file_size' => $previewFileSize,
                        ]);

                        // Upload preview thumbnail to S3
                        $s3PreviewPath = $this->uploadThumbnailToS3(
                            $bucket,
                            $asset,
                            $previewPath,
                            'preview',
                            $outputBasePath,
                            $version,
                            $mode,
                            $fallbackUploadDisk
                        );

                        // Get preview thumbnail metadata
                        $previewInfo = $this->getThumbnailMetadata($previewPath);

                        $previewThumbnails['preview'] = [
                            'path' => $s3PreviewPath,
                            'width' => $previewInfo['width'] ?? null,
                            'height' => $previewInfo['height'] ?? null,
                            'size_bytes' => $previewInfo['size_bytes'] ?? filesize($previewPath),
                            'generated_at' => now()->toIso8601String(),
                        ];

                        Log::info('[ThumbnailGenerationService] Preview thumbnail uploaded to S3', [
                            'asset_id' => $asset->id,
                            's3_path' => $s3PreviewPath,
                            'width' => $previewInfo['width'] ?? null,
                            'height' => $previewInfo['height'] ?? null,
                        ]);

                        // LQIP: persist preview_thumbnails to DB immediately so the API can expose
                        // preview_thumbnail_url while final thumb/medium/large are still generating.
                        // Previously metadata was only written at job completion, so the grid saw no blur
                        // for the entire PROCESSING window (see docs/MEDIA_PIPELINE.md).
                        $this->persistEarlyLqipMetadata($asset, $previewThumbnails, $version, $mode);

                        // Clean up local preview thumbnail
                        @unlink($previewPath);
                    } else {
                        Log::warning('[ThumbnailGenerationService] Preview thumbnail generation returned null or file missing', [
                            'asset_id' => $asset->id,
                            'preview_path' => $previewPath ?? 'null',
                        ]);
                    }
                } catch (\Exception $e) {
                    // Preview generation failure is non-fatal - continue with final thumbnails
                    Log::warning('[ThumbnailGenerationService] Failed to generate preview thumbnail (non-fatal)', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            $this->thumbProfilingLap('preview_block_ms');

            // Degraded mode: skip medium/large for files exceeding pixel cap (prevents OOM on 700MB+ TIFFs)
            $maxPixels = (int) config('assets.thumbnail.max_pixels', 200_000_000);
            $degradedMode = $sourceImageWidth && $sourceImageHeight
                && (($sourceImageWidth * $sourceImageHeight) > $maxPixels);
            if ($degradedMode) {
                Log::info('[ThumbnailGenerationService] Degraded mode: pixel area exceeds cap, skipping medium/large', [
                    'asset_id' => $asset->id,
                    'pixel_area' => $sourceImageWidth * $sourceImageHeight,
                    'max_pixels' => $maxPixels,
                ]);
            }

            // Generate final thumbnails (thumb, medium, large) - exclude preview
            foreach ($styles as $styleName => $styleConfig) {
                // Skip preview - already generated above
                if ($styleName === 'preview') {
                    continue;
                }
                // Degraded mode: only preview + thumb; skip medium and large
                if ($degradedMode && in_array($styleName, ['medium', 'large'], true)) {
                    Log::info('[ThumbnailGenerationService] Skipping style in degraded mode', [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                    ]);

                    continue;
                }
                try {
                    $tGen = microtime(true);
                    $thumbnailPath = $this->generateThumbnail(
                        $asset,
                        $tempPath,
                        $styleName,
                        $styleConfig,
                        $fileType,
                        false // Never force ImageMagick for normal generation
                    );
                    $genMs = (int) round((microtime(true) - $tGen) * 1000.0);

                    if ($thumbnailPath) {
                        $tUp = microtime(true);
                        // Upload thumbnail to S3
                        $s3ThumbnailPath = $this->uploadThumbnailToS3(
                            $bucket,
                            $asset,
                            $thumbnailPath,
                            $styleName,
                            $outputBasePath,
                            $version,
                            $mode,
                            $fallbackUploadDisk
                        );
                        $upMs = (int) round((microtime(true) - $tUp) * 1000.0);

                        // Get thumbnail metadata
                        $thumbnailInfo = $this->getThumbnailMetadata($thumbnailPath);

                        $thumbnails[$styleName] = [
                            'path' => $s3ThumbnailPath,
                            'width' => $thumbnailInfo['width'] ?? null,
                            'height' => $thumbnailInfo['height'] ?? null,
                            'size_bytes' => $thumbnailInfo['size_bytes'] ?? filesize($thumbnailPath),
                            'generated_at' => now()->toIso8601String(),
                        ];

                        $this->thumbProfilingDerivative(
                            $styleName,
                            $genMs,
                            $upMs,
                            isset($thumbnailInfo['size_bytes']) ? (int) $thumbnailInfo['size_bytes'] : (is_file($thumbnailPath) ? (int) filesize($thumbnailPath) : null),
                            isset($thumbnailInfo['width']) ? (int) $thumbnailInfo['width'] : null,
                            isset($thumbnailInfo['height']) ? (int) $thumbnailInfo['height'] : null,
                        );

                        // Clean up local thumbnail
                        @unlink($thumbnailPath);
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to generate thumbnail style '{$styleName}' for asset", [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with other styles even if one fails
                }
            }

            $this->thumbProfilingLap('final_styles_loop_ms');

            // Build thumbnail_dimensions from generated thumbnails
            $thumbnailDimensions = [];
            foreach (['thumb', 'medium', 'large'] as $style) {
                if (isset($thumbnails[$style]['width'], $thumbnails[$style]['height'])) {
                    $thumbnailDimensions[$style] = [
                        'width' => $thumbnails[$style]['width'],
                        'height' => $thumbnails[$style]['height'],
                    ];
                }
            }

            // Guard: Low-resolution raster for SVG/PDF — report diagnostic, do NOT fail job
            if (in_array($fileType, ['svg', 'pdf'], true)) {
                $mediumWidth = $thumbnails['medium']['width'] ?? 0;
                if ($mediumWidth > 0 && $mediumWidth < 1000) {
                    try {
                        app(\App\Services\Reliability\ReliabilityEngine::class)->report([
                            'source_type' => 'asset',
                            'source_id' => $asset->id,
                            'tenant_id' => $asset->tenant_id,
                            'severity' => 'warning',
                            'context' => 'low_raster_quality',
                            'title' => 'Vector rasterized below quality threshold',
                            'message' => 'Vector rasterized below quality threshold',
                            'metadata' => [
                                'file_type' => $fileType,
                                'medium_width' => $mediumWidth,
                                'mime_type' => $asset->mime_type,
                            ],
                            'unique_signature' => "low_raster_quality:{$asset->id}:{$mediumWidth}",
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('[ThumbnailGenerationService] Failed to report low raster quality', [
                            'asset_id' => $asset->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Source image dimensions (raster types only)
            if (($fileType === 'image' || $fileType === 'tiff' || $fileType === 'cr2' || $fileType === 'avif' || $fileType === 'heic') && $sourceImageWidth && $sourceImageHeight) {
                Log::info('[ThumbnailGenerationService] Captured original source image dimensions', [
                    'asset_id' => $asset->id,
                    'source_image_width' => $sourceImageWidth,
                    'source_image_height' => $sourceImageHeight,
                ]);
            }

            // Office: persist LibreOffice PDF to S3 so PDF page rendering can paginate like native PDFs.
            if ($fileType === 'office'
                && is_string($this->officeIntermediatePdfPath)
                && is_file($this->officeIntermediatePdfPath)
            ) {
                try {
                    $maxAllowedPages = (int) config('pdf.max_allowed_pages', 500);
                    $detectedPages = $this->detectPdfPageCount($this->officeIntermediatePdfPath);
                    if ($detectedPages > $maxAllowedPages) {
                        Log::warning('[ThumbnailGenerationService] Office converted PDF exceeds page limit; skipping preview PDF upload', [
                            'asset_id' => $asset->id,
                            'page_count' => $detectedPages,
                            'max_allowed_pages' => $maxAllowedPages,
                        ]);
                    } else {
                        $pdfPageCount = $detectedPages;
                        $officePreviewPdfPath = $this->uploadOfficePreviewPdfToStorage(
                            $bucket,
                            $asset,
                            $this->officeIntermediatePdfPath,
                            $version,
                            $fallbackUploadDisk
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('[ThumbnailGenerationService] Office preview PDF persist failed', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $pdfPageCount = null;
                    $officePreviewPdfPath = null;
                }
            }

            $this->thumbProfilingLap('post_styles_metadata_ms');

            $payload = [
                'thumbnails' => [$mode => $thumbnails],
                'preview_thumbnails' => [$mode => $previewThumbnails],
                'thumbnail_dimensions' => [$mode => $thumbnailDimensions],
                'image_width' => $sourceImageWidth,
                'image_height' => $sourceImageHeight,
                'thumbnail_quality' => $degradedMode ? 'degraded_large_skipped' : null,
                'pdf_page_count' => $pdfPageCount,
                'office_preview_pdf_path' => $officePreviewPdfPath,
                'thumbnail_modes_meta' => $this->buildThumbnailModesMetaPayload(),
            ];
            $profRow = $this->completeThumbnailProfiling(
                is_int($sourceFileSize) ? $sourceFileSize : null,
                $sourceImageWidth,
                $sourceImageHeight,
                true,
            );
            if ($profRow !== null) {
                $payload['_thumbnail_profiling'] = $profRow;
            }

            return $payload;
        } finally {
            $this->abandonThumbnailProfiling();
            $this->unlinkPreferredRasterCaches();
            // Clean up temporary file
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Preferred pipeline: dominant-ink bounding box crop when valid, else smart trim.
     * No print-layout detection gate — see {@see PrintLayoutCropService}.
     *
     * @return array{
     *   path: string,
     *   applied: bool,
     *   confidence: float,
     *   skip_reason?: string|null,
     *   crop_type?: string,
     *   detection_confidence?: float,
     *   signals?: array<string, mixed>
     * }
     */
    protected function applyPreferredSmartOrPrintCrop(string $imagePath): array
    {
        $print = app(PrintLayoutCropService::class)->cropPrintLayout($imagePath);

        if (($print['applied'] ?? false) === true
            && ($print['path'] ?? '') !== ''
            && $print['path'] !== $imagePath
            && is_file((string) $print['path'])) {
            return [
                'path' => $print['path'],
                'applied' => true,
                'confidence' => min(1.0, max((float) ($print['confidence'] ?? 0.65), 0.55)),
                'crop_type' => 'print_ready_bbox',
                'detection_confidence' => 0.0,
                'signals' => [
                    'trim_ratio' => null,
                    'edge_density' => null,
                    'padding_applied' => true,
                    'print_layout' => [],
                ],
                'skip_reason' => null,
            ];
        }

        if (! (bool) config('assets.print_layout.fallback_to_smart_crop', true)) {
            return [
                'path' => $imagePath,
                'applied' => false,
                'confidence' => 0.0,
                'crop_type' => 'print_ready_bbox',
                'detection_confidence' => 0.0,
                'signals' => [
                    'trim_ratio' => null,
                    'edge_density' => null,
                    'padding_applied' => false,
                    'print_layout' => [
                        'skipped' => true,
                        'skip_reason' => (string) ($print['skip_reason'] ?? 'unknown'),
                    ],
                ],
                'skip_reason' => (string) ($print['skip_reason'] ?? 'print_crop_skipped'),
            ];
        }

        $smart = app(ThumbnailSmartCropService::class);
        $crop = $smart->smartCrop($imagePath);
        $crop['crop_type'] = ($crop['applied'] ?? false) ? 'smart' : 'none';
        $crop['detection_confidence'] = 0.0;
        if (! isset($crop['signals']) || ! is_array($crop['signals'])) {
            $crop['signals'] = [];
        }
        $crop['signals']['print_layout'] = [];

        return $crop;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function buildThumbnailModesMetaPayload(): array
    {
        if ($this->generationMode !== ThumbnailMode::Preferred->value) {
            return [];
        }

        $s = $this->preferredCropSummary ?? [];
        $applied = (bool) ($s['applied'] ?? false);
        $signals = $s['signals'] ?? [
            'trim_ratio' => null,
            'edge_density' => null,
            'padding_applied' => false,
        ];
        if (! is_array($signals)) {
            $signals = [
                'trim_ratio' => null,
                'edge_density' => null,
                'padding_applied' => false,
            ];
        }

        $printLayoutSignals = $signals['print_layout'] ?? null;
        if (! is_array($printLayoutSignals)) {
            $printLayoutSignals = null;
        }

        return [
            'preferred' => [
                'applied' => $applied,
                'crop_applied' => $applied,
                'confidence' => (float) ($s['confidence'] ?? 0.0),
                'crop_type' => (string) ($s['crop_type'] ?? ($applied ? 'smart' : 'none')),
                'detection_confidence' => (float) ($s['detection_confidence'] ?? 0.0),
                'skip_reason' => $s['skip_reason'] ?? null,
                'signals' => [
                    'trim_ratio' => $signals['trim_ratio'] ?? null,
                    'edge_density' => $signals['edge_density'] ?? null,
                    'padding_applied' => (bool) ($signals['padding_applied'] ?? false),
                    'print_layout' => $printLayoutSignals,
                ],
            ],
        ];
    }

    protected function unlinkPreferredRasterCaches(): void
    {
        if ($this->preferredPdfRasterCachePath !== null && is_file($this->preferredPdfRasterCachePath)) {
            @unlink($this->preferredPdfRasterCachePath);
        }
        $this->preferredPdfRasterCachePath = null;

        if ($this->preferredVideoRasterCachePath !== null && is_file($this->preferredVideoRasterCachePath)) {
            @unlink($this->preferredVideoRasterCachePath);
        }
        $this->preferredVideoRasterCachePath = null;

        if ($this->officeLibreOfficeWorkDir !== null && is_dir($this->officeLibreOfficeWorkDir)) {
            try {
                File::deleteDirectory($this->officeLibreOfficeWorkDir);
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        $this->officeLibreOfficeWorkDir = null;
        $this->officeIntermediatePdfPath = null;
    }

    protected function copyRasterToTemp(string $sourcePath): string
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'png';
        }
        $dest = tempnam(sys_get_temp_dir(), 'thumb_raster_copy_').'.'.$ext;
        if (! @copy($sourcePath, $dest)) {
            throw new \RuntimeException('Failed to copy raster temp for thumbnail pipeline');
        }

        return $dest;
    }

    /**
     * Write preview (LQIP) paths to asset metadata as soon as the tiny blurred file is on S3.
     *
     * Without this, GenerateThumbnailsJob only persisted thumbnails at the end of the job, so
     * preview_thumbnail_url was null for the whole PROCESSING interval and the grid showed icons.
     * Final metadata merge at job completion overwrites/aligns the same keys.
     */
    protected function persistEarlyLqipMetadata(Asset $asset, array $previewThumbnails, ?AssetVersion $version = null, string $mode = 'original'): void
    {
        $mode = ThumbnailMode::normalize($mode);

        if (empty($previewThumbnails['preview']['path'])) {
            return;
        }

        $mergeFn = function (array $meta) use ($previewThumbnails, $mode): array {
            $thumbs = $meta['thumbnails'] ?? [];
            if (! is_array($thumbs)) {
                $thumbs = [];
            }
            if (! isset($thumbs[$mode]) || ! is_array($thumbs[$mode])) {
                $thumbs[$mode] = [];
            }
            $thumbs[$mode]['preview'] = $previewThumbnails['preview'];
            $meta['thumbnails'] = $thumbs;

            $pt = $meta['preview_thumbnails'] ?? [];
            if (! is_array($pt)) {
                $pt = [];
            }
            if (! isset($pt[$mode]) || ! is_array($pt[$mode])) {
                $pt[$mode] = [];
            }
            $pt[$mode]['preview'] = $previewThumbnails['preview'];
            $meta['preview_thumbnails'] = $pt;

            return $meta;
        };

        try {
            if ($version !== null) {
                $version->refresh();
                $version->update([
                    'metadata' => $mergeFn($version->metadata ?? []),
                ]);
            }

            $asset->refresh();
            $asset->update([
                'metadata' => $mergeFn($asset->metadata ?? []),
            ]);

            Log::debug('[LQIP] Early persist: preview_thumbnails available before final thumbnails finish', [
                'asset_id' => $asset->id,
                'version_id' => $version?->id,
                'preview_path' => $previewThumbnails['preview']['path'],
                'mode' => $mode,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal: final job completion still writes preview_thumbnails
            Log::warning('[ThumbnailGenerationService] Early LQIP metadata persist failed (non-fatal)', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download file from S3 to temporary location.
     *
     * @param  int|string|null  $assetId  Optional asset ID for error context (appears in failed_jobs)
     * @return string Path to temporary file
     *
     * @throws \RuntimeException If download fails
     */
    protected function downloadFromS3(StorageBucket $bucket, string $s3Key, $assetId = null): string
    {
        $ctx = $assetId !== null ? " asset_id={$assetId}" : '';
        try {
            Log::info('[ThumbnailGenerationService] Downloading source file from S3', [
                'asset_id' => $assetId,
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
            ]);

            $result = $this->s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);

            $body = $result['Body'];
            $contentLength = isset($result['ContentLength']) ? (int) $result['ContentLength'] : null;
            $contentType = isset($result['ContentType']) ? strtolower((string) $result['ContentType']) : '';

            // PDF: use temp path with .pdf extension so ImageMagick can select the PDF delegate
            // (S3 key may not have .pdf e.g. "tenants/.../v1/original"; also check ContentType)
            $isPdf = str_ends_with(strtolower($s3Key), '.pdf') || str_contains($contentType, 'pdf');
            if ($isPdf) {
                do {
                    $tempPath = sys_get_temp_dir().'/thumb_'.Str::random(32).'.pdf';
                } while (file_exists($tempPath));
            } else {
                $tempPath = tempnam(sys_get_temp_dir(), 'thumb_');
            }

            // Stream to file to avoid loading large files into memory (e.g. 178MB TIFF in FPM or worker)
            $fp = fopen($tempPath, 'w');
            if (! $fp) {
                @unlink($tempPath);
                throw new \RuntimeException("Failed to open temp file for write{$ctx}");
            }
            try {
                while (! $body->eof()) {
                    $chunk = $body->read(65536);
                    if ($chunk === '') {
                        break;
                    }
                    fwrite($fp, $chunk);
                }
                fclose($fp);
                $fp = null;
            } catch (\Throwable $e) {
                if ($fp) {
                    fclose($fp);
                }
                @unlink($tempPath);
                throw $e;
            }

            if (! file_exists($tempPath) || filesize($tempPath) === 0) {
                @unlink($tempPath);
                throw new \RuntimeException("Downloaded file from S3 is empty (size: 0 bytes){$ctx}");
            }
            if ($contentLength !== null && filesize($tempPath) !== $contentLength) {
                @unlink($tempPath);
                throw new \RuntimeException("Downloaded file size mismatch (expected {$contentLength}, got ".filesize($tempPath)."){$ctx}");
            }

            Log::info('[ThumbnailGenerationService] Source file streamed from S3 to temp location', [
                'asset_id' => $assetId,
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
                'temp_path' => $tempPath,
                'file_size' => filesize($tempPath),
            ]);

            return $tempPath;
        } catch (S3Exception $e) {
            Log::error('[ThumbnailGenerationService] Failed to download asset from S3 for thumbnail generation', [
                'asset_id' => $assetId,
                'bucket' => $bucket->name,
                'key' => $s3Key,
                'error' => $e->getMessage(),
                'aws_error_code' => $e->getAwsErrorCode(),
            ]);
            throw new \RuntimeException("Failed to download asset from S3{$ctx}: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Download an arbitrary S3 object to a temp file (e.g. existing thumbnail raster).
     *
     * @param  int|string|null  $assetId
     */
    public function downloadObjectToTemp(StorageBucket $bucket, string $s3Key, $assetId = null): string
    {
        return $this->downloadFromS3($bucket, $s3Key, $assetId);
    }

    /**
     * ETag (preferred) or Last-Modified for fingerprinting thumbnail objects (enhanced preview staleness).
     */
    public function headObjectFingerprint(StorageBucket $bucket, string $s3Key): string
    {
        if ($s3Key === '') {
            return '';
        }
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);
            $etag = isset($result['ETag']) ? trim((string) $result['ETag'], '"') : '';
            if ($etag !== '') {
                return $etag;
            }
            $lm = $result['LastModified'] ?? null;
            if ($lm instanceof \DateTimeInterface) {
                return $lm->format('c');
            }

            return '';
        } catch (S3Exception $e) {
            Log::warning('[ThumbnailGenerationService] headObjectFingerprint failed', [
                'bucket' => $bucket->name,
                'key' => $s3Key,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Upload thumbnail to S3.
     * Uses AssetPathGenerator for canonical path: tenants/.../thumbnails/{mode}/{style}/{filename}
     *
     * @param  string|null  $outputBasePath  Unused; kept for signature compatibility
     * @param  AssetVersion|null  $version  When provided, version_number used for path
     * @return string S3 key path for the thumbnail
     *
     * @throws \RuntimeException If upload fails
     */
    protected function uploadThumbnailToS3(
        ?StorageBucket $bucket,
        Asset $asset,
        string $localThumbnailPath,
        string $styleName,
        ?string $outputBasePath = null,
        ?AssetVersion $version = null,
        string $mode = 'original',
        ?string $laravelDiskWhenNoBucket = null,
    ): string {
        $mode = ThumbnailMode::normalize($mode);
        $versionNumber = $version?->version_number ?? $asset->currentVersion?->version_number ?? 1;
        $extension = pathinfo($localThumbnailPath, PATHINFO_EXTENSION) ?: 'jpg';
        $thumbnailFilename = "{$styleName}.{$extension}";
        $pathGenerator = app(AssetPathGenerator::class);
        $s3ThumbnailPath = $pathGenerator->generateThumbnailPath($asset->tenant, $asset, $versionNumber, $mode, $styleName, $thumbnailFilename);

        $body = file_get_contents($localThumbnailPath);
        if ($body === false) {
            throw new \RuntimeException('Failed to read local thumbnail for upload');
        }

        if ($bucket !== null) {
            try {
                $this->s3Client->putObject([
                    'Bucket' => $bucket->name,
                    'Key' => $s3ThumbnailPath,
                    'Body' => $body,
                    'ContentType' => $this->getMimeTypeForExtension($extension),
                    'Metadata' => [
                        'original-asset-id' => $asset->id,
                        'style' => $styleName,
                        'mode' => $mode,
                        'generated-at' => now()->toIso8601String(),
                    ],
                ]);

                return $s3ThumbnailPath;
            } catch (S3Exception $e) {
                Log::error('Failed to upload thumbnail to S3', [
                    'bucket' => $bucket->name,
                    'key' => $s3ThumbnailPath,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException("Failed to upload thumbnail to S3: {$e->getMessage()}", 0, $e);
            }
        }

        if (! is_string($laravelDiskWhenNoBucket) || $laravelDiskWhenNoBucket === '' || ! config("filesystems.disks.{$laravelDiskWhenNoBucket}")) {
            throw new \RuntimeException('Thumbnail upload requires a storage bucket or resolved Laravel disk.');
        }

        try {
            Storage::disk($laravelDiskWhenNoBucket)->put($s3ThumbnailPath, $body, ['visibility' => 'private']);
        } catch (\Throwable $e) {
            Log::error('Failed to upload thumbnail to fallback disk', [
                'disk' => $laravelDiskWhenNoBucket,
                'key' => $s3ThumbnailPath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to upload thumbnail: {$e->getMessage()}", 0, $e);
        }

        return $s3ThumbnailPath;
    }

    /**
     * Upload the LibreOffice-generated PDF used for multipage Office preview (same rendering path as native PDFs).
     *
     * @return string Canonical S3 / disk key (see {@see AssetPathGenerator::generateOfficePreviewPdfPath})
     */
    protected function uploadOfficePreviewPdfToStorage(
        ?StorageBucket $bucket,
        Asset $asset,
        string $localPdfPath,
        ?AssetVersion $version,
        ?string $laravelDiskWhenNoBucket,
    ): string {
        $asset->loadMissing('tenant');
        $tenant = $asset->tenant;
        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Tenant is required to store office preview PDF.');
        }

        $versionNumber = $version?->version_number ?? $asset->currentVersion?->version_number ?? 1;
        $pathGenerator = app(AssetPathGenerator::class);
        $s3Key = $pathGenerator->generateOfficePreviewPdfPath($tenant, $asset, $versionNumber);

        $bytes = file_get_contents($localPdfPath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Failed to read local office preview PDF for upload.');
        }

        if ($bucket !== null) {
            try {
                $this->s3Client->putObject([
                    'Bucket' => $bucket->name,
                    'Key' => $s3Key,
                    'Body' => $bytes,
                    'ContentType' => 'application/pdf',
                    'Metadata' => [
                        'original-asset-id' => $asset->id,
                        'derivative' => 'office_preview_pdf',
                        'generated-at' => now()->toIso8601String(),
                    ],
                ]);

                return $s3Key;
            } catch (S3Exception $e) {
                Log::error('[ThumbnailGenerationService] Failed to upload office preview PDF', [
                    'bucket' => $bucket->name,
                    'key' => $s3Key,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException("Failed to upload office preview PDF: {$e->getMessage()}", 0, $e);
            }
        }

        if (! is_string($laravelDiskWhenNoBucket) || $laravelDiskWhenNoBucket === '' || ! config("filesystems.disks.{$laravelDiskWhenNoBucket}")) {
            throw new \RuntimeException('Office preview PDF upload requires a storage bucket or resolved Laravel disk.');
        }

        try {
            Storage::disk($laravelDiskWhenNoBucket)->put($s3Key, $bytes, ['visibility' => 'private']);
        } catch (\Throwable $e) {
            Log::error('[ThumbnailGenerationService] Failed to upload office preview PDF to fallback disk', [
                'disk' => $laravelDiskWhenNoBucket,
                'key' => $s3Key,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to upload office preview PDF: {$e->getMessage()}", 0, $e);
        }

        return $s3Key;
    }

    /**
     * Download original bytes when the asset has no {@see StorageBucket} row (studio outputs, etc.).
     */
    protected function downloadSourceToTempForThumbnails(Asset $asset, string $sourceS3Path): string
    {
        $bytes = EditorAssetOriginalBytesLoader::loadFromStorage($asset, $sourceS3Path);
        $tempPath = tempnam(sys_get_temp_dir(), 'thumb_src_'.$asset->id.'_');
        if ($tempPath === false || @file_put_contents($tempPath, $bytes) === false) {
            throw new \RuntimeException('Failed to materialize source file for thumbnail generation');
        }

        return $tempPath;
    }

    /**
     * Generate a single thumbnail for the given style.
     *
     * @param  string  $sourcePath  Local path to source file
     * @param  string  $styleName  Name of the style (thumb, medium, large)
     * @param  array  $styleConfig  Style configuration from config
     * @param  string  $fileType  Detected file type
     * @return string|null Path to generated thumbnail file, or null if generation not supported
     *
     * @throws \RuntimeException If generation fails
     */
    protected function generateThumbnail(
        Asset $asset,
        string $sourcePath,
        string $styleName,
        array $styleConfig,
        string $fileType,
        bool $forceImageMagick = false
    ): ?string {
        // Admin override: Force ImageMagick for any file type
        if ($forceImageMagick || $fileType === 'imagick_override') {
            return $this->generateImageMagickThumbnail($sourcePath, $styleConfig, $asset);
        }

        // Route to appropriate generator based on file type using FileTypeService
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $handler = $fileTypeService->getHandler($fileType, 'thumbnail');

        if (! $handler || ! method_exists($this, $handler)) {
            Log::info('Thumbnail generation not supported for file type', [
                'asset_id' => $asset->id,
                'file_type' => $fileType,
                'mime_type' => $asset->mime_type,
            ]);

            return null;
        }

        $styleConfig['_asset_id'] = $asset->id;

        return $this->$handler($sourcePath, $styleConfig);
    }

    /**
     * Generate thumbnail for SVG files.
     *
     * When Imagick is available: Rasterizes SVG to PNG/WebP for analysis (dominant colors,
     * embedding) and consistent display. Output matches config assets.thumbnail.output_format.
     *
     * When Imagick is unavailable: Passthrough (copies original SVG). Brand scoring will
     * remain incomplete for SVG in that case.
     *
     * @return string Path to generated thumbnail (raster or SVG copy)
     *
     * @throws \RuntimeException If generation fails
     */
    protected function generateSvgThumbnail(string $sourcePath, array $styleConfig): string
    {
        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source SVG file does not exist: {$sourcePath}");
        }
        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException('Source SVG file is empty (size: 0 bytes)');
        }
        // Basic SVG validation - must start with <svg or <?xml
        $content = file_get_contents($sourcePath, false, null, 0, 200);
        if ($content === false || ! preg_match('/<\s*(svg|\?xml)/i', $content)) {
            throw new \RuntimeException("Source file does not appear to be a valid SVG (size: {$sourceFileSize} bytes)");
        }

        // Require Imagick for SVG — raw SVG passthrough produces wrong output (paths end in .svg,
        // getimagesize returns null, grid shows placeholder). Rasterization is mandatory.
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('SVG thumbnail generation requires Imagick extension');
        }

        return $this->generateSvgRasterizedThumbnail($sourcePath, $styleConfig);
    }

    /**
     * Render SVG to PNG via rsvg-convert (librsvg).
     *
     * @param  string  $sourcePath  Path to SVG file
     * @param  int  $targetWidth  Target pixel width (height auto from aspect ratio)
     * @return string Path to temporary PNG file
     */
    private function renderSvgViaRsvg(string $sourcePath, int $targetWidth): string
    {
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $tempPngPath = $tmpDir.'/svg_'.uniqid().'.png';

        $command = sprintf(
            'rsvg-convert -w %d %s -o %s 2>&1',
            $targetWidth,
            escapeshellarg($sourcePath),
            escapeshellarg($tempPngPath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ! file_exists($tempPngPath) || filesize($tempPngPath) === 0) {
            $stderr = trim(implode("\n", $output));
            throw new \RuntimeException(sprintf(
                'rsvg-convert failed (exit %d, width=%d): %s',
                $exitCode,
                $targetWidth,
                $stderr !== '' ? $stderr : 'no output'
            ));
        }

        return $tempPngPath;
    }

    /**
     * Rasterize SVG to raster thumbnail using rsvg-convert + Imagick.
     *
     * SVG → PNG via rsvg-convert, then PNG → WebP via Imagick.
     *
     * @return string Path to generated raster thumbnail
     */
    protected function generateSvgRasterizedThumbnail(string $sourcePath, array $styleConfig): string
    {
        // SVG-specific pixel widths: preview 32, thumb 400, medium 1600, large 4096
        $svgWidths = [
            32 => 32,
            320 => 400,
            1024 => 1600,
            4096 => 4096,
        ];
        $configWidth = $styleConfig['width'] ?? 1024;
        $targetWidth = $svgWidths[$configWidth] ?? min(4096, $configWidth);

        $pngPath = $this->renderSvgViaRsvg($sourcePath, $targetWidth);

        if ($this->generationMode === ThumbnailMode::Preferred->value) {
            $svgCrop = $this->applyPreferredSmartOrPrintCrop($pngPath);
            $this->preferredCropSummary = $svgCrop;
            if (($svgCrop['path'] ?? $pngPath) !== $pngPath && is_file((string) $svgCrop['path'])) {
                if (file_exists($pngPath)) {
                    unlink($pngPath);
                }
                $pngPath = $svgCrop['path'];
            }
        }

        try {
            $imagick = new \Imagick($pngPath);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality(92);
            $imagick->stripImage();

            if (! empty($styleConfig['blur'])) {
                $imagick->blurImage(0, 8);
            }

            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_svg_').'.webp';
            $imagick->writeImage($thumbPath);

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            $imagick->clear();
            $imagick->destroy();
        } finally {
            if (file_exists($pngPath)) {
                unlink($pngPath);
            }
        }

        if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
            throw new \RuntimeException('SVG rasterization produced empty output');
        }

        Log::info('[SVG Raster]', [
            'asset_id' => $styleConfig['_asset_id'] ?? null,
            'width' => $width,
            'height' => $height,
        ]);

        return $thumbPath;
    }

    /**
     * Generate thumbnail for image files (jpg, png, webp, gif).
     *
     * Uses PHP GD library for image processing.
     *
     * @return string Path to generated thumbnail
     *
     * @throws \RuntimeException If generation fails
     */
    protected function generateImageThumbnail(string $sourcePath, array $styleConfig): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is required for image thumbnail generation');
        }

        // Verify source file exists and is readable
        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException('Source file is empty (size: 0 bytes)');
        }

        $raster = $this->resolveGdThumbnailRaster($sourcePath);
        $workPath = $raster['path'];
        $tFileMeta = microtime(true);
        $sourceInfo = getimagesize($workPath);
        if ($this->diagnosticThumbnailSegmentMs !== null) {
            $this->diagnosticThumbnailSegmentMs['file_meta_ms'] = round((microtime(true) - $tFileMeta) * 1000, 3);
        }
        if ($sourceInfo === false) {
            throw new \RuntimeException("Unable to read source image: {$workPath} (size: {$sourceFileSize} bytes)");
        }

        [$sourceWidth, $sourceHeight, $sourceType] = $sourceInfo;

        Log::info('[ThumbnailGenerationService] Source image loaded', [
            'source_path' => $sourcePath,
            'decode_path' => $workPath,
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
            'source_type' => $sourceType,
            'source_file_size' => $sourceFileSize,
        ]);

        // Create source image resource
        $tDecode = microtime(true);
        $sourceImage = match ($sourceType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($workPath),
            IMAGETYPE_PNG => imagecreatefrompng($workPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($workPath),
            IMAGETYPE_GIF => imagecreatefromgif($workPath),
            default => throw new \RuntimeException("Unsupported image type: {$sourceType}"),
        };
        if ($this->diagnosticThumbnailSegmentMs !== null) {
            $this->diagnosticThumbnailSegmentMs['decode_ms'] = round((microtime(true) - $tDecode) * 1000, 3);
        }

        if ($sourceImage === false) {
            throw new \RuntimeException('Failed to create source image resource');
        }

        try {
            $tNormStart = microtime(true);
            // Calculate thumbnail dimensions (maintain aspect ratio)
            $targetWidth = $styleConfig['width'];
            $targetHeight = $styleConfig['height'];
            $fit = $styleConfig['fit'] ?? 'contain';

            [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                $sourceWidth,
                $sourceHeight,
                $targetWidth,
                $targetHeight,
                $fit
            );

            // Create thumbnail image
            $thumbImage = imagecreatetruecolor($thumbWidth, $thumbHeight);
            if ($thumbImage === false) {
                throw new \RuntimeException('Failed to create thumbnail image resource');
            }

            // Detect if image has white content on transparent background (PNG, GIF, WebP support transparency)
            $needsDarkBackground = false;
            $preserveTransparency = ! empty($styleConfig['preserve_transparency']);
            $supportsTransparency = in_array($sourceType, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
            if ($supportsTransparency) {
                // When preserve_transparency: skip gray fill so logo displays as-is on public pages
                if (! $preserveTransparency) {
                    $needsDarkBackground = $this->hasWhiteContentOnTransparent($sourceImage, $sourceWidth, $sourceHeight);
                }
                if ($needsDarkBackground) {
                    // Use gray-400 (#9CA3AF) for strong contrast with white logos
                    // Gray-300 was too light; gray-400 makes white-on-transparent clearly visible
                    $darkGray = imagecolorallocate($thumbImage, 156, 163, 175);
                    imagefill($thumbImage, 0, 0, $darkGray);
                } else {
                    // Preserve transparency when no white-on-transparent content
                    imagealphablending($thumbImage, false);
                    imagesavealpha($thumbImage, true);
                    $transparent = imagecolorallocatealpha($thumbImage, 0, 0, 0, 127);
                    imagefill($thumbImage, 0, 0, $transparent);
                }
            } else {
                // Fill with white background for opaque formats (JPEG, etc.)
                $white = imagecolorallocate($thumbImage, 255, 255, 255);
                imagefill($thumbImage, 0, 0, $white);
            }

            if ($this->diagnosticThumbnailSegmentMs !== null) {
                $this->diagnosticThumbnailSegmentMs['normalize_ms'] = round((microtime(true) - $tNormStart) * 1000, 3);
            }

            // Resize image — use nearest-neighbor for small sources (icons, favicons) for crisp upscaling
            $tResizeStart = microtime(true);
            $resizeFn = $this->isSmallSource($sourceWidth, $sourceHeight) ? 'imagecopyresized' : 'imagecopyresampled';
            $resizeFn(
                $thumbImage,
                $sourceImage,
                0, 0, 0, 0,
                $thumbWidth,
                $thumbHeight,
                $sourceWidth,
                $sourceHeight
            );

            // Step 6: Apply blur for preview thumbnails (LQIP effect)
            // Preview thumbnails are intentionally blurred to indicate they're temporary
            // BUT: They must still resemble the original image (blurred, not garbage)
            // Use moderate blur (1-2 passes) to maintain image resemblance
            if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                // Apply moderate blur (2 passes) to create LQIP effect while preserving image resemblance
                // Too much blur (3+ passes) makes previews look like garbage instead of blurred images
                for ($i = 0; $i < 2; $i++) {
                    imagefilter($thumbImage, IMG_FILTER_GAUSSIAN_BLUR);
                }
            }

            if ($this->diagnosticThumbnailSegmentMs !== null) {
                $this->diagnosticThumbnailSegmentMs['resize_ms'] = round((microtime(true) - $tResizeStart) * 1000, 3);
            }

            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $quality = $styleConfig['quality'] ?? 85;

            // Save thumbnail to temporary file
            $tEncode = microtime(true);
            if ($outputFormat === 'webp' && function_exists('imagewebp')) {
                $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_').'.webp';
                if (! imagewebp($thumbImage, $thumbPath, $quality)) {
                    throw new \RuntimeException('Failed to save thumbnail image as WebP');
                }
            } else {
                // Fallback to JPEG if WebP not available or not configured
                $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_').'.jpg';
                if (! imagejpeg($thumbImage, $thumbPath, $quality)) {
                    throw new \RuntimeException('Failed to save thumbnail image as JPEG');
                }
            }
            if ($this->diagnosticThumbnailSegmentMs !== null) {
                $this->diagnosticThumbnailSegmentMs['encode_ms'] = round((microtime(true) - $tEncode) * 1000, 3);
            }

            return $thumbPath;
        } finally {
            imagedestroy($sourceImage);
            if (isset($thumbImage)) {
                imagedestroy($thumbImage);
            }
        }
    }

    /**
     * Generate thumbnail for TIFF files using Imagick.
     *
     * TIFF files require Imagick as GD library does not support TIFF format.
     * This method uses Imagick to read and process TIFF images.
     *
     * @param  string  $sourcePath  Local path to TIFF file
     * @param  array  $styleConfig  Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image (JPEG)
     *
     * @throws \RuntimeException If TIFF processing fails
     */
    protected function generateTiffThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify Imagick extension is loaded
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('TIFF thumbnail generation requires Imagick PHP extension');
        }

        // Verify source file exists and is readable
        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source TIFF file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException('Source TIFF file is empty (size: 0 bytes)');
        }

        Log::info('[ThumbnailGenerationService] Generating TIFF thumbnail using Imagick', [
            'source_path' => $sourcePath,
            'source_file_size' => $sourceFileSize,
            'style_config' => $styleConfig,
        ]);

        try {
            // PMS/spot + transprency TIFFs often decode to an empty white RGB composite in ImageMagick
            // while the visible ink lives in another IFD, extra samples, or a multi-page stack.
            $imagick = $this->selectTiffSourceFrameForThumbnails($sourcePath);

            // Get source dimensions
            $sourceWidth = $imagick->getImageWidth();
            $sourceHeight = $imagick->getImageHeight();

            if ($sourceWidth === 0 || $sourceHeight === 0) {
                throw new \RuntimeException('TIFF image has invalid dimensions');
            }

            Log::info('[ThumbnailGenerationService] TIFF image loaded', [
                'source_path' => $sourcePath,
                'source_width' => $sourceWidth,
                'source_height' => $sourceHeight,
            ]);

            // Calculate thumbnail dimensions (maintain aspect ratio)
            $targetWidth = $styleConfig['width'];
            $targetHeight = $styleConfig['height'];
            $fit = $styleConfig['fit'] ?? 'contain';

            [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                $sourceWidth,
                $sourceHeight,
                $targetWidth,
                $targetHeight,
                $fit
            );

            // Resize — use nearest-neighbor for small sources (icons, favicons) for crisp upscaling
            $filter = $this->isSmallSource($sourceWidth, $sourceHeight) ? \Imagick::FILTER_POINT : \Imagick::FILTER_LANCZOS;
            $imagick->resizeImage($thumbWidth, $thumbHeight, $filter, 1, true);

            // Apply blur for preview thumbnails (LQIP effect)
            if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2); // Moderate blur
            }

            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);

            // Create temporary output file
            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_tiff_').'.'.$extension;

            // Write to file
            $imagick->writeImage($thumbPath);
            $imagick->clear();
            $imagick->destroy();

            // Verify output file was created
            if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
                throw new \RuntimeException('TIFF thumbnail generation failed - output file is missing or empty');
            }

            Log::info('[ThumbnailGenerationService] TIFF thumbnail generated successfully', [
                'source_path' => $sourcePath,
                'thumb_path' => $thumbPath,
                'thumb_width' => $thumbWidth,
                'thumb_height' => $thumbHeight,
                'thumb_size_bytes' => filesize($thumbPath),
                'output_format' => $outputFormat,
            ]);

            return $thumbPath;
        } catch (\ImagickException $e) {
            Log::error('[ThumbnailGenerationService] TIFF thumbnail generation failed', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            throw new \RuntimeException("TIFF thumbnail generation failed: {$e->getMessage()}", 0, $e);
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] TIFF thumbnail generation error', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            throw new \RuntimeException("TIFF thumbnail generation error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Pick the best decodable frame for web thumbnails. Fast path: subfile [0] only. If the normalized
     * result is still effectively blank (flat white), try merged multi-page stacks and other IFDs — some
     * Photoshop "PMS" line art and embedded preview JPEGs live there; ImageMagick's default composite can be white.
     */
    protected function selectTiffSourceFrameForThumbnails(string $sourcePath): \Imagick
    {
        $maxScan = max(1, (int) config('assets.thumbnail.tiff.max_subimage_scan', 6));
        $candidatesTried = [];

        $tryOne = function (string $pathSpec, string $label) use (&$candidatesTried, $sourcePath): ?\Imagick {
            try {
                $r = new \Imagick;
                $r->setOption('tiff:tile-geometry', '256x256');
                $r->readImage($pathSpec);
                $r->setIteratorIndex(0);
                $im = $r->getImage();
                $r->clear();
                $r->destroy();
                [$nrm, $orient] = $this->normalizePrintTiffFrameForWebThumbnail($im);
                if ($this->tiffNormalizedFrameLooksOnlyPaperWhite($nrm)) {
                    $candidatesTried[] = $label.':blank';
                    $nrm->clear();
                    $nrm->destroy();

                    return null;
                }
                $candidatesTried[] = $label.':ok';
                $this->mergeRasterOrientationProfileOnce(array_merge([
                    'pipeline' => 'tiff_normalize_print',
                    'exif_orientation_tag' => ImageOrientationNormalizer::readExifOrientationTag($sourcePath),
                ], $orient));

                return $nrm;
            } catch (\Throwable $e) {
                Log::debug('[ThumbnailGenerationService] TIFF sub-read failed', [
                    'source_path' => $sourcePath,
                    'path_spec' => $pathSpec,
                    'label' => $label,
                    'error' => $e->getMessage(),
                ]);
                $candidatesTried[] = $label.':err';

                return null;
            }
        };

        // 1) Default (fast, avoids reading entire huge multi-page spool into RAM when [0] is enough)
        $first = $tryOne($sourcePath.'[0]', 'sub[0]');
        if ($first instanceof \Imagick) {
            return $first;
        }

        // 2) Full sequence + flatten (multi-IFD / layered prepress)
        $merged = $this->tryMergeAllTiffSubimages($sourcePath);
        if ($merged instanceof \Imagick) {
            if (! $this->tiffNormalizedFrameLooksOnlyPaperWhite($merged)) {
                Log::info('[ThumbnailGenerationService] TIFF using merged multi-IFD decode', [
                    'source_path' => $sourcePath,
                    'candidates' => $candidatesTried,
                ]);

                return $merged;
            }
            $merged->clear();
            $merged->destroy();
        }

        // 3) Other IFDs (embedded RGB preview, reduced plate, non-primary page)
        for ($i = 1; $i < $maxScan; $i++) {
            $alt = $tryOne($sourcePath.'['.$i.']', 'sub['.$i.']');
            if ($alt instanceof \Imagick) {
                Log::info('[ThumbnailGenerationService] TIFF using alternate IFD for visible composite', [
                    'source_path' => $sourcePath,
                    'subfile_index' => $i,
                    'candidates' => $candidatesTried,
                ]);

                return $alt;
            }
        }

        // 4) Last resort: [0] again (may be all-white: spot-only channel art IM cannot merge — user should re-export)
        Log::warning('[ThumbnailGenerationService] TIFF decode produced no non-white frame; using [0] best-effort', [
            'source_path' => $sourcePath,
            'candidates' => $candidatesTried,
        ]);

        $r = new \Imagick;
        $r->setOption('tiff:tile-geometry', '256x256');
        $r->readImage($sourcePath.'[0]');
        $r->setIteratorIndex(0);
        $im = $r->getImage();
        $r->clear();
        $r->destroy();

        [$frame, $orient] = $this->normalizePrintTiffFrameForWebThumbnail($im);
        $this->mergeRasterOrientationProfileOnce(array_merge([
            'pipeline' => 'tiff_fallback_normalize',
            'exif_orientation_tag' => ImageOrientationNormalizer::readExifOrientationTag($sourcePath),
        ], $orient));

        return $frame;
    }

    /**
     * @return \Imagick|null Merged+normalized frame, or null on failure / single-page file
     */
    protected function tryMergeAllTiffSubimages(string $sourcePath): ?\Imagick
    {
        try {
            $reader = new \Imagick;
            $reader->setOption('tiff:tile-geometry', '256x256');
            $reader->readImage($sourcePath);
            $n = (int) $reader->getNumberImages();
            if ($n < 2) {
                $reader->clear();
                $reader->destroy();

                return null;
            }
            if (method_exists($reader, 'coalesceImages')) {
                $c = $reader->coalesceImages();
                $reader->clear();
                $reader->destroy();
                $reader = $c;
            }
            if (method_exists($reader, 'mergeImageLayers') && defined('Imagick::LAYERMETHOD_FLATTEN')) {
                $flat = $reader->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $reader->clear();
                $reader->destroy();
                if (! $flat instanceof \Imagick) {
                    return null;
                }

                [$mergedFrame, $orient] = $this->normalizePrintTiffFrameForWebThumbnail($flat);
                $this->mergeRasterOrientationProfileOnce(array_merge([
                    'pipeline' => 'tiff_merged_ifd',
                    'exif_orientation_tag' => ImageOrientationNormalizer::readExifOrientationTag($sourcePath),
                ], $orient));

                return $mergedFrame;
            }
            $reader->clear();
            $reader->destroy();
        } catch (\Throwable $e) {
            Log::debug('[ThumbnailGenerationService] TIFF full merge read failed', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * True when the composite is effectively a uniform sheet of white (failed spot/empty plate decode).
     */
    protected function tiffNormalizedFrameLooksOnlyPaperWhite(\Imagick $im): bool
    {
        try {
            $ex = $im->getImageExtrema();
            $min = (int) ($ex['min'] ?? 0);
            $max = (int) ($ex['max'] ?? 0);
            $range = $im->getQuantumRange();
            $hi = (int) ($range['quantumRangeLong'] ?? 65535);
            if ($hi < 1) {
                $hi = 65535;
            }
            $minF = $min / $hi;
            $maxF = $max / $hi;
            $span = $maxF - $minF;
            if ($span < 0.02 && $minF >= 0.97 && $maxF >= 0.97) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * ImageMagick/LibRaw options that reduce green/magenta RAW decode artifacts (best-effort per IM build).
     */
    protected function applyImagickRawDelegateOptions(\Imagick $imagick): void
    {
        $options = [
            'dng:read-thumbnail' => 'true',
            'raw:use-camera-wb' => 'true',
        ];
        if (config('assets.thumbnail.cr2.raw_auto_white_balance', false)) {
            $options['raw:use-auto-wb'] = 'true';
        }
        foreach ($options as $key => $value) {
            try {
                $imagick->setOption($key, $value);
            } catch (\Throwable) {
                // Older delegates omit some keys; ignore.
            }
        }
    }

    /**
     * After decoding a camera RAW frame, normalize orientation and output colorspace for web thumbnails.
     */
    protected function normalizeDecodedRawImageColors(\Imagick $imagick): void
    {
        $this->imagickNormalizeThumbnailOrientation($imagick, 'cr2_raw_decode');

        try {
            $imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        } catch (\Throwable $e) {
            Log::debug('[ThumbnailGenerationService] RAW colorspace normalize skipped', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pick CR2/LibRaw subimage index. Staging often uses a different ImageMagick build than local: layer
     * count/format metadata varies, and picking the largest layer triggers full demosaic → green/magenta.
     *
     * @return int>=0
     */
    protected function pickCr2LayerIndex(\Imagick $reader, int $minArea, bool $preferSmallest): int
    {
        $n = max(1, (int) $reader->getNumberImages());
        if ($n === 1) {
            return 0;
        }

        $preferJpeg = (bool) config('assets.thumbnail.cr2.prefer_embedded_jpeg_layer', true);
        $maxRawPixels = max(1, (int) config('assets.thumbnail.cr2.max_raw_decode_pixels', 25_000_000));

        $layers = [];
        for ($i = 0; $i < $n; $i++) {
            $reader->setIteratorIndex($i);
            $w = (int) $reader->getImageWidth();
            $h = (int) $reader->getImageHeight();
            $area = $w * $h;
            $format = '';
            try {
                $format = strtolower((string) $reader->getImageFormat());
            } catch (\Throwable) {
            }
            $isJpegish = in_array($format, ['jpeg', 'jpg', 'mjpeg'], true);
            $layers[] = ['i' => $i, 'w' => $w, 'h' => $h, 'area' => $area, 'format' => $format, 'is_jpegish' => $isJpegish];
        }

        $minPick = max(1, min($minArea, 100));

        if ($preferJpeg) {
            $jpegCandidates = array_values(array_filter(
                $layers,
                static fn (array $L): bool => $L['is_jpegish'] && $L['area'] >= $minPick
            ));
            if ($jpegCandidates !== []) {
                usort($jpegCandidates, static fn (array $a, array $b): int => $a['area'] <=> $b['area']);

                return $jpegCandidates[0]['i'];
            }
        }

        // Typical embedded preview: ~1–16MP; avoid full ~24–45MP RAW decode when a smaller layer exists.
        $sweetMin = 320 * 240;
        $sweetMax = min($maxRawPixels, 6000 * 4000);
        $sweet = array_values(array_filter(
            $layers,
            static fn (array $L): bool => $L['area'] >= $sweetMin && $L['area'] <= $sweetMax
        ));
        if ($sweet !== []) {
            usort($sweet, static fn (array $a, array $b): int => $a['area'] <=> $b['area']);

            return $sweet[0]['i'];
        }

        $underCap = array_values(array_filter(
            $layers,
            static fn (array $L): bool => $L['area'] <= $maxRawPixels && $L['area'] >= $minArea
        ));
        if ($underCap !== []) {
            usort($underCap, static fn (array $a, array $b): int => $a['area'] <=> $b['area']);

            return $underCap[0]['i'];
        }

        if ($preferSmallest) {
            $bestIdx = 0;
            $bestArea = PHP_INT_MAX;
            $found = false;
            foreach ($layers as $L) {
                if ($L['area'] >= $minArea && $L['area'] < $bestArea) {
                    $bestArea = $L['area'];
                    $bestIdx = $L['i'];
                    $found = true;
                }
            }
            if ($found) {
                return $bestIdx;
            }
            foreach ($layers as $L) {
                if ($L['area'] < $bestArea) {
                    $bestArea = $L['area'];
                    $bestIdx = $L['i'];
                }
            }

            return $bestIdx;
        }

        return 0;
    }

    /**
     * Load a CR2 (or similar RAW) and return a single raster frame best suited for thumbnails.
     *
     * When multiple subimages exist, prefer embedded JPEG / mid-size preview over full RAW demosaic.
     */
    protected function openCr2ThumbnailFrame(string $sourcePath): \Imagick
    {
        $preferSmallest = (bool) config('assets.thumbnail.cr2.prefer_smallest_sensible_layer', true);
        $minArea = max(1, (int) config('assets.thumbnail.cr2.layer_min_area_pixels', 1600));

        $tryOpen = function (string $pathToRead) use ($preferSmallest, $minArea): \Imagick {
            $reader = new \Imagick;
            $reader->setResolution(150, 150);
            $this->applyImagickRawDelegateOptions($reader);
            $reader->readImage($pathToRead);
            $n = max(1, (int) $reader->getNumberImages());
            $bestIdx = $n > 1
                ? $this->pickCr2LayerIndex($reader, $minArea, $preferSmallest)
                : 0;

            Log::debug('[ThumbnailGenerationService] CR2 layer choice', [
                'path_suffix' => str_contains($pathToRead, '[') ? 'indexed' : 'full',
                'subimage_count' => $n,
                'chosen_index' => $bestIdx,
            ]);

            $reader->setIteratorIndex($bestIdx);
            $frame = $reader->getImage();
            $reader->clear();
            $reader->destroy();
            $this->normalizeDecodedRawImageColors($frame);

            return $frame;
        };

        try {
            return $tryOpen($sourcePath);
        } catch (\Throwable $e) {
            Log::debug('[ThumbnailGenerationService] CR2 full-path read failed, retrying [0]', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
        }

        return $tryOpen($sourcePath.'[0]');
    }

    /**
     * Generate thumbnail for Canon CR2 (RAW) using Imagick.
     *
     * Uses LibRaw-friendly options, prefers embedded preview when multiple layers exist, and
     * converts to sRGB before resize to avoid green/magenta channel decode artifacts.
     *
     * @param  string  $sourcePath  Local path to CR2 file
     * @return string Path to generated thumbnail (WebP or JPEG per config)
     */
    protected function generateCr2Thumbnail(string $sourcePath, array $styleConfig): string
    {
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('CR2 thumbnail generation requires Imagick PHP extension');
        }

        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source CR2 file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException('Source CR2 file is empty (size: 0 bytes)');
        }

        Log::info('[ThumbnailGenerationService] Generating CR2 thumbnail using Imagick', [
            'source_path' => $sourcePath,
            'source_file_size' => $sourceFileSize,
            'style_config' => $styleConfig,
        ]);

        try {
            $imagick = $this->openCr2ThumbnailFrame($sourcePath);

            $sourceWidth = $imagick->getImageWidth();
            $sourceHeight = $imagick->getImageHeight();

            if ($sourceWidth === 0 || $sourceHeight === 0) {
                $imagick->clear();
                $imagick->destroy();
                throw new \RuntimeException('CR2 image has invalid dimensions');
            }

            $targetWidth = $styleConfig['width'];
            $targetHeight = $styleConfig['height'];
            $fit = $styleConfig['fit'] ?? 'contain';

            [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                $sourceWidth,
                $sourceHeight,
                $targetWidth,
                $targetHeight,
                $fit
            );

            $filter = $this->isSmallSource($sourceWidth, $sourceHeight) ? \Imagick::FILTER_POINT : \Imagick::FILTER_LANCZOS;
            $imagick->resizeImage($thumbWidth, $thumbHeight, $filter, 1, true);

            if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2);
            }

            $outputFormat = config('assets.thumbnail.output_format', 'webp');
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);

            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_cr2_').'.'.$extension;

            $imagick->writeImage($thumbPath);
            $imagick->clear();
            $imagick->destroy();

            if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
                throw new \RuntimeException('CR2 thumbnail generation failed - output file is missing or empty');
            }

            Log::info('[ThumbnailGenerationService] CR2 thumbnail generated successfully', [
                'source_path' => $sourcePath,
                'thumb_path' => $thumbPath,
                'thumb_width' => $thumbWidth,
                'thumb_height' => $thumbHeight,
                'thumb_size_bytes' => filesize($thumbPath),
                'output_format' => $outputFormat,
            ]);

            return $thumbPath;
        } catch (\ImagickException $e) {
            Log::error('[ThumbnailGenerationService] CR2 thumbnail generation failed', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("CR2 thumbnail generation failed: {$e->getMessage()}", 0, $e);
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] CR2 thumbnail generation error', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("CR2 thumbnail generation error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate thumbnail for AVIF files using Imagick.
     *
     * AVIF (AV1 Image File Format) files require Imagick as GD library does not support AVIF format.
     * This method uses Imagick to read and process AVIF images.
     *
     * @param  string  $sourcePath  Local path to AVIF file
     * @param  array  $styleConfig  Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image (JPEG or WebP)
     *
     * @throws \RuntimeException If AVIF processing fails
     */
    protected function generateAvifThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify Imagick extension is loaded
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('AVIF thumbnail generation requires Imagick PHP extension');
        }

        // Verify source file exists and is readable
        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source AVIF file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException('Source AVIF file is empty (size: 0 bytes)');
        }

        Log::info('[ThumbnailGenerationService] Generating AVIF thumbnail using Imagick', [
            'source_path' => $sourcePath,
            'source_file_size' => $sourceFileSize,
            'style_config' => $styleConfig,
        ]);

        try {
            $imagick = new \Imagick;

            // Read the AVIF file
            $imagick->readImage($sourcePath);

            // Get first image if multi-image AVIF
            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();

            $this->imagickNormalizeThumbnailOrientation($imagick, 'avif_imagick', [
                'exif_orientation_tag' => ImageOrientationNormalizer::readExifOrientationTag($sourcePath),
            ]);

            // Get source dimensions
            $sourceWidth = $imagick->getImageWidth();
            $sourceHeight = $imagick->getImageHeight();

            if ($sourceWidth === 0 || $sourceHeight === 0) {
                throw new \RuntimeException('AVIF image has invalid dimensions');
            }

            Log::info('[ThumbnailGenerationService] AVIF image loaded', [
                'source_path' => $sourcePath,
                'source_width' => $sourceWidth,
                'source_height' => $sourceHeight,
            ]);

            // Calculate thumbnail dimensions (maintain aspect ratio)
            $targetWidth = $styleConfig['width'];
            $targetHeight = $styleConfig['height'];
            $fit = $styleConfig['fit'] ?? 'contain';

            [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                $sourceWidth,
                $sourceHeight,
                $targetWidth,
                $targetHeight,
                $fit
            );

            // Resize — use nearest-neighbor for small sources (icons, favicons) for crisp upscaling
            $filter = $this->isSmallSource($sourceWidth, $sourceHeight) ? \Imagick::FILTER_POINT : \Imagick::FILTER_LANCZOS;
            $imagick->resizeImage($thumbWidth, $thumbHeight, $filter, 1, true);

            // Apply blur for preview thumbnails (LQIP effect)
            if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2); // Moderate blur
            }

            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $imagick->setImageFormat($outputFormat);

            $quality = $styleConfig['quality'] ?? 85;
            if ($outputFormat === 'webp') {
                $imagick->setImageCompressionQuality($quality);
            } else {
                $imagick->setImageCompressionQuality($quality);
            }

            // Create temporary output file
            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_avif_').'.'.$extension;

            // Write to file
            $imagick->writeImage($thumbPath);
            $imagick->clear();
            $imagick->destroy();

            // Verify output file was created
            if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
                throw new \RuntimeException('AVIF thumbnail generation failed - output file is missing or empty');
            }

            Log::info('[ThumbnailGenerationService] AVIF thumbnail generated successfully', [
                'source_path' => $sourcePath,
                'thumb_path' => $thumbPath,
                'thumb_width' => $thumbWidth,
                'thumb_height' => $thumbHeight,
                'thumb_size_bytes' => filesize($thumbPath),
                'output_format' => $outputFormat,
            ]);

            return $thumbPath;
        } catch (\ImagickException $e) {
            Log::error('[ThumbnailGenerationService] AVIF thumbnail generation failed', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            throw new \RuntimeException("AVIF thumbnail generation failed: {$e->getMessage()}", 0, $e);
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] AVIF thumbnail generation error', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            throw new \RuntimeException("AVIF thumbnail generation error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate thumbnail for HEIC/HEIF files using Imagick (libheif delegate).
     */
    protected function generateHeicThumbnail(string $sourcePath, array $styleConfig): string
    {
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('HEIC thumbnail generation requires Imagick PHP extension');
        }

        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source HEIC file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException('Source HEIC file is empty (size: 0 bytes)');
        }

        Log::info('[ThumbnailGenerationService] Generating HEIC thumbnail using Imagick', [
            'source_path' => $sourcePath,
            'source_file_size' => $sourceFileSize,
            'style_config' => $styleConfig,
        ]);

        try {
            $imagick = new \Imagick;
            $imagick->readImage($sourcePath.'[0]');
            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();

            $this->imagickNormalizeThumbnailOrientation($imagick, 'heic_imagick', [
                'exif_orientation_tag' => ImageOrientationNormalizer::readExifOrientationTag($sourcePath),
            ]);

            $sourceWidth = $imagick->getImageWidth();
            $sourceHeight = $imagick->getImageHeight();

            if ($sourceWidth === 0 || $sourceHeight === 0) {
                throw new \RuntimeException('HEIC image has invalid dimensions');
            }

            $targetWidth = $styleConfig['width'];
            $targetHeight = $styleConfig['height'];
            $fit = $styleConfig['fit'] ?? 'contain';

            [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                $sourceWidth,
                $sourceHeight,
                $targetWidth,
                $targetHeight,
                $fit
            );

            $filter = $this->isSmallSource($sourceWidth, $sourceHeight) ? \Imagick::FILTER_POINT : \Imagick::FILTER_LANCZOS;
            $imagick->resizeImage($thumbWidth, $thumbHeight, $filter, 1, true);

            if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2);
            }

            $outputFormat = config('assets.thumbnail.output_format', 'webp');
            $imagick->setImageFormat($outputFormat);

            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageCompressionQuality($quality);

            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_heic_').'.'.$extension;

            $imagick->writeImage($thumbPath);
            $imagick->clear();
            $imagick->destroy();

            if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
                throw new \RuntimeException('HEIC thumbnail generation failed - output file is missing or empty');
            }

            Log::info('[ThumbnailGenerationService] HEIC thumbnail generated successfully', [
                'source_path' => $sourcePath,
                'thumb_path' => $thumbPath,
                'thumb_width' => $thumbWidth,
                'thumb_height' => $thumbHeight,
                'thumb_size_bytes' => filesize($thumbPath),
                'output_format' => $outputFormat,
            ]);

            return $thumbPath;
        } catch (\ImagickException $e) {
            Log::error('[ThumbnailGenerationService] HEIC thumbnail generation failed', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("HEIC thumbnail generation failed: {$e->getMessage()}", 0, $e);
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] HEIC thumbnail generation error', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("HEIC thumbnail generation error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Detect page count for a local PDF file.
     */
    protected function detectPdfPageCount(string $sourcePath): int
    {
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('Imagick extension is required for PDF page counting');
        }
        if (! file_exists($sourcePath) || filesize($sourcePath) === 0) {
            throw new \RuntimeException('PDF file is missing or empty');
        }

        $imagick = new \Imagick;
        try {
            $imagick->pingImage($sourcePath);
            $pageCount = (int) $imagick->getNumberImages();
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }

        return max(1, $pageCount);
    }

    /**
     * Generate thumbnail for PDF files (first page only).
     *
     * Uses spatie/pdf-to-image to extract page 1 of the PDF and convert it to an image.
     * The image is then resized to match the thumbnail style dimensions.
     *
     * IMPORTANT: This is an additive extension - does not modify existing image processing.
     * Only page 1 is processed (enforced by config and safety guards).
     *
     * Safety guards:
     * - Maximum PDF size check (configurable, default 100MB)
     * - Page limit enforced (always page 1)
     * - Timeout protection (configurable, default 60s)
     * - Graceful failure with clear error messages
     *
     * @param  string  $sourcePath  Local path to PDF file
     * @param  array  $styleConfig  Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image (PNG/JPEG)
     *
     * @throws \RuntimeException If PDF processing fails or safety limits exceeded
     */
    protected function generatePdfThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify spatie/pdf-to-image package is available
        if (! class_exists(\Spatie\PdfToImage\Pdf::class)) {
            throw new \RuntimeException('PDF thumbnail generation requires spatie/pdf-to-image package');
        }

        // Verify Imagick extension is loaded (required by spatie/pdf-to-image)
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('PDF thumbnail generation requires Imagick PHP extension');
        }

        // Verify ImageMagick can process PDFs (quick check)
        try {
            $imagick = new \Imagick;
            $imagick->setResolution(72, 72); // Set resolution before reading
        } catch (\Exception $imagickException) {
            Log::error('[ThumbnailGenerationService] Imagick initialization failed', [
                'error' => $imagickException->getMessage(),
            ]);
            throw new \RuntimeException("Imagick extension error: {$imagickException->getMessage()}", 0, $imagickException);
        }

        // Safety guard: Check PDF file size
        $pdfSize = filesize($sourcePath);
        $maxSize = config('assets.thumbnail.pdf.max_size_bytes', 150 * 1024 * 1024); // 150MB default

        if ($pdfSize > $maxSize) {
            throw new \RuntimeException(
                "PDF file size ({$pdfSize} bytes) exceeds maximum allowed size ({$maxSize} bytes). ".
                'Large PDFs may cause memory exhaustion or processing timeouts.'
            );
        }

        Log::info('[ThumbnailGenerationService] Generating PDF thumbnail from page 1', [
            'source_path' => $sourcePath,
            'pdf_size_bytes' => $pdfSize,
            'max_size_bytes' => $maxSize,
            'style_config' => $styleConfig,
        ]);

        try {
            // Verify PDF file is readable before attempting conversion
            if (! is_readable($sourcePath)) {
                throw new \RuntimeException("PDF file is not readable: {$sourcePath}");
            }

            // Create PDF instance using spatie/pdf-to-image (sourcePath has .pdf extension for ImageMagick delegate)
            try {
                $pdf = new \Spatie\PdfToImage\Pdf($sourcePath);
            } catch (\Exception $pdfInitException) {
                Log::error('[ThumbnailGenerationService] Failed to initialize PDF object', [
                    'source_path' => $sourcePath,
                    'error' => $pdfInitException->getMessage(),
                    'exception_class' => get_class($pdfInitException),
                ]);
                throw new \RuntimeException("Failed to initialize PDF: {$pdfInitException->getMessage()}", 0, $pdfInitException);
            }

            // Safety guard: Enforce page limit (always page 1)
            // This is a hard requirement - only first page is used for thumbnails
            $maxPage = config('assets.thumbnail.pdf.max_page', 1);
            $targetPage = min(1, $maxPage); // Always use page 1, never exceed max_page

            // Note: spatie/pdf-to-image v3.x does not provide getNumberOfPages() method
            // We rely on the library to handle invalid page numbers gracefully
            // If page 1 doesn't exist, the save() method will fail, which we catch below

            // Set resolution to 300 DPI for high-quality rasterization before conversion
            if (method_exists($pdf, 'setResolution')) {
                $pdf->setResolution(300);
            }

            // Set timeout for PDF processing (prevents stuck jobs)
            $timeout = config('assets.thumbnail.pdf.timeout_seconds', 60);
            if (method_exists($pdf, 'setTimeout')) {
                $pdf->setTimeout($timeout);
            }

            // Extract page 1 as image (PNG format for best quality)
            // The PDF library will use ImageMagick to convert the PDF page to an image
            // Note: spatie/pdf-to-image v3.x uses selectPage() and save() methods
            // IMPORTANT: The output format may be changed by the library (e.g., .png -> .jpg)
            // We must use the ACTUAL path returned by save(), not the requested path
            $requestedImagePath = tempnam(sys_get_temp_dir(), 'pdf_thumb_').'.png';

            // Ensure temp directory is writable
            $tempDir = dirname($requestedImagePath);
            if (! is_writable($tempDir)) {
                throw new \RuntimeException("Temporary directory is not writable: {$tempDir}");
            }

            Log::info('[ThumbnailGenerationService] Attempting PDF to image conversion', [
                'source_path' => $sourcePath,
                'target_page' => $targetPage,
                'requested_image_path' => $requestedImagePath,
                'temp_dir_writable' => is_writable($tempDir),
            ]);

            $saveResult = null;
            $actualImagePath = null;

            try {
                // Attempt conversion - save() returns path(s) or throws exception
                // IMPORTANT: save() may return a different path than requested (e.g., .jpg instead of .png)
                // Always use the ACTUAL returned path, not the requested path
                $saveResult = $pdf->selectPage($targetPage)
                    ->save($requestedImagePath);

                // CRITICAL: Use the ACTUAL path returned by save(), not the requested path
                // The library may change the extension (e.g., .png -> .jpg) or return a different path
                if (is_array($saveResult)) {
                    // Multiple pages returned - use first one
                    if (empty($saveResult)) {
                        throw new \RuntimeException('PDF save() returned empty array');
                    }
                    $actualImagePath = $saveResult[0];
                    Log::info('[ThumbnailGenerationService] PDF save() returned multiple paths, using first', [
                        'returned_paths' => $saveResult,
                        'using_path' => $actualImagePath,
                    ]);
                } elseif (is_string($saveResult)) {
                    // Single path returned - use it (may differ from requested path)
                    $actualImagePath = $saveResult;
                    if ($actualImagePath !== $requestedImagePath) {
                        Log::info('[ThumbnailGenerationService] PDF save() returned different path than requested', [
                            'requested_path' => $requestedImagePath,
                            'returned_path' => $actualImagePath,
                        ]);
                    }
                } else {
                    throw new \RuntimeException('PDF save() returned unexpected type: '.gettype($saveResult));
                }

                Log::info('[ThumbnailGenerationService] PDF save() completed', [
                    'source_path' => $sourcePath,
                    'requested_path' => $requestedImagePath,
                    'actual_image_path' => $actualImagePath,
                    'save_result_type' => gettype($saveResult),
                ]);
            } catch (\Exception $saveException) {
                Log::error('[ThumbnailGenerationService] PDF save() method threw exception', [
                    'source_path' => $sourcePath,
                    'requested_image_path' => $requestedImagePath,
                    'exception' => $saveException->getMessage(),
                    'exception_class' => get_class($saveException),
                    'trace' => $saveException->getTraceAsString(),
                ]);
                throw new \RuntimeException("PDF to image conversion failed: {$saveException->getMessage()}", 0, $saveException);
            }

            // Verify the image was created successfully using the ACTUAL returned path
            // Check immediately after save() call
            if (! $actualImagePath || ! file_exists($actualImagePath)) {
                Log::error('[ThumbnailGenerationService] PDF conversion output file does not exist at returned path', [
                    'source_path' => $sourcePath,
                    'requested_path' => $requestedImagePath,
                    'actual_image_path' => $actualImagePath,
                    'temp_dir' => $tempDir,
                    'temp_dir_exists' => is_dir($tempDir),
                    'temp_dir_writable' => is_writable($tempDir),
                    'save_result' => $saveResult,
                ]);
                throw new \RuntimeException('PDF to image conversion failed - output file was not created at returned path');
            }

            // Use the actual path for the rest of the processing
            $tempImagePath = $actualImagePath;

            $outputFileSize = filesize($tempImagePath);
            if ($outputFileSize === 0) {
                Log::error('[ThumbnailGenerationService] PDF conversion output file is empty', [
                    'source_path' => $sourcePath,
                    'actual_image_path' => $tempImagePath,
                    'file_size' => $outputFileSize,
                ]);
                // Clean up empty file
                @unlink($tempImagePath);
                throw new \RuntimeException('PDF to image conversion failed - output file is empty (0 bytes)');
            }

            Log::info('[ThumbnailGenerationService] PDF page 1 extracted to image', [
                'source_path' => $sourcePath,
                'temp_image_path' => $tempImagePath,
                'image_size_bytes' => filesize($tempImagePath),
            ]);

            if ($this->generationMode === ThumbnailMode::Preferred->value) {
                if ($this->preferredPdfRasterCachePath !== null) {
                    @unlink($tempImagePath);
                    $tempImagePath = $this->copyRasterToTemp($this->preferredPdfRasterCachePath);
                } else {
                    $cropResult = $this->applyPreferredSmartOrPrintCrop($tempImagePath);
                    $this->preferredCropSummary = $cropResult;
                    if (($cropResult['path'] ?? $tempImagePath) !== $tempImagePath && is_file((string) $cropResult['path'])) {
                        @unlink($tempImagePath);
                        $tempImagePath = $cropResult['path'];
                    }
                    $this->preferredPdfRasterCachePath = $tempImagePath;
                }
            }

            // Resize the extracted image to match thumbnail style dimensions
            // Use GD library to resize (same as image thumbnails for consistency)
            if (! extension_loaded('gd')) {
                throw new \RuntimeException('GD extension is required for PDF thumbnail resizing');
            }

            // Load the extracted PDF page image
            // The library may return PNG or JPG, so detect format from file extension
            $imageExtension = strtolower(pathinfo($tempImagePath, PATHINFO_EXTENSION));
            $sourceImage = null;

            if ($imageExtension === 'png') {
                $sourceImage = imagecreatefrompng($tempImagePath);
            } elseif (in_array($imageExtension, ['jpg', 'jpeg'])) {
                $sourceImage = imagecreatefromjpeg($tempImagePath);
            } else {
                // Try to auto-detect format
                $imageInfo = @getimagesize($tempImagePath);
                if ($imageInfo === false) {
                    throw new \RuntimeException("Failed to detect image format for extracted PDF page: {$tempImagePath}");
                }

                $imageType = $imageInfo[2];
                if ($imageType === IMAGETYPE_PNG) {
                    $sourceImage = imagecreatefrompng($tempImagePath);
                } elseif ($imageType === IMAGETYPE_JPEG) {
                    $sourceImage = imagecreatefromjpeg($tempImagePath);
                } else {
                    throw new \RuntimeException("Unsupported image format for extracted PDF page (type: {$imageType})");
                }
            }

            if ($sourceImage === false) {
                throw new \RuntimeException("Failed to load extracted PDF page image from: {$tempImagePath}");
            }

            try {
                // Get source image dimensions
                $sourceWidth = imagesx($sourceImage);
                $sourceHeight = imagesy($sourceImage);

                if ($sourceWidth === 0 || $sourceHeight === 0) {
                    throw new \RuntimeException('Extracted PDF page image has invalid dimensions');
                }

                // Calculate thumbnail dimensions (maintain aspect ratio)
                $targetWidth = $styleConfig['width'];
                $targetHeight = $styleConfig['height'];
                $fit = $styleConfig['fit'] ?? 'contain';

                [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                    $sourceWidth,
                    $sourceHeight,
                    $targetWidth,
                    $targetHeight,
                    $fit
                );

                // Create thumbnail image
                $thumbImage = imagecreatetruecolor($thumbWidth, $thumbHeight);
                if ($thumbImage === false) {
                    throw new \RuntimeException('Failed to create thumbnail image resource');
                }

                // Check if extracted PDF page has white content
                // When preserve_transparency: skip gray fill for logo display
                $preserveTransparency = ! empty($styleConfig['preserve_transparency']);
                $needsDarkBackground = ! $preserveTransparency
                    && $this->hasWhiteContentOnTransparent($sourceImage, $sourceWidth, $sourceHeight);

                // Use darker background if white content detected, otherwise white
                if ($needsDarkBackground) {
                    // Use gray-400 (#9CA3AF) for strong contrast with white logos
                    $darkGray = imagecolorallocate($thumbImage, 156, 163, 175);
                    imagefill($thumbImage, 0, 0, $darkGray);
                } else {
                    // Fill with white background (PDFs may have transparency)
                    $white = imagecolorallocate($thumbImage, 255, 255, 255);
                    imagefill($thumbImage, 0, 0, $white);
                }

                // Resize image — use nearest-neighbor for small sources (icons, favicons) for crisp upscaling
                $resizeFn = $this->isSmallSource($sourceWidth, $sourceHeight) ? 'imagecopyresized' : 'imagecopyresampled';
                $resizeFn(
                    $thumbImage,
                    $sourceImage,
                    0, 0, 0, 0,
                    $thumbWidth,
                    $thumbHeight,
                    $sourceWidth,
                    $sourceHeight
                );

                // Apply blur for preview thumbnails (LQIP effect)
                if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                    for ($i = 0; $i < 2; $i++) {
                        imagefilter($thumbImage, IMG_FILTER_GAUSSIAN_BLUR);
                    }
                }

                // Determine output format based on config (default to WebP for better compression)
                $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
                $quality = $styleConfig['quality'] ?? 85;

                // Save thumbnail to temporary file
                if ($outputFormat === 'webp' && function_exists('imagewebp')) {
                    $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_').'.webp';
                    if (! imagewebp($thumbImage, $thumbPath, $quality)) {
                        throw new \RuntimeException('Failed to save PDF thumbnail image as WebP');
                    }
                } else {
                    // Fallback to JPEG if WebP not available or not configured
                    $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_').'.jpg';
                    if (! imagejpeg($thumbImage, $thumbPath, $quality)) {
                        throw new \RuntimeException('Failed to save PDF thumbnail image as JPEG');
                    }
                }

                Log::info('[ThumbnailGenerationService] PDF thumbnail generated successfully', [
                    'source_path' => $sourcePath,
                    'thumb_path' => $thumbPath,
                    'thumb_width' => $thumbWidth,
                    'thumb_height' => $thumbHeight,
                    'thumb_size_bytes' => filesize($thumbPath),
                ]);

                return $thumbPath;
            } finally {
                imagedestroy($sourceImage);
                if (isset($thumbImage)) {
                    imagedestroy($thumbImage);
                }
                // Clean up temporary extracted image (retain shared preferred-mode cache file)
                if (file_exists($tempImagePath) && $tempImagePath !== $this->preferredPdfRasterCachePath) {
                    @unlink($tempImagePath);
                }
            }
        } catch (\Spatie\PdfToImage\Exceptions\PdfDoesNotExist $e) {
            // User-friendly error message
            throw new \RuntimeException('The PDF file could not be found or accessed.', 0, $e);
        } catch (\Spatie\PdfToImage\Exceptions\InvalidFormat $e) {
            // User-friendly error message
            throw new \RuntimeException('The PDF file appears to be corrupted or invalid.', 0, $e);
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] PDF thumbnail generation failed', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            // Use sanitized error message for user-facing errors
            $userFriendlyMessage = $this->sanitizeErrorMessage($e->getMessage(), 'pdf');
            throw new \RuntimeException($userFriendlyMessage, 0, $e);
        }
    }

    /**
     * Generate thumbnail for PSD/PSB files (flattened preview).
     *
     * Uses Imagick to read PSD files and flatten layers into a preview image.
     * ImageMagick automatically flattens PSD layers when reading the file.
     *
     * NOTE: Requires Imagick PHP extension with ImageMagick that supports PSD.
     *
     * @param  string  $sourcePath  Local path to PSD/PSB file
     * @param  array  $styleConfig  Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image
     *
     * @throws \RuntimeException If PSD processing fails
     */
    protected function generatePsdThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify Imagick extension is loaded
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('PSD thumbnail generation requires Imagick PHP extension');
        }

        Log::info('[ThumbnailGenerationService] Generating PSD thumbnail', [
            'source_path' => $sourcePath,
            'psd_size_bytes' => filesize($sourcePath),
            'style_config' => $styleConfig,
        ]);

        try {
            // Verify PSD file is readable
            if (! is_readable($sourcePath)) {
                throw new \RuntimeException("PSD file is not readable: {$sourcePath}");
            }

            // Create Imagick instance and read PSD file
            // ImageMagick automatically flattens PSD layers when reading
            $imagick = new \Imagick;
            $imagick->setResolution(72, 72); // Set resolution before reading

            try {
                $imagick->readImage($sourcePath);
            } catch (\ImagickException $e) {
                Log::error('[ThumbnailGenerationService] Failed to read PSD file with Imagick', [
                    'source_path' => $sourcePath,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException("Failed to read PSD file: {$e->getMessage()}", 0, $e);
            }

            // Get first image (PSD files may have multiple layers, but we want the flattened result)
            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();

            $this->imagickNormalizeThumbnailOrientation($imagick, 'psd_imagick', [
                'exif_orientation_tag' => ImageOrientationNormalizer::readExifOrientationTag($sourcePath),
            ]);

            // Get dimensions
            $sourceWidth = $imagick->getImageWidth();
            $sourceHeight = $imagick->getImageHeight();

            if ($sourceWidth === 0 || $sourceHeight === 0) {
                throw new \RuntimeException('PSD file has invalid dimensions');
            }

            Log::info('[ThumbnailGenerationService] PSD file read successfully', [
                'source_path' => $sourcePath,
                'source_width' => $sourceWidth,
                'source_height' => $sourceHeight,
            ]);

            // Calculate target dimensions
            $targetWidth = $styleConfig['width'];
            $targetHeight = $styleConfig['height'];
            $fit = $styleConfig['fit'] ?? 'contain';

            [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                $sourceWidth,
                $sourceHeight,
                $targetWidth,
                $targetHeight,
                $fit
            );

            // Resize — use nearest-neighbor for small sources (icons, favicons) for crisp upscaling
            $filter = $this->isSmallSource($sourceWidth, $sourceHeight) ? \Imagick::FILTER_POINT : \Imagick::FILTER_LANCZOS;
            $imagick->resizeImage($thumbWidth, $thumbHeight, $filter, 1, true);

            // Apply blur for preview thumbnails if configured
            if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2); // Moderate blur
            }

            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);

            // Create temporary output file
            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $outputPath = tempnam(sys_get_temp_dir(), 'psd_thumb_').'.'.$extension;

            // Write to file
            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            // Verify output file was created
            if (! file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException('PSD thumbnail generation failed - output file is missing or empty');
            }

            Log::info('[ThumbnailGenerationService] PSD thumbnail generated successfully', [
                'source_path' => $sourcePath,
                'output_path' => $outputPath,
                'thumb_width' => $thumbWidth,
                'thumb_height' => $thumbHeight,
            ]);

            return $outputPath;
        } catch (\ImagickException $e) {
            Log::error('[ThumbnailGenerationService] PSD thumbnail generation failed (ImagickException)', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("PSD thumbnail generation failed: {$e->getMessage()}", 0, $e);
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] PSD thumbnail generation error', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("PSD thumbnail generation error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate thumbnail for Adobe Illustrator (.ai) files.
     *
     * Modern AI files (Illustrator 9+) are PDF-compatible. ImageMagick with Ghostscript
     * can render them. Uses same approach as PSD - Imagick flattens and converts.
     *
     * @return string Path to generated thumbnail
     *
     * @throws \RuntimeException If generation fails
     */
    protected function generateAiThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify Imagick extension is loaded
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('Illustrator thumbnail generation requires Imagick PHP extension');
        }

        Log::info('[ThumbnailGenerationService] Generating Illustrator thumbnail', [
            'source_path' => $sourcePath,
            'ai_size_bytes' => filesize($sourcePath),
            'style_config' => $styleConfig,
        ]);

        try {
            if (! is_readable($sourcePath)) {
                throw new \RuntimeException("Illustrator file is not readable: {$sourcePath}");
            }

            $imagick = new \Imagick;
            $imagick->setResolution(72, 72);

            try {
                // AI files are PDF-compatible; ImageMagick reads first page via Ghostscript
                $imagick->readImage($sourcePath.'[0]');
            } catch (\ImagickException $e) {
                Log::error('[ThumbnailGenerationService] Failed to read AI file with Imagick', [
                    'source_path' => $sourcePath,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException("Failed to read Illustrator file: {$e->getMessage()}", 0, $e);
            }

            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();

            $this->imagickNormalizeThumbnailOrientation($imagick, 'ai_imagick', [
                'exif_orientation_tag' => ImageOrientationNormalizer::readExifOrientationTag($sourcePath),
            ]);

            $sourceWidth = $imagick->getImageWidth();
            $sourceHeight = $imagick->getImageHeight();

            if ($sourceWidth === 0 || $sourceHeight === 0) {
                throw new \RuntimeException('Illustrator file has invalid dimensions');
            }

            // Detect AI files saved without PDF compatibility - they render as text instructions
            // instead of the design. Such files have very few colors (black text on white).
            $clone = clone $imagick;
            $clone->thumbnailImage(64, 64);
            $histogram = $clone->getImageHistogram();
            $uniqueColors = $histogram ? count($histogram) : 0;
            $clone->clear();
            $clone->destroy();
            if ($uniqueColors > 0 && $uniqueColors < 32) {
                Log::warning('[ThumbnailGenerationService] Illustrator file appears to lack PDF compatibility', [
                    'source_path' => $sourcePath,
                    'unique_colors' => $uniqueColors,
                ]);
                $imagick->clear();
                $imagick->destroy();
                throw new \RuntimeException(
                    'This Illustrator file was saved without PDF compatibility. Re-save it in Adobe Illustrator with '.
                    '"Create PDF Compatible File" enabled (File → Save As → Illustrator Options) to generate a visual preview.'
                );
            }

            Log::info('[ThumbnailGenerationService] Illustrator file read successfully', [
                'source_path' => $sourcePath,
                'source_width' => $sourceWidth,
                'source_height' => $sourceHeight,
            ]);

            $targetWidth = $styleConfig['width'];
            $targetHeight = $styleConfig['height'];
            $fit = $styleConfig['fit'] ?? 'contain';

            [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                $sourceWidth,
                $sourceHeight,
                $targetWidth,
                $targetHeight,
                $fit
            );

            // Resize — use nearest-neighbor for small sources (icons, favicons) for crisp upscaling
            $filter = $this->isSmallSource($sourceWidth, $sourceHeight) ? \Imagick::FILTER_POINT : \Imagick::FILTER_LANCZOS;
            $imagick->resizeImage($thumbWidth, $thumbHeight, $filter, 1, true);

            if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2);
            }

            $outputFormat = config('assets.thumbnail.output_format', 'webp');
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);

            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $outputPath = tempnam(sys_get_temp_dir(), 'ai_thumb_').'.'.$extension;

            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            if (! file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException('Illustrator thumbnail generation failed - output file is missing or empty');
            }

            Log::info('[ThumbnailGenerationService] Illustrator thumbnail generated successfully', [
                'source_path' => $sourcePath,
                'output_path' => $outputPath,
                'thumb_width' => $thumbWidth,
                'thumb_height' => $thumbHeight,
            ]);

            return $outputPath;
        } catch (\ImagickException $e) {
            Log::error('[ThumbnailGenerationService] Illustrator thumbnail failed (ImagickException)', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Illustrator thumbnail generation failed: {$e->getMessage()}", 0, $e);
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] Illustrator thumbnail error', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Illustrator thumbnail generation error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate thumbnail using ImageMagick (admin override for testing unsupported file types).
     *
     * This method attempts to use ImageMagick to convert any file type to an image.
     * Used by admin regeneration to test file types that aren't officially supported.
     *
     * @param  string  $sourcePath  Path to source file
     * @param  array  $styleConfig  Style configuration
     * @param  Asset  $asset  Asset being processed (for logging)
     * @return string Path to generated thumbnail
     *
     * @throws \RuntimeException If generation fails
     */
    protected function generateImageMagickThumbnail(string $sourcePath, array $styleConfig, Asset $asset): string
    {
        // Verify Imagick extension is loaded
        if (! extension_loaded('imagick')) {
            throw new \RuntimeException('ImageMagick thumbnail generation requires Imagick PHP extension');
        }

        try {
            $imagick = new \Imagick;
            $imagick->setResolution(72, 72); // Set resolution before reading

            // Try to read the file with ImageMagick (supports many formats)
            // For multi-page documents, only read first page
            $imagick->readImage($sourcePath.'[0]'); // [0] = first page/frame

            // Get first image from potential multi-page document
            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();

            $this->imagickNormalizeThumbnailOrientation($imagick, 'imagick_admin_override', [
                'exif_orientation_tag' => ImageOrientationNormalizer::readExifOrientationTag($sourcePath),
            ]);

            // Get dimensions
            $sourceWidth = $imagick->getImageWidth();
            $sourceHeight = $imagick->getImageHeight();

            if ($sourceWidth === 0 || $sourceHeight === 0) {
                throw new \RuntimeException('ImageMagick could not read valid dimensions from file');
            }

            Log::info('[ThumbnailGenerationService] ImageMagick read file successfully (admin override)', [
                'asset_id' => $asset->id,
                'source_path' => $sourcePath,
                'source_width' => $sourceWidth,
                'source_height' => $sourceHeight,
                'mime_type' => $asset->mime_type,
            ]);

            // Calculate target dimensions
            $targetWidth = $styleConfig['width'];
            $targetHeight = $styleConfig['height'];
            $fit = $styleConfig['fit'] ?? 'contain';

            [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                $sourceWidth,
                $sourceHeight,
                $targetWidth,
                $targetHeight,
                $fit
            );

            // Resize — use nearest-neighbor for small sources (icons, favicons) for crisp upscaling
            $filter = $this->isSmallSource($sourceWidth, $sourceHeight) ? \Imagick::FILTER_POINT : \Imagick::FILTER_LANCZOS;
            $imagick->resizeImage($thumbWidth, $thumbHeight, $filter, 1, true);

            // Apply blur for preview thumbnails if configured
            if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2); // Moderate blur
            }

            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);

            // Create temporary output file
            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $outputPath = tempnam(sys_get_temp_dir(), 'thumb_imagick_').'.'.$extension;

            // Write to file
            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            // Verify output file was created
            if (! file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException('ImageMagick thumbnail generation failed - output file is missing or empty');
            }

            Log::info('[ThumbnailGenerationService] ImageMagick thumbnail generated (admin override)', [
                'asset_id' => $asset->id,
                'output_path' => $outputPath,
                'thumb_width' => $thumbWidth,
                'thumb_height' => $thumbHeight,
            ]);

            return $outputPath;
        } catch (\ImagickException $e) {
            Log::error('[ThumbnailGenerationService] ImageMagick thumbnail generation failed', [
                'asset_id' => $asset->id,
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
                'mime_type' => $asset->mime_type,
            ]);
            throw new \RuntimeException("ImageMagick thumbnail generation failed: {$e->getMessage()}", 0, $e);
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] ImageMagick thumbnail generation error', [
                'asset_id' => $asset->id,
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
                'mime_type' => $asset->mime_type,
            ]);
            throw new \RuntimeException("ImageMagick thumbnail generation error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate thumbnail for Office files (Word, Excel, PowerPoint).
     *
     * Pipeline: LibreOffice headless converts the document to PDF once per {@see generateThumbnails}
     * run, then {@see generatePdfThumbnail} rasterizes page 1 for each style (shared raster cache in preferred mode).
     *
     * @return string Path to generated thumbnail image (WebP/JPEG per config)
     *
     * @throws \RuntimeException When conversion or PDF rasterization fails
     */
    protected function generateOfficeThumbnail(string $sourcePath, array $styleConfig): string
    {
        if (! file_exists($sourcePath) || ! is_readable($sourcePath)) {
            throw new \RuntimeException("Source Office file does not exist or is not readable: {$sourcePath}");
        }

        $converter = app(\App\Services\Office\LibreOfficeDocumentPreviewService::class);

        if ($this->officeIntermediatePdfPath === null || ! is_file($this->officeIntermediatePdfPath)) {
            $result = $converter->convertToPdf($sourcePath);
            $this->officeLibreOfficeWorkDir = $result['work_dir'];
            $this->officeIntermediatePdfPath = $result['pdf_path'];
        }

        return $this->generatePdfThumbnail($this->officeIntermediatePdfPath, $styleConfig);
    }

    /**
     * Generate thumbnail for video files (poster frame extraction).
     *
     * 🔒 VIDEO POSTER / STATIC THUMBNAIL LOCK — Do not change FFmpeg strategy casually.
     *
     * Single implementation for grid/drawer **static** video thumbnails (not hover MP4). Used by
     * {@see GenerateThumbnailsJob}, bulk regenerate, admin regenerate-styles, and
     * {@see \App\Http\Controllers\AssetThumbnailController::regenerateVideoThumbnail}.
     *
     * Orientation contract: (1) plain frame extract (FFmpeg default input autorotation, matches QuickTime/VLC);
     * (2) on failure, -noautorotate + manual {@see VideoDisplayProbe::ffmpegTransposeFilters()} from
     * {@see self::getVideoInfo()} / {@see VideoDisplayProbe::dimensionsFromFfprobe()}.
     *
     * Frame timestamp: ~30% of duration. Output is resized below to style (WebP/JPEG per config).
     *
     * @param  string  $sourcePath  Local path to video file
     * @param  array  $styleConfig  Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image
     *
     * @throws \RuntimeException If video processing fails
     */
    protected function generateVideoThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify source file exists
        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source video file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException('Source video file is empty (size: 0 bytes)');
        }

        Log::info('[ThumbnailGenerationService] Generating video thumbnail using FFmpeg', [
            'source_path' => $sourcePath,
            'source_file_size' => $sourceFileSize,
            'style_config' => $styleConfig,
        ]);

        // Check if FFmpeg is available
        $ffmpegPath = $this->findFFmpegPath();
        if (! $ffmpegPath) {
            throw new \RuntimeException('FFmpeg is not installed or not found in PATH. Video processing requires FFmpeg.');
        }

        try {
            // Get video duration and dimensions using FFprobe
            $videoInfo = $this->getVideoInfo($sourcePath, $ffmpegPath);
            $duration = $videoInfo['duration'] ?? 0;
            $width = $videoInfo['width'] ?? 0;
            $height = $videoInfo['height'] ?? 0;

            if ($duration <= 0) {
                throw new \RuntimeException('Unable to determine video duration');
            }

            if ($width === 0 || $height === 0) {
                throw new \RuntimeException('Unable to determine video dimensions');
            }

            Log::info('[ThumbnailGenerationService] Video info extracted', [
                'source_path' => $sourcePath,
                'duration' => $duration,
                'width' => $width,
                'height' => $height,
            ]);

            // Calculate frame timestamp: 25-40% of duration (defaults to 30%)
            // This avoids black frames at the start and ensures we get actual content
            $timestampPercent = 0.30; // 30% of duration
            $timestamp = max(0.5, $duration * $timestampPercent); // Minimum 0.5 seconds

            // Extract frame at calculated timestamp
            $tempImagePath = tempnam(sys_get_temp_dir(), 'video_thumb_').'.jpg';

            // Static grid poster: prefer FFmpeg’s default input autorotation first (matches QuickTime/VLC for
            // typical MOV/MP4 metadata). Manual ffprobe + -noautorotate is the fallback when that fails or
            // returns empty output — avoids “success” with wrong rotation when our probe picked the wrong stream.
            $rotationDeg = (int) ($videoInfo['rotation'] ?? 0);
            $transpose = VideoDisplayProbe::ffmpegTransposeFilters($rotationDeg);
            $codedW = (int) ($videoInfo['coded_width'] ?? 0);
            $codedH = (int) ($videoInfo['coded_height'] ?? 0);
            $dispW = (int) ($videoInfo['width'] ?? 0);
            $dispH = (int) ($videoInfo['height'] ?? 0);
            $vfParts = array_values(array_filter([
                $transpose !== '' ? $transpose : null,
                $rotationDeg === 0 && $codedW > 0 && $codedH > 0 && ($dispW !== $codedW || $dispH !== $codedH)
                    ? sprintf('scale=%d:%d:flags=lanczos', $dispW, $dispH)
                    : null,
            ]));
            $vfClause = $vfParts !== [] ? sprintf('-vf %s ', escapeshellarg(implode(',', $vfParts))) : '';

            $cmdAutorotate = sprintf(
                '%s -ss %.2f -i %s -vframes 1 -q:v 2 -y %s 2>&1',
                escapeshellarg($ffmpegPath),
                $timestamp,
                escapeshellarg($sourcePath),
                escapeshellarg($tempImagePath)
            );
            $cmdManual = sprintf(
                '%s -ss %.2f -noautorotate -i %s %s-vframes 1 -q:v 2 -y %s 2>&1',
                escapeshellarg($ffmpegPath),
                $timestamp,
                escapeshellarg($sourcePath),
                $vfClause,
                escapeshellarg($tempImagePath)
            );

            $output = [];
            $returnCode = 0;
            Log::info('[ThumbnailGenerationService] Extracting video frame (autorotate first)', [
                'source_path' => $sourcePath,
                'timestamp' => $timestamp,
                'command' => $cmdAutorotate,
            ]);
            exec($cmdAutorotate, $output, $returnCode);

            if ($returnCode !== 0 || ! file_exists($tempImagePath) || filesize($tempImagePath) === 0) {
                $errorOutput = implode("\n", $output);
                Log::warning('[ThumbnailGenerationService] Autorotate frame extract failed; retrying with noautorotate + manual rotation', [
                    'source_path' => $sourcePath,
                    'return_code' => $returnCode,
                    'output_tail' => substr($errorOutput, -800),
                ]);
                if (file_exists($tempImagePath)) {
                    @unlink($tempImagePath);
                }
                Log::info('[ThumbnailGenerationService] Extracting video frame (manual rotation)', [
                    'source_path' => $sourcePath,
                    'timestamp' => $timestamp,
                    'command' => $cmdManual,
                    'rotation_deg' => $rotationDeg,
                ]);
                $output = [];
                exec($cmdManual, $output, $returnCode);
            }

            if ($returnCode !== 0 || ! file_exists($tempImagePath) || filesize($tempImagePath) === 0) {
                $errorOutput = implode("\n", $output);
                Log::error('[ThumbnailGenerationService] FFmpeg frame extraction failed', [
                    'source_path' => $sourcePath,
                    'return_code' => $returnCode,
                    'output' => $errorOutput,
                ]);
                throw new \RuntimeException("Failed to extract video frame: FFmpeg returned error code {$returnCode}");
            }

            Log::info('[ThumbnailGenerationService] Video frame extracted', [
                'source_path' => $sourcePath,
                'temp_image_path' => $tempImagePath,
                'image_size_bytes' => filesize($tempImagePath),
            ]);

            if ($this->generationMode === ThumbnailMode::Preferred->value) {
                if ($this->preferredVideoRasterCachePath !== null) {
                    @unlink($tempImagePath);
                    $tempImagePath = $this->copyRasterToTemp($this->preferredVideoRasterCachePath);
                } else {
                    $videoCrop = $this->applyPreferredSmartOrPrintCrop($tempImagePath);
                    $this->preferredCropSummary = $videoCrop;
                    if (($videoCrop['path'] ?? $tempImagePath) !== $tempImagePath && is_file((string) $videoCrop['path'])) {
                        @unlink($tempImagePath);
                        $tempImagePath = $videoCrop['path'];
                    }
                    $this->preferredVideoRasterCachePath = $tempImagePath;
                }
            }

            // Resize the extracted frame to match thumbnail style dimensions
            // Use GD library to resize (same as image thumbnails for consistency)
            if (! extension_loaded('gd')) {
                throw new \RuntimeException('GD extension is required for video thumbnail resizing');
            }

            // Load the extracted frame
            $sourceImage = imagecreatefromjpeg($tempImagePath);
            if ($sourceImage === false) {
                throw new \RuntimeException("Failed to load extracted video frame from: {$tempImagePath}");
            }

            try {
                // Get source image dimensions
                $sourceWidth = imagesx($sourceImage);
                $sourceHeight = imagesy($sourceImage);

                if ($sourceWidth === 0 || $sourceHeight === 0) {
                    throw new \RuntimeException('Extracted video frame has invalid dimensions');
                }

                // Calculate thumbnail dimensions (maintain aspect ratio)
                $targetWidth = $styleConfig['width'];
                $targetHeight = $styleConfig['height'];
                $fit = $styleConfig['fit'] ?? 'contain';

                [$thumbWidth, $thumbHeight] = $this->calculateDimensions(
                    $sourceWidth,
                    $sourceHeight,
                    $targetWidth,
                    $targetHeight,
                    $fit
                );

                // Create thumbnail image
                $thumbImage = imagecreatetruecolor($thumbWidth, $thumbHeight);
                if ($thumbImage === false) {
                    throw new \RuntimeException('Failed to create thumbnail image resource');
                }

                // Fill with white background
                $white = imagecolorallocate($thumbImage, 255, 255, 255);
                imagefill($thumbImage, 0, 0, $white);

                // Resize image — use nearest-neighbor for small sources (icons, favicons) for crisp upscaling
                $resizeFn = $this->isSmallSource($sourceWidth, $sourceHeight) ? 'imagecopyresized' : 'imagecopyresampled';
                $resizeFn(
                    $thumbImage,
                    $sourceImage,
                    0, 0, 0, 0,
                    $thumbWidth,
                    $thumbHeight,
                    $sourceWidth,
                    $sourceHeight
                );

                // Apply blur for preview thumbnails (LQIP effect)
                if (! empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                    for ($i = 0; $i < 2; $i++) {
                        imagefilter($thumbImage, IMG_FILTER_GAUSSIAN_BLUR);
                    }
                }

                // Determine output format based on config (default to WebP for better compression)
                $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
                $quality = $styleConfig['quality'] ?? 85;

                // Save thumbnail to temporary file
                if ($outputFormat === 'webp' && function_exists('imagewebp')) {
                    $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_').'.webp';
                    if (! imagewebp($thumbImage, $thumbPath, $quality)) {
                        throw new \RuntimeException('Failed to save video thumbnail image as WebP');
                    }
                } else {
                    // Fallback to JPEG if WebP not available or not configured
                    $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_').'.jpg';
                    if (! imagejpeg($thumbImage, $thumbPath, $quality)) {
                        throw new \RuntimeException('Failed to save video thumbnail image as JPEG');
                    }
                }

                Log::info('[ThumbnailGenerationService] Video thumbnail generated successfully', [
                    'source_path' => $sourcePath,
                    'thumb_path' => $thumbPath,
                    'thumb_width' => $thumbWidth,
                    'thumb_height' => $thumbHeight,
                    'thumb_size_bytes' => filesize($thumbPath),
                ]);

                return $thumbPath;
            } finally {
                imagedestroy($sourceImage);
                if (isset($thumbImage)) {
                    imagedestroy($thumbImage);
                }
                // Clean up temporary extracted frame (retain shared preferred-mode cache file)
                if (file_exists($tempImagePath) && $tempImagePath !== $this->preferredVideoRasterCachePath) {
                    @unlink($tempImagePath);
                }
            }
        } catch (\Exception $e) {
            Log::error('[ThumbnailGenerationService] Video thumbnail generation failed', [
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            throw new \RuntimeException("Video thumbnail generation failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Find FFmpeg executable path.
     *
     * @return string|null Path to FFmpeg executable or null if not found
     */
    protected function findFFmpegPath(): ?string
    {
        // Common FFmpeg paths
        $possiblePaths = [
            'ffmpeg', // In PATH
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg', // macOS Homebrew
        ];

        foreach ($possiblePaths as $path) {
            // Check if command exists and is executable
            if ($path === 'ffmpeg') {
                // Check if ffmpeg is in PATH
                $output = [];
                $returnCode = 0;
                exec('which ffmpeg 2>&1', $output, $returnCode);
                if ($returnCode === 0 && ! empty($output[0]) && file_exists($output[0])) {
                    return $output[0];
                }
            } elseif (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get video information using FFprobe.
     *
     * @param  string  $videoPath  Path to video file
     * @param  string  $ffmpegPath  Path to FFmpeg executable
     * @return array Video information (duration, width, height)
     *
     * @throws \RuntimeException If FFprobe fails
     */
    protected function getVideoInfo(string $videoPath, string $ffmpegPath): array
    {
        // Try to use ffprobe first (more reliable)
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
        if (! file_exists($ffprobePath) || ! is_executable($ffprobePath)) {
            // Fallback: use ffmpeg to probe
            $ffprobePath = $ffmpegPath;
        }

        // Get video information using JSON output
        $command = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($videoPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorOutput = implode("\n", $output);
            Log::error('[ThumbnailGenerationService] FFprobe failed', [
                'video_path' => $videoPath,
                'return_code' => $returnCode,
                'output' => $errorOutput,
            ]);
            throw new \RuntimeException("Failed to get video information: FFprobe returned error code {$returnCode}");
        }

        $jsonOutput = implode("\n", $output);
        $videoData = json_decode($jsonOutput, true);

        if (! $videoData || ! isset($videoData['format'])) {
            throw new \RuntimeException('Failed to parse video information from FFprobe output');
        }

        $dims = VideoDisplayProbe::dimensionsFromFfprobe($videoData);

        if (! $dims) {
            throw new \RuntimeException('No video stream found in file');
        }

        $duration = (float) ($videoData['format']['duration'] ?? 0);

        return [
            'duration' => $duration,
            'width' => $dims['display_width'],
            'height' => $dims['display_height'],
            'coded_width' => $dims['width'],
            'coded_height' => $dims['height'],
            'rotation' => $dims['rotation'],
        ];
    }

    /**
     * Detect file type from mime type and extension.
     * Version-pure: when version is provided, use ONLY version (never asset).
     *
     * @param  Asset  $asset  Used only when version is null (legacy path)
     * @param  AssetVersion|null  $version  When provided, use ONLY version->mime_type and version->file_path
     * @return string File type: image, pdf, tiff, psd, ai, office, video, or unknown
     */
    protected function detectFileType(Asset $asset, ?AssetVersion $version = null): string
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);

        if ($version) {
            // Version-pure: use ONLY version. Ignore asset completely.
            $mime = $version->mime_type;
            $ext = $version->file_path
                ? strtolower(pathinfo($version->file_path, PATHINFO_EXTENSION))
                : '';
        } else {
            // Legacy (Starter): use asset only. No mixed fallback.
            $mime = $asset->mime_type;
            $ext = $asset->storage_root_path
                ? strtolower(pathinfo($asset->storage_root_path, PATHINFO_EXTENSION))
                : '';
        }
        $ext = $ext ?: '';
        $fileType = $fileTypeService->detectFileType($mime, $ext);

        // TIFF fallback: GD getimagesize() does not support TIFF. When MIME is wrong (e.g. from S3)
        // or application/octet-stream, we may get 'image' or 'unknown'. For .tif/.tiff extension,
        // route to tiff (Imagick) to avoid "Downloaded file is not a valid image" errors.
        $resolved = $fileType ?? 'unknown';
        if (in_array($ext, ['tif', 'tiff'], true) && in_array($resolved, ['image', 'unknown'], true)) {
            return 'tiff';
        }

        if ($ext === 'cr2' && in_array($resolved, ['image', 'unknown'], true)) {
            return 'cr2';
        }

        if (in_array($ext, ['heic', 'heif'], true) && in_array($resolved, ['image', 'unknown'], true)) {
            return 'heic';
        }

        // SVG fallback: SVG is XML, not raster. When MIME is wrong or application/octet-stream,
        // we may get 'image' or 'unknown'. For .svg extension, route to svg to avoid getimagesize() failure.
        if (in_array($ext, ['svg'], true) && in_array($resolved, ['image', 'unknown'], true)) {
            return 'svg';
        }

        if ($fileTypeService->isOfficeDocument($mime ? strtolower((string) $mime) : null, $ext !== '' ? $ext : null)) {
            return 'office';
        }

        return $resolved;
    }

    /**
     * Whether the source image is small (either dimension < 100px).
     * Small images (icons, favicons) use nearest-neighbor interpolation for crisp upscaling.
     */
    protected function isSmallSource(int $sourceWidth, int $sourceHeight): bool
    {
        return $sourceWidth < 100 || $sourceHeight < 100;
    }

    /**
     * Calculate thumbnail dimensions maintaining aspect ratio.
     * For small images (either dimension < 100px, e.g. icons/favicons), never upscale —
     * output is capped at source to prevent blurriness. Normal images use cover/contain as-is.
     *
     * @param  string  $fit  Strategy: 'contain' (fit within), 'cover' (fill), 'width', 'height'
     * @return array [width, height]
     */
    protected function calculateDimensions(
        int $sourceWidth,
        int $sourceHeight,
        int $targetWidth,
        int $targetHeight,
        string $fit = 'contain'
    ): array {
        $sourceRatio = $sourceWidth / $sourceHeight;
        $targetRatio = $targetWidth / $targetHeight;

        switch ($fit) {
            case 'cover':
                if ($sourceRatio > $targetRatio) {
                    $w = (int) ($targetHeight * $sourceRatio);
                    $h = $targetHeight;
                } else {
                    $w = $targetWidth;
                    $h = (int) ($targetWidth / $sourceRatio);
                }
                break;
            case 'width':
                $w = $targetWidth;
                $h = (int) ($targetWidth / $sourceRatio);
                break;
            case 'height':
                $w = (int) ($targetHeight * $sourceRatio);
                $h = $targetHeight;
                break;
            case 'contain':
            default:
                if ($sourceRatio > $targetRatio) {
                    $w = $targetWidth;
                    $h = (int) ($targetWidth / $sourceRatio);
                } else {
                    $w = (int) ($targetHeight * $sourceRatio);
                    $h = $targetHeight;
                }
                break;
        }

        // Small images (icons, favicons under 100px): we now upscale with nearest-neighbor
        // (see isSmallSource + FILTER_POINT / imagecopyresized) for crisp pixel art.
        // No dimension cap — output follows target; interpolation handles quality.

        return [$w, $h];
    }

    /**
     * Get thumbnail metadata (width, height, size).
     */
    protected function getThumbnailMetadata(string $thumbnailPath): array
    {
        $info = @getimagesize($thumbnailPath);
        // For SVG and other non-raster formats, getimagesize returns false
        $width = $info !== false ? ($info[0] ?? null) : null;
        $height = $info !== false ? ($info[1] ?? null) : null;

        return [
            'width' => $width,
            'height' => $height,
            'size_bytes' => file_exists($thumbnailPath) ? filesize($thumbnailPath) : 0,
        ];
    }

    /**
     * Get MIME type for file extension.
     *
     * @return string MIME type
     */
    protected function getMimeTypeForExtension(string $extension): string
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);

        return $fileTypeService->getMimeTypeForExtension($extension) ?? 'image/jpeg';
    }

    /**
     * Detect if image has white content on transparent background (e.g. white logo).
     *
     * Only add a dark backdrop when the image is effectively a white/near-white logo or graphic
     * on transparency. We never add backdrop for photography or images with large transparent
     * areas where the visible content is not mostly white.
     *
     * Criteria (all required):
     * - At least 8% of pixels are transparent (so it's "content on transparent", not opaque photo).
     * - Among visible (opaque) pixels, at least 55% are white/near-white (relaxed for anti-aliasing).
     *
     * @param  resource  $imageResource  GD image resource
     * @param  int  $width  Image width
     * @param  int  $height  Image height
     * @return bool True only when image appears to be a white logo/graphic on transparent background
     */
    protected function hasWhiteContentOnTransparent($imageResource, int $width, int $height): bool
    {
        $sampleSize = min(50, max(10, (int) min($width, $height) / 5));
        $stepX = max(1, (int) ($width / $sampleSize));
        $stepY = max(1, (int) ($height / $sampleSize));

        $whitePixelCount = 0;
        $visiblePixelCount = 0;
        $transparentPixelCount = 0;
        $totalSampled = 0;

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $totalSampled++;
                $color = imagecolorat($imageResource, $x, $y);
                // Support both 7-bit (0-127) and 8-bit (0-255) alpha; GD varies by format
                $alpha = ($color >> 24) & 0xFF;
                $red = ($color >> 16) & 0xFF;
                $green = ($color >> 8) & 0xFF;
                $blue = $color & 0xFF;

                // Treat as transparent: alpha >= 100 (covers 7-bit 100-127 and 8-bit 200-255)
                $isTransparent = $alpha >= 100;
                if ($isTransparent) {
                    $transparentPixelCount++;

                    continue;
                }
                $visiblePixelCount++;
                // Near-white: allow anti-aliased edges (210 threshold)
                $isWhite = ($red > 210 && $green > 210 && $blue > 210);
                if ($isWhite) {
                    $whitePixelCount++;
                }
            }
        }

        if ($visiblePixelCount === 0) {
            return false;
        }

        $transparentRatio = $transparentPixelCount / $totalSampled;
        $whiteRatio = $whitePixelCount / $visiblePixelCount;

        // Relaxed thresholds: catch more white logos (anti-aliasing, varied layouts)
        $hasSignificantTransparency = $transparentRatio >= 0.08;
        $isNearlyAllWhiteContent = $whiteRatio >= 0.55;
        $needsDarkBackground = $hasSignificantTransparency && $isNearlyAllWhiteContent;

        Log::debug('[ThumbnailGenerationService] White content detection', [
            'total_sampled' => $totalSampled,
            'visible_pixels' => $visiblePixelCount,
            'transparent_pixels' => $transparentPixelCount,
            'transparent_ratio' => round($transparentRatio, 2),
            'white_pixels' => $whitePixelCount,
            'white_ratio' => round($whiteRatio, 2),
            'needs_dark_background' => $needsDarkBackground,
        ]);

        return $needsDarkBackground;
    }

    /**
     * Generate enhanced-mode thumbnails from an existing local raster (preferred or original pipeline output).
     * Does not download or process the original asset file — keeps main pipeline untouched.
     *
     * @return array{
     *     thumbnails: array<string, array<string, array<string, mixed>>>,
     *     thumbnail_dimensions: array<string, array<string, array{width:int, height:int}>>,
     *     preview_thumbnails: array<string, array<string, mixed>>,
     *     template: string,
     *     source_mode: string
     * }
     */
    public function generateEnhancedPreviewsFromLocalRaster(
        Asset $asset,
        AssetVersion $version,
        string $localRasterPath,
        string $templateId,
        string $sourceModeLabel
    ): array {
        $mode = ThumbnailMode::Enhanced->value;
        $this->generationMode = $mode;

        $bucket = $asset->storageBucket;
        if (! $bucket) {
            throw new \RuntimeException('Asset missing storage bucket');
        }

        $allStyles = config('assets.thumbnail_styles', []);
        $styleNames = config('enhanced_preview.styles', ['thumb', 'medium']);
        if (! is_array($styleNames) || $styleNames === []) {
            $styleNames = ['thumb', 'medium'];
        }

        $renderer = app(TemplateRenderer::class);
        $outputBasePath = $version->file_path ? dirname($version->file_path) : null;

        $thumbnails = [];
        $thumbnailDimensions = [];
        $tempFiles = [];

        try {
            foreach ($styleNames as $styleName) {
                if ($styleName === 'preview' || ! isset($allStyles[$styleName]) || ! is_array($allStyles[$styleName])) {
                    continue;
                }
                $styleConfig = $allStyles[$styleName];
                $localOut = $renderer->renderCompositedThumbnail($localRasterPath, $templateId, (string) $styleName, $styleConfig);
                if ($localOut === null || ! is_file($localOut)) {
                    continue;
                }
                $tempFiles[] = $localOut;

                $s3Path = $this->uploadThumbnailToS3(
                    $bucket,
                    $asset,
                    $localOut,
                    (string) $styleName,
                    $outputBasePath,
                    $version,
                    $mode
                );

                $info = $this->getThumbnailMetadata($localOut);
                $thumbnails[$styleName] = [
                    'path' => $s3Path,
                    'width' => $info['width'] ?? null,
                    'height' => $info['height'] ?? null,
                    'size_bytes' => $info['size_bytes'] ?? (is_file($localOut) ? filesize($localOut) : null),
                    'generated_at' => now()->toIso8601String(),
                ];
                if (isset($info['width'], $info['height'])) {
                    $thumbnailDimensions[$styleName] = [
                        'width' => (int) $info['width'],
                        'height' => (int) $info['height'],
                    ];
                }
            }
        } finally {
            foreach ($tempFiles as $f) {
                if (is_string($f) && is_file($f)) {
                    @unlink($f);
                }
            }
        }

        if ($thumbnails === []) {
            throw new \RuntimeException('No enhanced thumbnails were generated');
        }

        return [
            'thumbnails' => [$mode => $thumbnails],
            'thumbnail_dimensions' => [$mode => $thumbnailDimensions],
            'preview_thumbnails' => [$mode => []],
            'template' => $templateId,
            'source_mode' => $sourceModeLabel,
        ];
    }

    /**
     * Resize an AI presentation output raster into presentation-mode thumbnail styles and upload to S3.
     *
     * @return array{
     *     thumbnails: array<string, array<string, array<string, mixed>>>,
     *     thumbnail_dimensions: array<string, array<string, array{width:int, height:int}>>,
     *     styles_generated: list<string>
     * }
     */
    public function generatePresentationPreviewsFromLocalRaster(
        Asset $asset,
        AssetVersion $version,
        string $localRasterPath
    ): array {
        $mode = ThumbnailMode::Presentation->value;
        $this->generationMode = $mode;

        $bucket = $asset->storageBucket;
        if (! $bucket) {
            throw new \RuntimeException('Asset missing storage bucket');
        }

        $allStyles = config('assets.thumbnail_styles', []);
        $styleNames = config('presentation_preview.styles', ['thumb', 'medium']);
        if (! is_array($styleNames) || $styleNames === []) {
            $styleNames = ['thumb', 'medium'];
        }

        $thumbnails = [];
        $thumbnailDimensions = [];
        $tempFiles = [];

        try {
            foreach ($styleNames as $styleName) {
                if ($styleName === 'preview' || ! isset($allStyles[$styleName]) || ! is_array($allStyles[$styleName])) {
                    continue;
                }
                $styleConfig = $allStyles[$styleName];
                $localOut = $this->generateImageThumbnail($localRasterPath, $styleConfig);
                if ($localOut === null || ! is_file($localOut)) {
                    continue;
                }
                $tempFiles[] = $localOut;

                $s3Path = $this->uploadThumbnailToS3(
                    $bucket,
                    $asset,
                    $localOut,
                    (string) $styleName,
                    null,
                    $version,
                    $mode
                );

                $info = $this->getThumbnailMetadata($localOut);
                $thumbnails[$styleName] = [
                    'path' => $s3Path,
                    'width' => $info['width'] ?? null,
                    'height' => $info['height'] ?? null,
                    'size_bytes' => $info['size_bytes'] ?? (is_file($localOut) ? filesize($localOut) : null),
                    'generated_at' => now()->toIso8601String(),
                ];
                if (isset($info['width'], $info['height'])) {
                    $thumbnailDimensions[$styleName] = [
                        'width' => (int) $info['width'],
                        'height' => (int) $info['height'],
                    ];
                }
            }
        } finally {
            foreach ($tempFiles as $f) {
                if (is_string($f) && is_file($f)) {
                    @unlink($f);
                }
            }
        }

        if ($thumbnails === []) {
            throw new \RuntimeException('No presentation thumbnails were generated');
        }

        return [
            'thumbnails' => [$mode => $thumbnails],
            'thumbnail_dimensions' => [$mode => $thumbnailDimensions],
            'styles_generated' => array_keys($thumbnails),
        ];
    }

    /**
     * Create S3 client instance.
     */
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
