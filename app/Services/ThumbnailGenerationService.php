<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\StorageBucket;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Thumbnail Generation Service
 *
 * Enterprise-grade thumbnail generation service that handles multiple file types
 * and generates predefined thumbnail styles atomically per asset.
 *
 * File type support:
 * - Images: jpg, png, webp, gif (direct thumbnail generation via GD library) ✓ IMPLEMENTED
 * - TIFF: thumbnail generation via Imagick (requires Imagick PHP extension) ✓ IMPLEMENTED
 * - AVIF: thumbnail generation via Imagick (requires Imagick PHP extension) ✓ IMPLEMENTED
 * - PDF: first page extraction (page 1 only, via spatie/pdf-to-image) ✓ IMPLEMENTED
 * 
 * Thumbnail output format:
 * - Configurable: WebP (default, better compression) or JPEG (maximum compatibility)
 * - WebP offers 25-35% smaller file sizes with similar quality
 * - Modern browser support is excellent (Chrome, Firefox, Safari, Edge)
 * - PSD/PSB: flattened preview (best-effort) - @todo Implement
 * - AI: best-effort preview - @todo Implement
 * - Office (Word/Excel/PowerPoint): icon or first-page render (best-effort) - @todo Implement
 * - Video: mp4, mov → first frame extraction - @todo Implement
 *
 * All thumbnails are stored in S3 alongside the original asset.
 * Thumbnail paths follow pattern: {asset_path_base}/thumbnails/{style}/{filename}
 *
 * PDF thumbnail generation:
 * - Uses spatie/pdf-to-image with ImageMagick/Ghostscript backend
 * - Only page 1 is processed (enforced by config and safety guards)
 * - Safety guards: max file size (100MB default), timeout (60s default), page limit (1)
 * - Thumbnails are resized using GD library (same as image thumbnails for consistency)
 *
 * @todo PSD / PSB thumbnail generation (Imagick)
 * @todo PDF multi-page previews (future enhancement - currently page 1 only)
 * @todo Video poster frame generation (FFmpeg)
 * @todo Office document previews (LibreOffice)
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

    /**
     * Convert technical error messages to user-friendly messages.
     * 
     * This sanitizes exception messages and technical details that users shouldn't see,
     * replacing them with clear, actionable error messages.
     * 
     * @param string $errorMessage The raw error message
     * @param string|null $fileType Optional file type for type-specific errors
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
     * @param S3Client|null $s3Client Optional S3 client for testing
     */
    public function __construct(
        ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Regenerate specific thumbnail styles for an asset.
     *
     * Admin-only method for regenerating specific thumbnail styles.
     * Used for troubleshooting or testing new file types.
     *
     * @param Asset $asset The asset to regenerate thumbnails for
     * @param array $styleNames Array of style names to regenerate (e.g., ['thumb', 'medium', 'large'])
     * @param bool $forceImageMagick If true, bypass file type checks and force ImageMagick usage (admin override for testing)
     * @return array Array of regenerated thumbnail metadata
     * @throws \RuntimeException If regeneration fails
     */
    public function regenerateThumbnailStyles(Asset $asset, array $styleNames, bool $forceImageMagick = false): array
    {
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            throw new \RuntimeException('Asset missing storage path or bucket');
        }

        $bucket = $asset->storageBucket;
        $allStyles = config('assets.thumbnail_styles', []);
        
        // Filter to only requested styles
        $styles = [];
        foreach ($styleNames as $styleName) {
            if (!isset($allStyles[$styleName])) {
                throw new \RuntimeException("Invalid thumbnail style: {$styleName}");
            }
            $styles[$styleName] = $allStyles[$styleName];
        }
        
        if (empty($styles)) {
            throw new \RuntimeException('No valid thumbnail styles specified');
        }

        // Download source file (same logic as generateThumbnails)
        $sourceS3Path = $asset->storage_root_path;
        $tempPath = $this->downloadFromS3($bucket, $sourceS3Path, $asset->id);
        
        if (!file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Downloaded source file is invalid or empty');
        }

        // Admin override: If forceImageMagick is true, bypass file type detection and use ImageMagick
        $fileType = $forceImageMagick ? 'imagick_override' : $this->detectFileType($asset);
        $regenerated = [];
        
        try {
            // Generate only requested styles
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
                        // Upload to S3
                        $s3ThumbnailPath = $this->uploadThumbnailToS3(
                            $bucket,
                            $asset,
                            $thumbnailPath,
                            $styleName,
                            $outputBasePath
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
            
            // Update metadata with regenerated styles
            $metadata = $asset->metadata ?? [];
            
            // Separate preview and final styles
            $previewStyles = [];
            $finalStyles = [];
            
            foreach ($regenerated as $styleName => $styleData) {
                if ($styleName === 'preview') {
                    $previewStyles[$styleName] = $styleData;
                } else {
                    $finalStyles[$styleName] = $styleData;
                }
            }
            
            // Merge with existing thumbnails
            if (!empty($previewStyles)) {
                $metadata['preview_thumbnails'] = array_merge(
                    $metadata['preview_thumbnails'] ?? [],
                    $previewStyles
                );
            }
            
            if (!empty($finalStyles)) {
                $metadata['thumbnails'] = array_merge(
                    $metadata['thumbnails'] ?? [],
                    $finalStyles
                );
                $metadata['thumbnails_generated_at'] = now()->toIso8601String();
                // Persist thumbnail_dimensions when medium was regenerated
                if (isset($finalStyles['medium']['width'], $finalStyles['medium']['height'])) {
                    $metadata['thumbnail_dimensions'] = array_merge(
                        $metadata['thumbnail_dimensions'] ?? [],
                        [
                            'medium' => [
                                'width' => $finalStyles['medium']['width'],
                                'height' => $finalStyles['medium']['height'],
                            ],
                        ]
                    );
                }
            }
            
            $asset->update(['metadata' => $metadata]);
            
            return $regenerated;
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
     * @param AssetVersion $version The version to generate thumbnails for
     * @return array Array of thumbnail metadata
     */
    public function generateThumbnailsForVersion(AssetVersion $version): array
    {
        return $this->generateThumbnails(
            $version->asset,
            $version->file_path,
            dirname($version->file_path),
            $version
        );
    }

    /**
     * Generate all thumbnail styles for an asset atomically.
     *
     * Downloads the asset from S3, generates all configured thumbnail styles,
     * uploads thumbnails to S3, and returns metadata about generated thumbnails.
     *
     * @param Asset $asset The asset to generate thumbnails for
     * @param string|null $sourceS3Path Override source path (default: asset->storage_root_path)
     * @param string|null $outputBasePath Override output base for thumbnails (default: dirname of source)
     * @param AssetVersion|null $version When provided, use version->mime_type for file type detection (version-aware)
     * @return array Array of thumbnail metadata with keys: thumb, medium, large
     * @throws \RuntimeException If thumbnail generation fails
     */
    public function generateThumbnails(Asset $asset, ?string $sourceS3Path = null, ?string $outputBasePath = null, ?AssetVersion $version = null): array
    {
        $sourceS3Path = $sourceS3Path ?? $asset->storage_root_path;
        $outputBasePath = $outputBasePath ?? ($sourceS3Path ? dirname($sourceS3Path) : null);

        if (!$sourceS3Path || !$asset->storageBucket) {
            throw new \RuntimeException('Asset missing storage path or bucket');
        }

        $bucket = $asset->storageBucket;
        $styles = config('assets.thumbnail_styles', []);
        
        if (empty($styles)) {
            throw new \RuntimeException('No thumbnail styles configured');
        }
        
        Log::info('[ThumbnailGenerationService] Generating thumbnails from source', [
            'asset_id' => $asset->id,
            'source_s3_path' => $sourceS3Path,
            'bucket' => $bucket->name,
        ]);

        // Download original file to temporary location
        // This is the SAME source file used for metadata extraction
        $tempPath = $this->downloadFromS3($bucket, $sourceS3Path, $asset->id);
        
        // Verify downloaded file is valid
        if (!file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Downloaded source file is invalid or empty');
        }
        
        $sourceFileSize = filesize($tempPath);
        
        // Detect file type: use version->mime_type when version-aware (from FileInspectionService)
        $fileType = $this->detectFileType($asset, $version);
        
        // Capture source image dimensions ONLY for image file types (not PDFs, videos, or other types)
        // Dimensions are read from the ORIGINAL source file, not from thumbnails
        // Some file types (PDFs, videos, documents) do not have pixel dimensions
        $sourceImageWidth = null;
        $sourceImageHeight = null;
        
        if ($fileType === 'pdf') {
            // PDF validation: Check file size and basic PDF structure
            // Full PDF validation happens during thumbnail generation
            // PDFs do not have pixel dimensions - skip dimension capture
            Log::info('[ThumbnailGenerationService] Source PDF file downloaded and verified', [
                'asset_id' => $asset->id,
                'temp_path' => $tempPath,
                'source_file_size' => $sourceFileSize,
                'file_type' => 'pdf',
            ]);
        } elseif ($fileType === 'tiff') {
            // TIFF validation: Use Imagick to get dimensions
            // TIFF files require Imagick for processing
            if (!extension_loaded('imagick')) {
                throw new \RuntimeException('TIFF file processing requires Imagick PHP extension');
            }
            
            try {
                $imagick = new \Imagick();
                $imagick->readImage($tempPath);
                $imagick->setIteratorIndex(0);
                $imagick = $imagick->getImage();
                
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
        } elseif ($fileType === 'avif') {
            // AVIF validation: Use Imagick to get dimensions
            // AVIF files require Imagick for processing
            if (!extension_loaded('imagick')) {
                throw new \RuntimeException('AVIF file processing requires Imagick PHP extension');
            }
            
            try {
                $imagick = new \Imagick();
                $imagick->readImage($tempPath);
                $imagick->setIteratorIndex(0);
                $imagick = $imagick->getImage();
                
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
        } elseif ($fileType === 'svg') {
            // SVG validation: Basic XML structure check
            $content = file_get_contents($tempPath, false, null, 0, 500);
            if ($content === false || !preg_match('/<\s*(svg|\?xml)/i', $content)) {
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
        
        try {
            // Determine file type and generate thumbnails
            // File type was already detected above, reuse it
            // This ensures consistent file type detection throughout the method
            $thumbnails = [];
            
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
                            $outputBasePath
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
            
            // Generate final thumbnails (thumb, medium, large) - exclude preview
            foreach ($styles as $styleName => $styleConfig) {
                // Skip preview - already generated above
                if ($styleName === 'preview') {
                    continue;
                }
                try {
                    $thumbnailPath = $this->generateThumbnail(
                        $asset,
                        $tempPath,
                        $styleName,
                        $styleConfig,
                        $fileType,
                        false // Never force ImageMagick for normal generation
                    );
                    
                    if ($thumbnailPath) {
                        // Upload thumbnail to S3
                        $s3ThumbnailPath = $this->uploadThumbnailToS3(
                            $bucket,
                            $asset,
                            $thumbnailPath,
                            $styleName,
                            $outputBasePath
                        );
                        
                        // Get thumbnail metadata
                        $thumbnailInfo = $this->getThumbnailMetadata($thumbnailPath);
                        
                        $thumbnails[$styleName] = [
                            'path' => $s3ThumbnailPath,
                            'width' => $thumbnailInfo['width'] ?? null,
                            'height' => $thumbnailInfo['height'] ?? null,
                            'size_bytes' => $thumbnailInfo['size_bytes'] ?? filesize($thumbnailPath),
                            'generated_at' => now()->toIso8601String(),
                        ];
                        
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
            
            // Step 6: Merge preview thumbnails with final thumbnails
            // Preview thumbnails are stored separately in metadata but returned together
            $allThumbnails = array_merge($previewThumbnails, $thumbnails);
            
            // Persist thumbnail_dimensions for ALL styles (thumb, medium, large) - required for visualMetadataReady
            $metadata = $asset->metadata ?? [];
            $metadataUpdated = false;
            $thumbnailDimensions = $metadata['thumbnail_dimensions'] ?? [];
            foreach (['thumb', 'medium', 'large'] as $style) {
                if (isset($thumbnails[$style]['width'], $thumbnails[$style]['height'])) {
                    $thumbnailDimensions[$style] = [
                        'width' => $thumbnails[$style]['width'],
                        'height' => $thumbnails[$style]['height'],
                    ];
                    $metadataUpdated = true;
                }
            }
            if ($metadataUpdated) {
                $metadata['thumbnail_dimensions'] = $thumbnailDimensions;
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
            
            // Store source image dimensions in metadata ONLY for image file types
            // These dimensions are from the ORIGINAL source file (not thumbnails)
            // Only image file types have pixel dimensions - PDFs, videos, and other types do not
            if (($fileType === 'image' || $fileType === 'tiff' || $fileType === 'avif') && $sourceImageWidth && $sourceImageHeight) {
                $metadata['image_width'] = $sourceImageWidth;
                $metadata['image_height'] = $sourceImageHeight;
                $metadataUpdated = true;
                
                Log::info('[ThumbnailGenerationService] Stored original source image dimensions in metadata', [
                    'asset_id' => $asset->id,
                    'source_image_width' => $sourceImageWidth,
                    'source_image_height' => $sourceImageHeight,
                    'note' => 'Dimensions are from original source file, not thumbnails',
                ]);
            }
            
            if ($metadataUpdated) {
                $asset->update(['metadata' => $metadata]);
            }
            
            return $allThumbnails;
        } finally {
            // Clean up temporary file
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Download file from S3 to temporary location.
     *
     * @param StorageBucket $bucket
     * @param string $s3Key
     * @param int|string|null $assetId Optional asset ID for error context (appears in failed_jobs)
     * @return string Path to temporary file
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
            
            // Verify object exists and has content
            $body = $result['Body'];
            $bodyContents = (string) $body;
            $contentLength = strlen($bodyContents);
            
            if ($contentLength === 0) {
                throw new \RuntimeException("Downloaded file from S3 is empty (size: 0 bytes){$ctx}");
            }
            
            Log::info('[ThumbnailGenerationService] Source file downloaded from S3', [
                'asset_id' => $assetId,
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
                'content_length' => $contentLength,
            ]);
            
            $tempPath = tempnam(sys_get_temp_dir(), 'thumb_');
            file_put_contents($tempPath, $bodyContents);
            
            // Verify file was written correctly
            if (!file_exists($tempPath) || filesize($tempPath) !== $contentLength) {
                throw new \RuntimeException("Failed to write downloaded file to temp location{$ctx}");
            }
            
            Log::info('[ThumbnailGenerationService] Source file saved to temp location', [
                'asset_id' => $assetId,
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
     * Upload thumbnail to S3.
     *
     * @param StorageBucket $bucket
     * @param Asset $asset
     * @param string $localThumbnailPath
     * @param string $styleName
     * @return string S3 key path for the thumbnail
     * @throws \RuntimeException If upload fails
     */
    protected function uploadThumbnailToS3(
        StorageBucket $bucket,
        Asset $asset,
        string $localThumbnailPath,
        string $styleName,
        ?string $outputBasePath = null
    ): string {
        // Generate S3 path for thumbnail
        // Pattern: {asset_path_base}/thumbnails/{style}/{filename_with_ext}
        $basePath = $outputBasePath ?? pathinfo($asset->storage_root_path, PATHINFO_DIRNAME);
        $extension = pathinfo($localThumbnailPath, PATHINFO_EXTENSION) ?: 'jpg';
        $thumbnailFilename = "{$styleName}.{$extension}";
        $s3ThumbnailPath = "{$basePath}/thumbnails/{$styleName}/{$thumbnailFilename}";
        
        try {
            $this->s3Client->putObject([
                'Bucket' => $bucket->name,
                'Key' => $s3ThumbnailPath,
                'Body' => file_get_contents($localThumbnailPath),
                'ContentType' => $this->getMimeTypeForExtension($extension),
                'Metadata' => [
                    'original-asset-id' => $asset->id,
                    'style' => $styleName,
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

    /**
     * Generate a single thumbnail for the given style.
     *
     * @param Asset $asset
     * @param string $sourcePath Local path to source file
     * @param string $styleName Name of the style (thumb, medium, large)
     * @param array $styleConfig Style configuration from config
     * @param string $fileType Detected file type
     * @return string|null Path to generated thumbnail file, or null if generation not supported
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
        
        if (!$handler || !method_exists($this, $handler)) {
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
     * @param string $sourcePath
     * @param array $styleConfig
     * @return string Path to generated thumbnail (raster or SVG copy)
     * @throws \RuntimeException If generation fails
     */
    protected function generateSvgThumbnail(string $sourcePath, array $styleConfig): string
    {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source SVG file does not exist: {$sourcePath}");
        }
        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException("Source SVG file is empty (size: 0 bytes)");
        }
        // Basic SVG validation - must start with <svg or <?xml
        $content = file_get_contents($sourcePath, false, null, 0, 200);
        if ($content === false || !preg_match('/<\s*(svg|\?xml)/i', $content)) {
            throw new \RuntimeException("Source file does not appear to be a valid SVG (size: {$sourceFileSize} bytes)");
        }

        // Require Imagick for SVG — raw SVG passthrough produces wrong output (paths end in .svg,
        // getimagesize returns null, grid shows placeholder). Rasterization is mandatory.
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('SVG thumbnail generation requires Imagick extension');
        }

        return $this->generateSvgRasterizedThumbnail($sourcePath, $styleConfig);
    }

    /**
     * Render SVG to PNG via rsvg-convert (librsvg).
     *
     * @param string $sourcePath Path to SVG file
     * @param int $targetWidth Target pixel width (height auto from aspect ratio)
     * @return string Path to temporary PNG file
     */
    private function renderSvgViaRsvg(string $sourcePath, int $targetWidth): string
    {
        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $tempPngPath = $tmpDir . '/svg_' . uniqid() . '.png';

        $command = sprintf(
            'rsvg-convert -w %d %s -o %s',
            $targetWidth,
            escapeshellarg($sourcePath),
            escapeshellarg($tempPngPath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($tempPngPath)) {
            throw new \RuntimeException('rsvg-convert failed.');
        }

        return $tempPngPath;
    }

    /**
     * Rasterize SVG to raster thumbnail using rsvg-convert + Imagick.
     *
     * SVG → PNG via rsvg-convert, then PNG → WebP via Imagick.
     *
     * @param string $sourcePath
     * @param array $styleConfig
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

        try {
            $imagick = new \Imagick($pngPath);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality(92);
            $imagick->stripImage();

            if (!empty($styleConfig['blur'])) {
                $imagick->blurImage(0, 8);
            }

            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_svg_') . '.webp';
            $imagick->writeImage($thumbPath);

            $width  = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            $imagick->clear();
            $imagick->destroy();
        } finally {
            if (file_exists($pngPath)) {
                unlink($pngPath);
            }
        }

        if (!file_exists($thumbPath) || filesize($thumbPath) === 0) {
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
     * @param string $sourcePath
     * @param array $styleConfig
     * @return string Path to generated thumbnail
     * @throws \RuntimeException If generation fails
     */
    protected function generateImageThumbnail(string $sourcePath, array $styleConfig): string
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is required for image thumbnail generation');
        }
        
        // Verify source file exists and is readable
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source file does not exist: {$sourcePath}");
        }
        
        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException("Source file is empty (size: 0 bytes)");
        }
        
        // Load source image
        $sourceInfo = getimagesize($sourcePath);
        if ($sourceInfo === false) {
            throw new \RuntimeException("Unable to read source image: {$sourcePath} (size: {$sourceFileSize} bytes)");
        }
        
        [$sourceWidth, $sourceHeight, $sourceType] = $sourceInfo;
        
        Log::info('[ThumbnailGenerationService] Source image loaded', [
            'source_path' => $sourcePath,
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
            'source_type' => $sourceType,
            'source_file_size' => $sourceFileSize,
        ]);
        
        // Create source image resource
        $sourceImage = match ($sourceType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
            IMAGETYPE_GIF => imagecreatefromgif($sourcePath),
            default => throw new \RuntimeException("Unsupported image type: {$sourceType}"),
        };
        
        if ($sourceImage === false) {
            throw new \RuntimeException('Failed to create source image resource');
        }
        
        try {
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
            $preserveTransparency = !empty($styleConfig['preserve_transparency']);
            $supportsTransparency = in_array($sourceType, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
            if ($supportsTransparency) {
                // When preserve_transparency: skip gray fill so logo displays as-is on public pages
                if (!$preserveTransparency) {
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
            
            // Resize image
            imagecopyresampled(
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
            if (!empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                // Apply moderate blur (2 passes) to create LQIP effect while preserving image resemblance
                // Too much blur (3+ passes) makes previews look like garbage instead of blurred images
                for ($i = 0; $i < 2; $i++) {
                    imagefilter($thumbImage, IMG_FILTER_GAUSSIAN_BLUR);
                }
            }
            
            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $quality = $styleConfig['quality'] ?? 85;
            
            // Save thumbnail to temporary file
            if ($outputFormat === 'webp' && function_exists('imagewebp')) {
                $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_') . '.webp';
                if (!imagewebp($thumbImage, $thumbPath, $quality)) {
                    throw new \RuntimeException('Failed to save thumbnail image as WebP');
                }
            } else {
                // Fallback to JPEG if WebP not available or not configured
                $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_') . '.jpg';
                if (!imagejpeg($thumbImage, $thumbPath, $quality)) {
                    throw new \RuntimeException('Failed to save thumbnail image as JPEG');
                }
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
     * @param string $sourcePath Local path to TIFF file
     * @param array $styleConfig Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image (JPEG)
     * @throws \RuntimeException If TIFF processing fails
     */
    protected function generateTiffThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify Imagick extension is loaded
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('TIFF thumbnail generation requires Imagick PHP extension');
        }

        // Verify source file exists and is readable
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source TIFF file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException("Source TIFF file is empty (size: 0 bytes)");
        }

        Log::info('[ThumbnailGenerationService] Generating TIFF thumbnail using Imagick', [
            'source_path' => $sourcePath,
            'source_file_size' => $sourceFileSize,
            'style_config' => $styleConfig,
        ]);

        try {
            $imagick = new \Imagick();
            
            // Read the TIFF file
            $imagick->readImage($sourcePath);
            
            // Get first image if multi-page TIFF
            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();
            
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

            // Resize image using high-quality Lanczos filter
            $imagick->resizeImage($thumbWidth, $thumbHeight, \Imagick::FILTER_LANCZOS, 1, true);

            // Apply blur for preview thumbnails (LQIP effect)
            if (!empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2); // Moderate blur
            }

            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);

            // Create temporary output file
            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_tiff_') . '.' . $extension;

            // Write to file
            $imagick->writeImage($thumbPath);
            $imagick->clear();
            $imagick->destroy();

            // Verify output file was created
            if (!file_exists($thumbPath) || filesize($thumbPath) === 0) {
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
     * Generate thumbnail for AVIF files using Imagick.
     *
     * AVIF (AV1 Image File Format) files require Imagick as GD library does not support AVIF format.
     * This method uses Imagick to read and process AVIF images.
     *
     * @param string $sourcePath Local path to AVIF file
     * @param array $styleConfig Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image (JPEG or WebP)
     * @throws \RuntimeException If AVIF processing fails
     */
    protected function generateAvifThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify Imagick extension is loaded
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('AVIF thumbnail generation requires Imagick PHP extension');
        }

        // Verify source file exists and is readable
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source AVIF file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException("Source AVIF file is empty (size: 0 bytes)");
        }

        Log::info('[ThumbnailGenerationService] Generating AVIF thumbnail using Imagick', [
            'source_path' => $sourcePath,
            'source_file_size' => $sourceFileSize,
            'style_config' => $styleConfig,
        ]);

        try {
            $imagick = new \Imagick();
            
            // Read the AVIF file
            $imagick->readImage($sourcePath);
            
            // Get first image if multi-image AVIF
            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();
            
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

            // Resize image using high-quality Lanczos filter
            $imagick->resizeImage($thumbWidth, $thumbHeight, \Imagick::FILTER_LANCZOS, 1, true);

            // Apply blur for preview thumbnails (LQIP effect)
            if (!empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
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
            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_avif_') . '.' . $extension;

            // Write to file
            $imagick->writeImage($thumbPath);
            $imagick->clear();
            $imagick->destroy();

            // Verify output file was created
            if (!file_exists($thumbPath) || filesize($thumbPath) === 0) {
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
     * @param string $sourcePath Local path to PDF file
     * @param array $styleConfig Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image (PNG/JPEG)
     * @throws \RuntimeException If PDF processing fails or safety limits exceeded
     */
    protected function generatePdfThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify spatie/pdf-to-image package is available
        if (!class_exists(\Spatie\PdfToImage\Pdf::class)) {
            throw new \RuntimeException('PDF thumbnail generation requires spatie/pdf-to-image package');
        }
        
        // Verify Imagick extension is loaded (required by spatie/pdf-to-image)
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('PDF thumbnail generation requires Imagick PHP extension');
        }
        
        // Verify ImageMagick can process PDFs (quick check)
        try {
            $imagick = new \Imagick();
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
                "PDF file size ({$pdfSize} bytes) exceeds maximum allowed size ({$maxSize} bytes). " .
                "Large PDFs may cause memory exhaustion or processing timeouts."
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
            if (!is_readable($sourcePath)) {
                throw new \RuntimeException("PDF file is not readable: {$sourcePath}");
            }
            
            // Create PDF instance using spatie/pdf-to-image
            // This uses ImageMagick/Ghostscript under the hood
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
            $requestedImagePath = tempnam(sys_get_temp_dir(), 'pdf_thumb_') . '.png';
            
            // Ensure temp directory is writable
            $tempDir = dirname($requestedImagePath);
            if (!is_writable($tempDir)) {
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
                    throw new \RuntimeException('PDF save() returned unexpected type: ' . gettype($saveResult));
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
            if (!$actualImagePath || !file_exists($actualImagePath)) {
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

            // Resize the extracted image to match thumbnail style dimensions
            // Use GD library to resize (same as image thumbnails for consistency)
            if (!extension_loaded('gd')) {
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
                $preserveTransparency = !empty($styleConfig['preserve_transparency']);
                $needsDarkBackground = !$preserveTransparency
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

                // Resize image
                imagecopyresampled(
                    $thumbImage,
                    $sourceImage,
                    0, 0, 0, 0,
                    $thumbWidth,
                    $thumbHeight,
                    $sourceWidth,
                    $sourceHeight
                );

                // Apply blur for preview thumbnails (LQIP effect)
                if (!empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                    for ($i = 0; $i < 2; $i++) {
                        imagefilter($thumbImage, IMG_FILTER_GAUSSIAN_BLUR);
                    }
                }

                // Determine output format based on config (default to WebP for better compression)
                $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
                $quality = $styleConfig['quality'] ?? 85;

                // Save thumbnail to temporary file
                if ($outputFormat === 'webp' && function_exists('imagewebp')) {
                    $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_') . '.webp';
                    if (!imagewebp($thumbImage, $thumbPath, $quality)) {
                        throw new \RuntimeException('Failed to save PDF thumbnail image as WebP');
                    }
                } else {
                    // Fallback to JPEG if WebP not available or not configured
                    $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_') . '.jpg';
                    if (!imagejpeg($thumbImage, $thumbPath, $quality)) {
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
                // Clean up temporary extracted image
                if (file_exists($tempImagePath)) {
                    @unlink($tempImagePath);
                }
            }
        } catch (\Spatie\PdfToImage\Exceptions\PdfDoesNotExist $e) {
            // User-friendly error message
            throw new \RuntimeException("The PDF file could not be found or accessed.", 0, $e);
        } catch (\Spatie\PdfToImage\Exceptions\InvalidFormat $e) {
            // User-friendly error message
            throw new \RuntimeException("The PDF file appears to be corrupted or invalid.", 0, $e);
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
     * @param string $sourcePath Local path to PSD/PSB file
     * @param array $styleConfig Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image
     * @throws \RuntimeException If PSD processing fails
     */
    protected function generatePsdThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify Imagick extension is loaded
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('PSD thumbnail generation requires Imagick PHP extension');
        }

        Log::info('[ThumbnailGenerationService] Generating PSD thumbnail', [
            'source_path' => $sourcePath,
            'psd_size_bytes' => filesize($sourcePath),
            'style_config' => $styleConfig,
        ]);

        try {
            // Verify PSD file is readable
            if (!is_readable($sourcePath)) {
                throw new \RuntimeException("PSD file is not readable: {$sourcePath}");
            }

            // Create Imagick instance and read PSD file
            // ImageMagick automatically flattens PSD layers when reading
            $imagick = new \Imagick();
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

            // Resize image
            $imagick->resizeImage($thumbWidth, $thumbHeight, \Imagick::FILTER_LANCZOS, 1, true);

            // Apply blur for preview thumbnails if configured
            if (!empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2); // Moderate blur
            }

            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);

            // Create temporary output file
            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $outputPath = tempnam(sys_get_temp_dir(), 'psd_thumb_') . '.' . $extension;

            // Write to file
            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            // Verify output file was created
            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
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
     * @param string $sourcePath
     * @param array $styleConfig
     * @return string Path to generated thumbnail
     * @throws \RuntimeException If generation fails
     */
    protected function generateAiThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify Imagick extension is loaded
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('Illustrator thumbnail generation requires Imagick PHP extension');
        }

        Log::info('[ThumbnailGenerationService] Generating Illustrator thumbnail', [
            'source_path' => $sourcePath,
            'ai_size_bytes' => filesize($sourcePath),
            'style_config' => $styleConfig,
        ]);

        try {
            if (!is_readable($sourcePath)) {
                throw new \RuntimeException("Illustrator file is not readable: {$sourcePath}");
            }

            $imagick = new \Imagick();
            $imagick->setResolution(72, 72);

            try {
                // AI files are PDF-compatible; ImageMagick reads first page via Ghostscript
                $imagick->readImage($sourcePath . '[0]');
            } catch (\ImagickException $e) {
                Log::error('[ThumbnailGenerationService] Failed to read AI file with Imagick', [
                    'source_path' => $sourcePath,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException("Failed to read Illustrator file: {$e->getMessage()}", 0, $e);
            }

            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();

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
                    'This Illustrator file was saved without PDF compatibility. Re-save it in Adobe Illustrator with ' .
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

            $imagick->resizeImage($thumbWidth, $thumbHeight, \Imagick::FILTER_LANCZOS, 1, true);

            if (!empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2);
            }

            $outputFormat = config('assets.thumbnail.output_format', 'webp');
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);

            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $outputPath = tempnam(sys_get_temp_dir(), 'ai_thumb_') . '.' . $extension;

            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
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
     * @param string $sourcePath Path to source file
     * @param array $styleConfig Style configuration
     * @param Asset $asset Asset being processed (for logging)
     * @return string Path to generated thumbnail
     * @throws \RuntimeException If generation fails
     */
    protected function generateImageMagickThumbnail(string $sourcePath, array $styleConfig, Asset $asset): string
    {
        // Verify Imagick extension is loaded
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('ImageMagick thumbnail generation requires Imagick PHP extension');
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(72, 72); // Set resolution before reading
            
            // Try to read the file with ImageMagick (supports many formats)
            // For multi-page documents, only read first page
            $imagick->readImage($sourcePath . '[0]'); // [0] = first page/frame
            
            // Get first image from potential multi-page document
            $imagick->setIteratorIndex(0);
            $imagick = $imagick->getImage();
            
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
            
            // Resize image
            $imagick->resizeImage($thumbWidth, $thumbHeight, \Imagick::FILTER_LANCZOS, 1, true);
            
            // Apply blur for preview thumbnails if configured
            if (!empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                $imagick->blurImage(0, 2); // Moderate blur
            }
            
            // Determine output format based on config (default to WebP for better compression)
            $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
            $quality = $styleConfig['quality'] ?? 85;
            $imagick->setImageFormat($outputFormat);
            $imagick->setImageCompressionQuality($quality);
            
            // Create temporary output file
            $extension = $outputFormat === 'webp' ? 'webp' : 'jpg';
            $outputPath = tempnam(sys_get_temp_dir(), 'thumb_imagick_') . '.' . $extension;
            
            // Write to file
            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();
            
            // Verify output file was created
            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
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
     * @todo Implement Office document previews (LibreOffice)
     *   - Option 1: Use LibreOffice headless to convert to PDF first, then generate thumbnail
     *     (libreoffice --headless --convert-to pdf --outdir /tmp document.docx)
     *   - Option 2: Use library to extract embedded preview/thumbnail (e.g., PHPOffice)
     *   - Option 3: Generate icon based on file type (fallback)
     *   - Note: Office files may contain embedded previews that can be extracted directly
     *
     * NOTE: Requires external tools or libraries to extract preview/icon.
     * This is a placeholder implementation.
     *
     * @param string $sourcePath
     * @param array $styleConfig
     * @return string|null Path to generated thumbnail, or null if not supported
     */
    protected function generateOfficeThumbnail(string $sourcePath, array $styleConfig): ?string
    {
        Log::info('Office thumbnail generation not yet implemented', [
            'source_path' => $sourcePath,
        ]);
        
        return null;
    }

    /**
     * Generate thumbnail for video files (poster frame extraction).
     *
     * Uses FFmpeg to extract a poster frame from the video.
     * Frame timestamp: 25-40% of video duration (defaults to 30%).
     * Output format: JPG or WebP (based on config).
     *
     * @param string $sourcePath Local path to video file
     * @param array $styleConfig Thumbnail style configuration (width, height, quality, fit)
     * @return string Path to generated thumbnail image
     * @throws \RuntimeException If video processing fails
     */
    protected function generateVideoThumbnail(string $sourcePath, array $styleConfig): string
    {
        // Verify source file exists
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source video file does not exist: {$sourcePath}");
        }

        $sourceFileSize = filesize($sourcePath);
        if ($sourceFileSize === 0) {
            throw new \RuntimeException("Source video file is empty (size: 0 bytes)");
        }

        Log::info('[ThumbnailGenerationService] Generating video thumbnail using FFmpeg', [
            'source_path' => $sourcePath,
            'source_file_size' => $sourceFileSize,
            'style_config' => $styleConfig,
        ]);

        // Check if FFmpeg is available
        $ffmpegPath = $this->findFFmpegPath();
        if (!$ffmpegPath) {
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
            $tempImagePath = tempnam(sys_get_temp_dir(), 'video_thumb_') . '.jpg';
            
            // FFmpeg command to extract frame
            // -ss: seek to timestamp
            // -i: input file
            // -vframes 1: extract only 1 frame
            // -q:v 2: high quality JPEG (1-31, lower is better)
            // -y: overwrite output file
            $command = sprintf(
                '%s -ss %.2f -i %s -vframes 1 -q:v 2 -y %s 2>&1',
                escapeshellarg($ffmpegPath),
                $timestamp,
                escapeshellarg($sourcePath),
                escapeshellarg($tempImagePath)
            );

            Log::info('[ThumbnailGenerationService] Extracting video frame', [
                'source_path' => $sourcePath,
                'timestamp' => $timestamp,
                'command' => $command,
            ]);

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($tempImagePath) || filesize($tempImagePath) === 0) {
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

            // Resize the extracted frame to match thumbnail style dimensions
            // Use GD library to resize (same as image thumbnails for consistency)
            if (!extension_loaded('gd')) {
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

                // Resize image
                imagecopyresampled(
                    $thumbImage,
                    $sourceImage,
                    0, 0, 0, 0,
                    $thumbWidth,
                    $thumbHeight,
                    $sourceWidth,
                    $sourceHeight
                );

                // Apply blur for preview thumbnails (LQIP effect)
                if (!empty($styleConfig['blur']) && $styleConfig['blur'] === true) {
                    for ($i = 0; $i < 2; $i++) {
                        imagefilter($thumbImage, IMG_FILTER_GAUSSIAN_BLUR);
                    }
                }

                // Determine output format based on config (default to WebP for better compression)
                $outputFormat = config('assets.thumbnail.output_format', 'webp'); // 'webp' or 'jpeg'
                $quality = $styleConfig['quality'] ?? 85;

                // Save thumbnail to temporary file
                if ($outputFormat === 'webp' && function_exists('imagewebp')) {
                    $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_') . '.webp';
                    if (!imagewebp($thumbImage, $thumbPath, $quality)) {
                        throw new \RuntimeException('Failed to save video thumbnail image as WebP');
                    }
                } else {
                    // Fallback to JPEG if WebP not available or not configured
                    $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_') . '.jpg';
                    if (!imagejpeg($thumbImage, $thumbPath, $quality)) {
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
                // Clean up temporary extracted frame
                if (file_exists($tempImagePath)) {
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
                if ($returnCode === 0 && !empty($output[0]) && file_exists($output[0])) {
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
     * @param string $videoPath Path to video file
     * @param string $ffmpegPath Path to FFmpeg executable
     * @return array Video information (duration, width, height)
     * @throws \RuntimeException If FFprobe fails
     */
    protected function getVideoInfo(string $videoPath, string $ffmpegPath): array
    {
        // Try to use ffprobe first (more reliable)
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
        if (!file_exists($ffprobePath) || !is_executable($ffprobePath)) {
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

        if (!$videoData || !isset($videoData['format'])) {
            throw new \RuntimeException('Failed to parse video information from FFprobe output');
        }

        // Find video stream
        $videoStream = null;
        foreach ($videoData['streams'] ?? [] as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                $videoStream = $stream;
                break;
            }
        }

        if (!$videoStream) {
            throw new \RuntimeException('No video stream found in file');
        }

        // Extract duration, width, height
        $duration = (float) ($videoData['format']['duration'] ?? 0);
        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);

        return [
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Detect file type from mime type and filename.
     * When version is provided, uses version->mime_type (from FileInspectionService).
     *
     * @param Asset $asset
     * @param AssetVersion|null $version When provided, use version->mime_type for detection
     * @return string File type: image, pdf, tiff, psd, ai, office, video, or unknown
     */
    protected function detectFileType(Asset $asset, ?AssetVersion $version = null): string
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $mime = $version ? $version->mime_type : $asset->mime_type;
        // When version is provided, use extension from version->file_path (authoritative for replace).
        // asset.original_filename can be stale after replace (e.g. wrong ext from previous file).
        // Prefer version->file_path when available (authoritative for replace); fallback to original_filename, then storage_root_path
        $ext = $version && $version->file_path
            ? pathinfo($version->file_path, PATHINFO_EXTENSION)
            : pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION);
        if (empty($ext) && $asset->storage_root_path) {
            $ext = pathinfo($asset->storage_root_path, PATHINFO_EXTENSION);
        }
        // After replace: storage_root_path may be correct while original_filename is stale (e.g. still .jpg)
        if (!$version && $asset->storage_root_path) {
            $pathExt = strtolower(pathinfo($asset->storage_root_path, PATHINFO_EXTENSION));
            if ($pathExt === 'svg') {
                $ext = 'svg';
            }
        }
        $ext = $ext ? strtolower($ext) : '';
        $fileType = $fileTypeService->detectFileType($mime, $ext);

        // TIFF fallback: GD getimagesize() does not support TIFF. When MIME is wrong (e.g. from S3)
        // or application/octet-stream, we may get 'image' or 'unknown'. For .tif/.tiff extension,
        // route to tiff (Imagick) to avoid "Downloaded file is not a valid image" errors.
        $resolved = $fileType ?? 'unknown';
        if (in_array($ext, ['tif', 'tiff'], true) && in_array($resolved, ['image', 'unknown'], true)) {
            return 'tiff';
        }

        // SVG fallback: SVG is XML, not raster. When MIME is wrong or application/octet-stream,
        // we may get 'image' or 'unknown'. For .svg extension, route to svg to avoid getimagesize() failure.
        if (in_array($ext, ['svg'], true) && in_array($resolved, ['image', 'unknown'], true)) {
            return 'svg';
        }

        return $resolved;
    }

    /**
     * Calculate thumbnail dimensions maintaining aspect ratio.
     *
     * @param int $sourceWidth
     * @param int $sourceHeight
     * @param int $targetWidth
     * @param int $targetHeight
     * @param string $fit Strategy: 'contain' (fit within), 'cover' (fill), 'width', 'height'
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
                // Fill entire target area, may crop
                if ($sourceRatio > $targetRatio) {
                    // Source is wider, fit to height
                    return [$targetHeight * $sourceRatio, $targetHeight];
                } else {
                    // Source is taller, fit to width
                    return [$targetWidth, $targetWidth / $sourceRatio];
                }
            
            case 'width':
                // Fit to target width
                return [$targetWidth, (int) ($targetWidth / $sourceRatio)];
            
            case 'height':
                // Fit to target height
                return [(int) ($targetHeight * $sourceRatio), $targetHeight];
            
            case 'contain':
            default:
                // Fit within target dimensions (default)
                if ($sourceRatio > $targetRatio) {
                    // Source is wider, fit to width
                    return [$targetWidth, (int) ($targetWidth / $sourceRatio)];
                } else {
                    // Source is taller, fit to height
                    return [(int) ($targetHeight * $sourceRatio), $targetHeight];
                }
        }
    }

    /**
     * Get thumbnail metadata (width, height, size).
     *
     * @param string $thumbnailPath
     * @return array
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
     * @param string $extension
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
     * @param resource $imageResource GD image resource
     * @param int $width Image width
     * @param int $height Image height
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
     * Create S3 client instance.
     *
     * @return S3Client
     */
    protected function createS3Client(): S3Client
    {
        if (!class_exists(S3Client::class)) {
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
