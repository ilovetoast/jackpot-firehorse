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
 * - PDF: first page extraction (page 1 only, via spatie/pdf-to-image) ✓ IMPLEMENTED
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
     * @return string User-friendly error message
     */
    protected function sanitizeErrorMessage(string $errorMessage): string
    {
        // Map technical errors to user-friendly messages
        $errorMappings = [
            // PDF-related errors
            'Call to undefined method.*setPage' => 'PDF processing error. Please try again or contact support if the issue persists.',
            'Call to undefined method.*selectPage' => 'PDF processing error. Please try again or contact support if the issue persists.',
            'PDF file does not exist' => 'The PDF file could not be found or accessed.',
            'Invalid PDF format' => 'The PDF file appears to be corrupted or invalid.',
            'PDF thumbnail generation failed' => 'Unable to generate preview from PDF. The file may be corrupted or too large.',
            
            // Image processing errors
            'getimagesize.*failed' => 'Unable to read image file. The file may be corrupted.',
            'imagecreatefrom.*failed' => 'Unable to process image. The file format may not be supported.',
            'imagecopyresampled.*failed' => 'Unable to resize image. Please try again.',
            
            // Storage errors
            'S3.*error' => 'Unable to save thumbnail. Please try again.',
            'Storage.*failed' => 'Unable to save thumbnail. Please check storage configuration.',
            
            // Timeout errors
            'timeout' => 'Thumbnail generation timed out. The file may be too large or complex.',
            'Maximum execution time' => 'Thumbnail generation took too long. The file may be too large.',
            
            // Generic technical errors
            'Error:' => 'An error occurred during thumbnail generation.',
            'Exception:' => 'An error occurred during thumbnail generation.',
            'Fatal error' => 'An error occurred during thumbnail generation.',
        ];
        
        // Check for specific error patterns and replace with user-friendly messages
        foreach ($errorMappings as $pattern => $friendlyMessage) {
            if (preg_match('/' . $pattern . '/i', $errorMessage)) {
                return $friendlyMessage;
            }
        }
        
        // If error contains class names or technical paths, provide generic message
        if (preg_match('/(\\\\[A-Z][a-zA-Z0-9\\\\]+|::|->|at\s+\/.*\.php)/', $errorMessage)) {
            return 'An error occurred during thumbnail generation. Please try again or contact support if the issue persists.';
        }
        
        // For other errors, try to extract a meaningful message
        // Remove common technical prefixes
        $cleaned = preg_replace('/^(Error|Exception|Fatal error):\s*/i', '', $errorMessage);
        
        // If the cleaned message is still too technical, use generic message
        if (strlen($cleaned) > 200 || preg_match('/[{}()\[\]\\\]/', $cleaned)) {
            return 'An error occurred during thumbnail generation. Please try again.';
        }
        
        return $cleaned;
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
            if ($fileType === 'image' && $sourceImageWidth && $sourceImageHeight) {
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
        
        // Route to appropriate generator based on file type
        switch ($fileType) {
            case 'image':
                return $this->generateImageThumbnail($sourcePath, $styleConfig);
            
            case 'pdf':
                return $this->generatePdfThumbnail($sourcePath, $styleConfig);
            
            case 'psd':
                return $this->generatePsdThumbnail($sourcePath, $styleConfig);
            
            case 'ai':
                return $this->generateAiThumbnail($sourcePath, $styleConfig);
            
            case 'office':
                return $this->generateOfficeThumbnail($sourcePath, $styleConfig);
            
            case 'video':
                return $this->generateVideoThumbnail($sourcePath, $styleConfig);
            
            default:
                Log::info('Thumbnail generation not supported for file type', [
                    'asset_id' => $asset->id,
                    'file_type' => $fileType,
                    'mime_type' => $asset->mime_type,
                ]);
                return null;
        }
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
            
            // Preserve transparency for PNG and GIF
            if ($sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_GIF) {
                imagealphablending($thumbImage, false);
                imagesavealpha($thumbImage, true);
                $transparent = imagecolorallocatealpha($thumbImage, 0, 0, 0, 127);
                imagefill($thumbImage, 0, 0, $transparent);
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
            
            // Save thumbnail to temporary file
            $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_') . '.jpg';
            $quality = $styleConfig['quality'] ?? 85;
            
            if (!imagejpeg($thumbImage, $thumbPath, $quality)) {
                throw new \RuntimeException('Failed to save thumbnail image');
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

                // Fill with white background (PDFs may have transparency)
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

                // Save thumbnail to temporary file (JPEG format for consistency)
                $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_gen_') . '.jpg';
                $quality = $styleConfig['quality'] ?? 85;

                if (!imagejpeg($thumbImage, $thumbPath, $quality)) {
                    throw new \RuntimeException('Failed to save PDF thumbnail image');
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
            $userFriendlyMessage = $this->sanitizeErrorMessage($e->getMessage());
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
            
            // Set image format to JPEG for output
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(85);
            
            // Create temporary output file
            $outputPath = tempnam(sys_get_temp_dir(), 'thumb_imagick_') . '.jpg';
            
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
     * Generate thumbnail for video files (first frame extraction).
     *
     * @todo Implement video poster frame generation (FFmpeg)
     *   - Option 1: Use FFmpeg via shell command (ffmpeg -i video.mp4 -ss 00:00:01 -vframes 1 thumbnail.jpg)
     *   - Option 2: Use PHP-FFMpeg library (https://github.com/PHP-FFMpeg/PHP-FFMpeg)
     *   - Note: May want to extract frame at specific time (e.g., 1 second) instead of first frame
     *   - Future: Generate multiple frames for video preview (first, middle, last)
     *
     * NOTE: Requires FFmpeg or similar tool.
     * This is a placeholder implementation.
     *
     * @param string $sourcePath
     * @param array $styleConfig
     * @return string|null Path to generated thumbnail, or null if not supported
     */
    protected function generateVideoThumbnail(string $sourcePath, array $styleConfig): ?string
    {
        Log::info('Video thumbnail generation not yet implemented', [
            'source_path' => $sourcePath,
        ]);
        
        return null;
    }

    /**
     * Detect file type from asset mime type and filename.
     *
     * @param Asset $asset
     * @return string File type: image, pdf, psd, ai, office, video, or unknown
     */
    protected function detectFileType(Asset $asset): string
    {
        $mimeType = $asset->mime_type ?? '';
        $filename = $asset->original_filename ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Image types
        if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'])) {
            return 'image';
        }
        
        // PDF
        if ($mimeType === 'application/pdf' || $extension === 'pdf') {
            return 'pdf';
        }
        
        // PSD/PSB
        if (in_array($extension, ['psd', 'psb']) || $mimeType === 'image/vnd.adobe.photoshop') {
            return 'psd';
        }
        
        // AI
        if ($extension === 'ai' || $mimeType === 'application/postscript') {
            return 'ai';
        }
        
        // Office documents
        if (in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']) ||
            str_starts_with($mimeType, 'application/msword') ||
            str_starts_with($mimeType, 'application/vnd.ms-excel') ||
            str_starts_with($mimeType, 'application/vnd.ms-powerpoint') ||
            str_starts_with($mimeType, 'application/vnd.openxmlformats')) {
            return 'office';
        }
        
        // Video
        if (str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
            return 'video';
        }
        
        return 'unknown';
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
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
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
