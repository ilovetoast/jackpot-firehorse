<?php

namespace App\Services;

use App\Models\Asset;
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
        $tempPath = $this->downloadFromS3($bucket, $sourceS3Path);
        
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
                            $styleName
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
     * Generate all thumbnail styles for an asset atomically.
     *
     * Downloads the asset from S3, generates all configured thumbnail styles,
     * uploads thumbnails to S3, and returns metadata about generated thumbnails.
     *
     * @param Asset $asset The asset to generate thumbnails for
     * @return array Array of thumbnail metadata with keys: thumb, medium, large
     *               Each entry contains: path, width, height, size_bytes, generated_at
     * @throws \RuntimeException If thumbnail generation fails
     */
    public function generateThumbnails(Asset $asset): array
    {
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            throw new \RuntimeException('Asset missing storage path or bucket');
        }

        $bucket = $asset->storageBucket;
        $styles = config('assets.thumbnail_styles', []);
        
        if (empty($styles)) {
            throw new \RuntimeException('No thumbnail styles configured');
        }

        // CRITICAL: Use the correct source path for thumbnail generation
        // For newly uploaded assets, storage_root_path points to temp upload location
        // This is the SAME source used for metadata extraction
        // 
        // IMPORTANT: Preview thumbnails MUST be generated from the REAL uploaded file,
        // not from a placeholder or corrupted file. The source must be the actual
        // image file that was uploaded (stored at temp/uploads/{upload_session_id}/original).
        $sourceS3Path = $asset->storage_root_path;
        
        // If asset has upload_session_id, verify we're using the temp upload path
        // This ensures previews are generated from the same source as metadata extraction
        if ($asset->upload_session_id) {
            $expectedTempPath = "temp/uploads/{$asset->upload_session_id}/original";
            if ($sourceS3Path !== $expectedTempPath) {
                Log::warning('[ThumbnailGenerationService] Source path mismatch - using storage_root_path', [
                    'asset_id' => $asset->id,
                    'storage_root_path' => $sourceS3Path,
                    'expected_temp_path' => $expectedTempPath,
                    'upload_session_id' => $asset->upload_session_id,
                ]);
                // Continue with storage_root_path (may have been promoted already)
            } else {
                Log::info('[ThumbnailGenerationService] Using temp upload path for thumbnail generation', [
                    'asset_id' => $asset->id,
                    'source_s3_path' => $sourceS3Path,
                    'upload_session_id' => $asset->upload_session_id,
                ]);
            }
        }
        
        Log::info('[ThumbnailGenerationService] Generating thumbnails from source', [
            'asset_id' => $asset->id,
            'source_s3_path' => $sourceS3Path,
            'bucket' => $bucket->name,
        ]);

        // Download original file to temporary location
        // This is the SAME source file used for metadata extraction
        $tempPath = $this->downloadFromS3($bucket, $sourceS3Path);
        
        // Verify downloaded file is valid
        if (!file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Downloaded source file is invalid or empty');
        }
        
        $sourceFileSize = filesize($tempPath);
        
        // Detect file type to determine validation approach
        // IMPORTANT: PDF support is additive - image validation remains unchanged
        $fileType = $this->detectFileType($asset);
        
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
        } elseif ($fileType === 'image') {
            // Image validation: Verify file is actually an image (not corrupted or wrong format)
            // CRITICAL: Get dimensions from ORIGINAL source file using getimagesize()
            // These are the actual pixel dimensions of the original image, not thumbnails
            $imageInfo = @getimagesize($tempPath);
            if ($imageInfo === false) {
                throw new \RuntimeException("Downloaded file is not a valid image (size: {$sourceFileSize} bytes)");
            }
            
            // Capture source dimensions from original image file
            // These are the authoritative pixel dimensions for the source image (original size)
            // NOT thumbnail dimensions - these represent the actual uploaded file dimensions
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
                            'preview'
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
                            $styleName
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
            
            // Store source image dimensions in metadata ONLY for image file types
            // These dimensions are from the ORIGINAL source file (not thumbnails)
            // Only image file types have pixel dimensions - PDFs, videos, and other types do not
            if (($fileType === 'image' || $fileType === 'tiff' || $fileType === 'avif') && $sourceImageWidth && $sourceImageHeight) {
                $metadata = $asset->metadata ?? [];
                $metadata['image_width'] = $sourceImageWidth;
                $metadata['image_height'] = $sourceImageHeight;
                $asset->update(['metadata' => $metadata]);
                
                Log::info('[ThumbnailGenerationService] Stored original source image dimensions in metadata', [
                    'asset_id' => $asset->id,
                    'source_image_width' => $sourceImageWidth,
                    'source_image_height' => $sourceImageHeight,
                    'note' => 'Dimensions are from original source file, not thumbnails',
                ]);
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
     * @return string Path to temporary file
     * @throws \RuntimeException If download fails
     */
    protected function downloadFromS3(StorageBucket $bucket, string $s3Key): string
    {
        try {
            Log::info('[ThumbnailGenerationService] Downloading source file from S3', [
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
                throw new \RuntimeException("Downloaded file from S3 is empty (size: 0 bytes)");
            }
            
            Log::info('[ThumbnailGenerationService] Source file downloaded from S3', [
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
                'content_length' => $contentLength,
            ]);
            
            $tempPath = tempnam(sys_get_temp_dir(), 'thumb_');
            file_put_contents($tempPath, $bodyContents);
            
            // Verify file was written correctly
            if (!file_exists($tempPath) || filesize($tempPath) !== $contentLength) {
                throw new \RuntimeException("Failed to write downloaded file to temp location");
            }
            
            Log::info('[ThumbnailGenerationService] Source file saved to temp location', [
                'temp_path' => $tempPath,
                'file_size' => filesize($tempPath),
            ]);
            
            return $tempPath;
        } catch (S3Exception $e) {
            Log::error('[ThumbnailGenerationService] Failed to download asset from S3 for thumbnail generation', [
                'bucket' => $bucket->name,
                'key' => $s3Key,
                'error' => $e->getMessage(),
                'aws_error_code' => $e->getAwsErrorCode(),
            ]);
            throw new \RuntimeException("Failed to download asset from S3: {$e->getMessage()}", 0, $e);
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
        string $styleName
    ): string {
        // Generate S3 path for thumbnail
        // Pattern: {asset_path_base}/thumbnails/{style}/{filename_with_ext}
        $assetPathInfo = pathinfo($asset->storage_root_path);
        $extension = pathinfo($localThumbnailPath, PATHINFO_EXTENSION) ?: 'jpg';
        $thumbnailFilename = "{$styleName}.{$extension}";
        $s3ThumbnailPath = "{$assetPathInfo['dirname']}/thumbnails/{$styleName}/{$thumbnailFilename}";
        
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
        
        return $this->$handler($sourcePath, $styleConfig);
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
            
            // Detect if image has white content on transparent background
            $needsDarkBackground = false;
            if ($sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_GIF) {
                // Check if image has transparency and white content
                $needsDarkBackground = $this->hasWhiteContentOnTransparent($sourceImage, $sourceWidth, $sourceHeight);
                
                if ($needsDarkBackground) {
                    // Use darker gray background (gray-300: #D1D5DB / RGB: 209, 213, 219)
                    // This makes white content clearly visible on transparent backgrounds
                    // Gray-300 provides better contrast than gray-200 for white logos/text
                    $darkGray = imagecolorallocate($thumbImage, 209, 213, 219);
                    imagefill($thumbImage, 0, 0, $darkGray);
                } else {
                    // Preserve transparency for PNG and GIF
                    imagealphablending($thumbImage, false);
                    imagesavealpha($thumbImage, true);
                    $transparent = imagecolorallocatealpha($thumbImage, 0, 0, 0, 127);
                    imagefill($thumbImage, 0, 0, $transparent);
                }
            } else {
                // Fill with white background for opaque formats
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
                // Use the already-loaded sourceImage to detect white content
                $needsDarkBackground = $this->hasWhiteContentOnTransparent($sourceImage, $sourceWidth, $sourceHeight);
                
                // Use darker background if white content detected, otherwise white
                if ($needsDarkBackground) {
                    // Use darker gray background (gray-300: #D1D5DB / RGB: 209, 213, 219)
                    // This makes white content clearly visible on transparent backgrounds
                    // Gray-300 provides better contrast than gray-200 for white logos/text
                    $darkGray = imagecolorallocate($thumbImage, 209, 213, 219);
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
     * @todo Implement PSD / PSB thumbnail generation (Imagick)
     *   - Option 1: Use Imagick with PSD support (Imagick::readImage)
     *   - Option 2: Use external tool (e.g., ImageMagick convert command)
     *   - Note: PSD files are complex layered formats - need to flatten layers
     *   - Best-effort: Extract embedded preview if available
     *
     * NOTE: Requires Imagick with PSD support or external tool.
     * This is a placeholder implementation.
     *
     * @param string $sourcePath
     * @param array $styleConfig
     * @return string|null Path to generated thumbnail, or null if not supported
     */
    protected function generatePsdThumbnail(string $sourcePath, array $styleConfig): ?string
    {
        Log::info('PSD thumbnail generation not yet implemented', [
            'source_path' => $sourcePath,
        ]);
        
        return null;
    }

    /**
     * Generate thumbnail for AI files (best-effort preview).
     *
     * NOTE: Adobe Illustrator files are complex and may not be fully supported.
     * This is a placeholder implementation.
     *
     * @param string $sourcePath
     * @param array $styleConfig
     * @return string|null Path to generated thumbnail, or null if not supported
     */
    protected function generateAiThumbnail(string $sourcePath, array $styleConfig): ?string
    {
        // TODO: Implement AI thumbnail generation (best-effort)
        // AI files may contain embedded previews or require conversion
        
        Log::info('AI thumbnail generation not yet implemented', [
            'source_path' => $sourcePath,
        ]);
        
        return null;
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
     * Detect file type from asset mime type and filename.
     *
     * @param Asset $asset
     * @return string File type: image, pdf, psd, ai, office, video, or unknown
     */
    protected function detectFileType(Asset $asset): string
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        
        return $fileType ?? 'unknown';
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
        $info = getimagesize($thumbnailPath);
        
        return [
            'width' => $info[0] ?? null,
            'height' => $info[1] ?? null,
            'size_bytes' => filesize($thumbnailPath),
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
     * Detect if image has white content on transparent background.
     * 
     * Samples pixels from the image to determine if visible content is mostly white.
     * This helps identify logos/text that would be invisible on white/transparent backgrounds.
     * 
     * Uses a more aggressive detection strategy:
     * - Lower white threshold (RGB > 220 instead of 240) to catch more white content
     * - Lower ratio threshold (40% instead of 60%) to be more inclusive
     * - Also checks if average brightness is high (bright images likely need dark bg)
     *
     * @param resource $imageResource GD image resource
     * @param int $width Image width
     * @param int $height Image height
     * @return bool True if image appears to have white content on transparent background
     */
    protected function hasWhiteContentOnTransparent($imageResource, int $width, int $height): bool
    {
        // Sample more pixels for better detection (especially for small images)
        $sampleSize = min(50, max(10, min($width, $height) / 5)); // Sample 10-50 points
        $stepX = max(1, (int)($width / $sampleSize));
        $stepY = max(1, (int)($height / $sampleSize));
        
        $whitePixelCount = 0;
        $visiblePixelCount = 0;
        $totalBrightness = 0;
        $totalSampled = 0;
        
        // Sample pixels across the image
        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $totalSampled++;
                $color = imagecolorat($imageResource, $x, $y);
                
                // Extract RGBA components
                $alpha = ($color >> 24) & 0x7F; // Alpha channel (0 = opaque, 127 = transparent)
                $red = ($color >> 16) & 0xFF;
                $green = ($color >> 8) & 0xFF;
                $blue = $color & 0xFF;
                
                // Only consider visible pixels (not fully transparent)
                if ($alpha < 127) {
                    $visiblePixelCount++;
                    
                    // Calculate brightness (luminance formula)
                    $brightness = (0.299 * $red + 0.587 * $green + 0.114 * $blue);
                    $totalBrightness += $brightness;
                    
                    // Check if pixel is white or near-white
                    // More aggressive threshold: RGB values all > 200 (catches off-white, cream, light gray)
                    // Also check if any channel is very high (>230) - catches mostly-white pixels
                    // This catches white logos/text on transparent backgrounds more reliably
                    $isWhite = ($red > 200 && $green > 200 && $blue > 200) || 
                               ($red > 230 || $green > 230 || $blue > 230);
                    if ($isWhite) {
                        $whitePixelCount++;
                    }
                }
            }
        }
        
        // If we have visible pixels, check if most are white
        if ($visiblePixelCount === 0) {
            return false; // No visible content, can't determine
        }
        
        // Calculate average brightness
        $avgBrightness = $totalBrightness / $visiblePixelCount;
        
        // If more than 30% of visible pixels are white, likely white content on transparent
        // Very aggressive threshold (30% instead of 40%) to catch more cases
        $whiteRatio = $whitePixelCount / $visiblePixelCount;
        
        // Also check if average brightness is high (bright images likely need dark bg)
        // Average brightness > 180 indicates a bright image (lowered from 200)
        $isBrightImage = $avgBrightness > 180;
        
        // Very aggressive detection: white content OR bright image
        // If >30% white OR (>15% white AND bright), use dark background
        $isWhiteContent = $whiteRatio > 0.3 || ($whiteRatio > 0.15 && $isBrightImage);
        
        Log::debug('[ThumbnailGenerationService] White content detection', [
            'total_sampled' => $totalSampled,
            'visible_pixels' => $visiblePixelCount,
            'white_pixels' => $whitePixelCount,
            'white_ratio' => round($whiteRatio, 2),
            'avg_brightness' => round($avgBrightness, 2),
            'is_bright_image' => $isBrightImage,
            'needs_dark_background' => $isWhiteContent,
        ]);
        
        return $isWhiteContent;
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
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ];
        
        // Support MinIO for local development
        if (env('AWS_ENDPOINT')) {
            $config['endpoint'] = env('AWS_ENDPOINT');
            $config['use_path_style_endpoint'] = env('AWS_USE_PATH_STYLE_ENDPOINT', true);
        }
        
        return new S3Client($config);
    }
}
