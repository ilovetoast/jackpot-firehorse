<?php

namespace App\Http\Controllers;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Asset Thumbnail Controller
 *
 * Secure thumbnail delivery endpoint that streams thumbnails from S3 through the backend.
 * Does NOT expose S3 URLs publicly - all access is controlled via this endpoint.
 *
 * Endpoint: GET /app/assets/{asset}/thumbnail/{style}
 *
 * Authorization:
 * - Asset must belong to authenticated user's tenant
 * - Asset must belong to active brand (unless tenant owner/admin)
 *
 * Thumbnail Resolution:
 * - Validates style against config('assets.thumbnail_styles')
 * - Returns processing placeholder if thumbnail_status !== completed
 * - Returns failed placeholder if thumbnail_status === failed
 * - Streams thumbnail from S3 if completed
 *
 * Future work notes (see ThumbnailGenerationService for implementation details):
 * @todo PSD / PSB thumbnail generation (Imagick) - See ThumbnailGenerationService::generatePsdThumbnail()
 * @todo PDF first-page + multi-page previews - See ThumbnailGenerationService::generatePdfThumbnail()
 * @todo Video poster frame generation (FFmpeg) - See ThumbnailGenerationService::generateVideoThumbnail()
 * @todo Office document previews (LibreOffice) - See ThumbnailGenerationService::generateOfficeThumbnail()
 * @todo Manual thumbnail regeneration endpoint (future admin-only) - Create admin endpoint to retry failed thumbnails
 * @todo Asset versioning (future phase) - Handle thumbnail paths for asset versions
 * @todo Activity timeline integration - Log thumbnail generation/view events in activity timeline
 */
class AssetThumbnailController extends Controller
{
    /**
     * S3 client instance.
     */
    protected ?S3Client $s3Client = null;

    /**
     * Create a new AssetThumbnailController instance.
     */
    public function __construct()
    {
        // Lazy-load S3 client only when needed
    }

    /**
     * Stream thumbnail for an asset.
     *
     * GET /app/assets/{asset}/thumbnail/{style}
     *
     * @param Request $request
     * @param Asset $asset
     * @param string $style Thumbnail style (thumb, medium, large)
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function show(Request $request, Asset $asset, string $style): \Symfony\Component\HttpFoundation\Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        // Authorization: Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            abort(404, 'Asset not found');
        }

        // Authorization: Verify asset belongs to active brand (unless tenant owner/admin)
        if ($brand) {
            $tenantRole = $user?->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

            if (!$isTenantOwnerOrAdmin && $asset->brand_id !== $brand->id) {
                abort(403, 'Asset does not belong to active brand');
            }
        }

        // Validate style against configuration
        $styles = config('assets.thumbnail_styles', []);
        if (!isset($styles[$style])) {
            abort(404, 'Invalid thumbnail style');
        }

        // Handle thumbnail status: return appropriate placeholder or stream thumbnail
        $thumbnailStatus = $asset->thumbnail_status;

        // If thumbnails are still processing or pending, return processing placeholder
        if (!$thumbnailStatus || $thumbnailStatus === ThumbnailStatus::PENDING || $thumbnailStatus === ThumbnailStatus::PROCESSING) {
            return $this->streamPlaceholder('processing');
        }

        // If thumbnail generation failed, return failed placeholder
        if ($thumbnailStatus === ThumbnailStatus::FAILED) {
            return $this->streamPlaceholder('failed');
        }

        // If thumbnail generation is not completed, return processing placeholder
        if ($thumbnailStatus !== ThumbnailStatus::COMPLETED) {
            return $this->streamPlaceholder('processing');
        }

        // Phase 3.1E: Defensive guard - verify thumbnail file exists before treating as completed
        // If thumbnail_status === completed but file does not exist, downgrade to pending
        // This prevents false "completed" states that cause UI to skip processing/icon states
        // Ensures new uploads behave the same as existing assets
        $thumbnailPath = $asset->thumbnailPathForStyle($style);
        
        if (!$thumbnailPath) {
            Log::warning('Thumbnail path not found in asset metadata - downgrading status', [
                'asset_id' => $asset->id,
                'style' => $style,
                'thumbnail_status' => $thumbnailStatus?->value ?? 'null',
            ]);
            
            // Downgrade thumbnail_status to prevent false "completed" state
            // This ensures UI doesn't expect a thumbnail that doesn't exist
            if ($asset->thumbnail_status === ThumbnailStatus::COMPLETED) {
                $asset->thumbnail_status = ThumbnailStatus::PENDING;
                $asset->save();
            }
            
            return $this->streamPlaceholder('processing');
        }

        // Stream thumbnail from S3
        try {
            return $this->streamThumbnailFromS3($asset, $thumbnailPath);
        } catch (\RuntimeException $e) {
            // streamThumbnailFromS3 throws RuntimeException on errors
            // Check error message to determine if it's a 404 (not found), invalid size, or other error
            if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), '404')) {
                Log::warning('Thumbnail not found in S3, returning 404', [
                    'asset_id' => $asset->id,
                    'style' => $style,
                    'thumbnail_path' => $thumbnailPath,
                    'error' => $e->getMessage(),
                ]);
                // Phase 3.1E: Return 404 instead of placeholder to allow UI fallback
                abort(404, 'Thumbnail not found');
            }
            
            // Phase 3.1E: Handle invalid thumbnail size (1x1 pixel, too small, etc.)
            if (str_contains($e->getMessage(), 'too small') || str_contains($e->getMessage(), 'invalid')) {
                Log::warning('Thumbnail file invalid (too small or corrupted), returning 404', [
                    'asset_id' => $asset->id,
                    'style' => $style,
                    'thumbnail_path' => $thumbnailPath,
                    'error' => $e->getMessage(),
                ]);
                // Return 404 to allow UI fallback to file icon
                abort(404, 'Thumbnail file is invalid');
            }

            Log::error('Failed to stream thumbnail from S3', [
                'asset_id' => $asset->id,
                'style' => $style,
                'thumbnail_path' => $thumbnailPath,
                'error' => $e->getMessage(),
            ]);

            // Return failed placeholder on S3 errors
            return $this->streamPlaceholder('failed');
        } catch (\Exception $e) {
            Log::error('Unexpected error streaming thumbnail', [
                'asset_id' => $asset->id,
                'style' => $style,
                'thumbnail_path' => $thumbnailPath,
                'error' => $e->getMessage(),
            ]);

            // Return failed placeholder on unexpected errors
            return $this->streamPlaceholder('failed');
        }
    }

    /**
     * Stream thumbnail from S3.
     *
     * Downloads thumbnail from S3 and streams it through the response.
     * Does NOT load the entire file into memory.
     *
     * @param Asset $asset
     * @param string $thumbnailPath S3 key path to thumbnail
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \RuntimeException If streaming fails
     */
    protected function streamThumbnailFromS3(Asset $asset, string $thumbnailPath): \Symfony\Component\HttpFoundation\Response
    {
        if (!$asset->storageBucket) {
            throw new \RuntimeException('Asset missing storage bucket');
        }

        $bucket = $asset->storageBucket;
        $s3Client = $this->getS3Client();

        try {
            // Get object from S3 (streaming, not loading into memory)
            $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $thumbnailPath,
            ]);

