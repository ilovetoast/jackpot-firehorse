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
 * - Images: jpg, png, webp, gif (direct thumbnail generation) ✓ IMPLEMENTED
 * - PDF: first page extraction (future: multi-page plan) - @todo Implement
 * - PSD/PSB: flattened preview (best-effort) - @todo Implement
 * - AI: best-effort preview - @todo Implement
 * - Office (Word/Excel/PowerPoint): icon or first-page render (best-effort) - @todo Implement
 * - Video: mp4, mov → first frame extraction - @todo Implement
 *
 * All thumbnails are stored in S3 alongside the original asset.
 * Thumbnail paths follow pattern: {asset_path_base}/thumbnails/{style}/{filename}
 *
 * @todo PSD / PSB thumbnail generation (Imagick)
 * @todo PDF first-page + multi-page previews
 * @todo Video poster frame generation (FFmpeg)
 * @todo Office document previews (LibreOffice)
 * @todo Manual thumbnail regeneration endpoint (future admin-only)
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

        // Download original file to temporary location
        $tempPath = $this->downloadFromS3($bucket, $asset->storage_root_path);
        
        try {
            // Determine file type and generate thumbnails
            $fileType = $this->detectFileType($asset);
            $thumbnails = [];
            
            foreach ($styles as $styleName => $styleConfig) {
                try {
                    $thumbnailPath = $this->generateThumbnail(
                        $asset,
                        $tempPath,
                        $styleName,
                        $styleConfig,
                        $fileType
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
            
            return $thumbnails;
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
            $result = $this->s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);
            
            $tempPath = tempnam(sys_get_temp_dir(), 'thumb_');
            file_put_contents($tempPath, $result['Body']);
            
            return $tempPath;
        } catch (S3Exception $e) {
            Log::error('Failed to download asset from S3 for thumbnail generation', [
                'bucket' => $bucket->name,
                'key' => $s3Key,
                'error' => $e->getMessage(),
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
        string $fileType
    ): ?string {
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
        
        // Load source image
        $sourceInfo = getimagesize($sourcePath);
        if ($sourceInfo === false) {
            throw new \RuntimeException('Unable to read source image');
        }
        
        [$sourceWidth, $sourceHeight, $sourceType] = $sourceInfo;
        
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
     * Generate thumbnail for PDF files (first page).
     *
     * @todo Implement PDF thumbnail generation
     *   - Option 1: Use Imagick (if available) with PDF support
     *   - Option 2: Use Ghostscript via shell command (gs -sDEVICE=png16m -dFirstPage=1 -dLastPage=1)
     *   - Option 3: Use third-party library (e.g., spatie/pdf)
     *   - Future: Multi-page PDF preview generation (generate thumbnails for all pages)
     *
     * NOTE: Requires Imagick with PDF support or external tool like Ghostscript.
     * This is a placeholder implementation that will need proper PDF processing.
     *
     * @param string $sourcePath
     * @param array $styleConfig
     * @return string|null Path to generated thumbnail, or null if not supported
     */
    protected function generatePdfThumbnail(string $sourcePath, array $styleConfig): ?string
    {
        Log::info('PDF thumbnail generation not yet implemented', [
            'source_path' => $sourcePath,
        ]);
        
        return null;
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