            // Phase 3.1E: Validate thumbnail file size - reject 1x1 pixel placeholders
            // If thumbnail is < 1KB, it's likely a failed generation (1x1 pixel ~70 bytes)
            // DO NOT return invalid thumbnails - let UI fall back to file icon
            $contentLength = $result['ContentLength'] ?? 0;
            $minValidSize = 1024; // 1KB threshold
            
            if ($contentLength < $minValidSize) {
                Log::error('Thumbnail file too small (likely 1x1 pixel placeholder)', [
                    'asset_id' => $asset->id,
                    'thumbnail_path' => $thumbnailPath,
                    'bucket' => $bucket->name,
                    'content_length' => $contentLength,
                    'expected_min' => $minValidSize,
                ]);
                
                // Downgrade thumbnail_status to prevent false "completed" state
                // This ensures UI doesn't expect a thumbnail that doesn't exist
                $asset->thumbnail_status = \App\Enums\ThumbnailStatus::FAILED;
                $asset->save();
                
                throw new \RuntimeException('Thumbnail file is invalid (too small)');
            }

            // Get content type from S3 metadata or infer from file extension
            $contentType = $result['ContentType'] ?? $this->inferContentType($thumbnailPath);

            // Stream response (does not load entire file into memory)
            return response()->stream(function () use ($result) {
                // Stream the body directly to output
                $body = $result['Body'];
                while (!$body->eof()) {
                    echo $body->read(8192); // Read in 8KB chunks
                    flush();
                }
            }, 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'private, max-age=3600',
                'Content-Length' => $contentLength,
                'ETag' => $result['ETag'] ?? null,
            ]);
        } catch (S3Exception $e) {
            // Check if object doesn't exist (404)
            if ($e->getStatusCode() === 404) {
                Log::warning('Thumbnail not found in S3', [
                    'asset_id' => $asset->id,
                    'thumbnail_path' => $thumbnailPath,
                    'bucket' => $bucket->name,
                ]);
                
                // Phase 3.1E: Downgrade thumbnail_status if file doesn't exist
                // Prevents false "completed" state when file is missing
                if ($asset->thumbnail_status === \App\Enums\ThumbnailStatus::COMPLETED) {
                    $asset->thumbnail_status = \App\Enums\ThumbnailStatus::FAILED;
                    $asset->save();
                }
                
                throw new \RuntimeException('Thumbnail not found in storage');
            }

            Log::error('S3 error streaming thumbnail', [
                'asset_id' => $asset->id,
                'thumbnail_path' => $thumbnailPath,
                'bucket' => $bucket->name,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw new \RuntimeException("Failed to stream thumbnail from S3: {$e->getMessage()}", 0, $e);
        } catch (\RuntimeException $e) {
            // Re-throw RuntimeException (includes our size validation error)
            throw $e;
        }
    }

    /**
     * Stream placeholder image.
     *
     * Returns a placeholder image for processing or failed states.
     *
     * @param string $type Placeholder type: 'processing' or 'failed'
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function streamPlaceholder(string $type): \Symfony\Component\HttpFoundation\Response
    {
        $placeholderPath = resource_path("images/placeholders/thumbnail-{$type}.png");

        // If placeholder doesn't exist, return a simple 1x1 transparent PNG
        if (!file_exists($placeholderPath)) {
            return response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=300', // Shorter cache for placeholders
            ]);
        }

        // Stream placeholder file
        return response()->file($placeholderPath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300', // Shorter cache for placeholders
        ]);
    }

    /**
     * Infer content type from file path.
     *
     * @param string $path
     * @return string MIME type
     */
    protected function inferContentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/jpeg', // Default to JPEG
        };
    }

    /**
     * Get or create S3 client instance.
     *
     * @return S3Client
     */
    protected function getS3Client(): S3Client
    {
        if ($this->s3Client === null) {
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

            $this->s3Client = new S3Client($config);
        }

        return $this->s3Client;
    }
}
