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
 * CRITICAL CACHE CORRECTNESS RULES:
 * =================================
 * 
 * 1. NO PLACEHOLDER IMAGES
 *    - Backend MUST NEVER return fake/placeholder images (1x1 pixel, solid colors, etc.)
 *    - Missing or invalid thumbnails MUST return 404
 *    - This prevents browser from caching placeholder images that can never be replaced
 * 
 * 2. PREVIEW vs FINAL SEPARATION
 *    - Preview thumbnails: /app/assets/{asset}/thumbnail/preview/{style}
 *    - Final thumbnails: /app/assets/{asset}/thumbnail/final/{style}
 *    - These URLs MUST NEVER be the same to prevent cache confusion
 * 
 * 3. VERSION-BASED CACHE BUSTING
 *    - Final thumbnails include thumbnail_version query param
 *    - Version changes ONLY when final thumbnail is ready (thumbnails_generated_at)
 *    - Browser will refetch final thumbnail when version changes
 *    - Preview thumbnails do NOT include version (they're temporary)
 * 
 * 4. WHY THIS MATTERS
 *    - Prevents cached placeholders: Browser never caches 404s
 *    - Prevents green tiles: No placeholder images means no cached placeholders
 *    - Enables safe preview→final swap: Different URLs ensure no cache collision
 * 
 * Authorization:
 * - Asset must belong to authenticated user's tenant
 * - Asset must belong to active brand (unless tenant owner/admin)
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
     * Stream preview thumbnail for an asset.
     *
     * GET /app/assets/{asset}/thumbnail/preview/{style}
     *
     * Preview thumbnails are temporary, low-quality thumbnails shown while processing.
     * They are NOT cached with version numbers and should be replaced by final thumbnails.
     *
     * CRITICAL: This endpoint MUST return 404 if thumbnail is not ready.
     * NO placeholder images are ever returned - browser must never cache fake images.
     *
     * @param Request $request
     * @param Asset $asset
     * @param string $style Thumbnail style (thumb, medium, large)
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function preview(Request $request, Asset $asset, string $style): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAsset($request, $asset);
        $this->validateStyle($style);

        // Step 6: Serve actual preview thumbnail files (LQIP)
        // Preview thumbnails are real derivative images, not placeholders
        // They are generated early in the pipeline and stored separately from final thumbnails
        
        // Check if preview thumbnail exists in metadata
        $metadata = $asset->metadata ?? [];
        $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
        
        // For preview endpoint, we only serve the 'preview' style (LQIP)
        // Other styles (thumb, medium, large) should use the final endpoint
        if ($style !== 'preview') {
            abort(404, 'Preview endpoint only serves preview style');
        }
        
        $previewData = $previewThumbnails['preview'] ?? null;
        if (!$previewData || !isset($previewData['path'])) {
            abort(404, 'Preview thumbnail not available');
        }
        
        $previewPath = $previewData['path'];
        $bucket = $asset->storageBucket;
        
        if (!$bucket) {
            abort(404, 'Storage bucket not found');
        }
        
            // Stream preview thumbnail from S3
            try {
                $s3Client = $this->getS3Client();
                $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $previewPath,
            ]);
            
            $content = $result['Body']->getContents();
            $contentType = $result['ContentType'] ?? 'image/jpeg';
            
            return response($content, 200)
                ->header('Content-Type', $contentType)
                ->header('Cache-Control', 'public, max-age=3600') // Cache preview for 1 hour
                ->header('X-Thumbnail-Type', 'preview'); // Header to identify preview vs final
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                abort(404, 'Preview thumbnail file not found');
            }
            
            Log::error('Failed to stream preview thumbnail from S3', [
                'asset_id' => $asset->id,
                'preview_path' => $previewPath,
                'bucket' => $bucket->name,
                'error' => $e->getMessage(),
            ]);
            
            abort(500, 'Failed to retrieve preview thumbnail');
        }
    }

    /**
     * Stream final thumbnail for an asset.
     *
     * GET /app/assets/{asset}/thumbnail/final/{style}?v={thumbnail_version}
     *
     * Final thumbnails are the completed, full-quality thumbnails.
     * They include a version query parameter (thumbnails_generated_at timestamp)
     * that changes ONLY when the final thumbnail is ready, ensuring browser refetches.
     *
     * CRITICAL: This endpoint MUST return 404 if thumbnail is not ready.
     * NO placeholder images are ever returned - browser must never cache fake images.
     *
     * @param Request $request
     * @param Asset $asset
     * @param string $style Thumbnail style (thumb, medium, large)
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function final(Request $request, Asset $asset, string $style): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAsset($request, $asset);
        $this->validateStyle($style);

        // CRITICAL: Final thumbnails are ONLY available when thumbnail_status === COMPLETED
        // All other states (pending, processing, failed, skipped) MUST return 404
        // This prevents browser from caching placeholder images that can never be replaced
        $thumbnailStatus = $asset->thumbnail_status;

        if ($thumbnailStatus !== ThumbnailStatus::COMPLETED) {
            abort(404, 'Final thumbnail not ready');
        }

        // Verify thumbnail file exists in metadata
        $thumbnailPath = $asset->thumbnailPathForStyle($style);
        
        if (!$thumbnailPath) {
            Log::warning('Final thumbnail path not found in asset metadata', [
                'asset_id' => $asset->id,
                'style' => $style,
                'thumbnail_status' => $thumbnailStatus?->value ?? 'null',
            ]);
            
            // Downgrade thumbnail_status to prevent false "completed" state
            if ($asset->thumbnail_status === ThumbnailStatus::COMPLETED) {
                $asset->thumbnail_status = ThumbnailStatus::PENDING;
                $asset->save();
            }
            
            abort(404, 'Final thumbnail not found');
        }

        // Stream thumbnail from S3
        try {
            $response = $this->streamThumbnailFromS3($asset, $thumbnailPath);
            
            // Add version-based cache headers for final thumbnails
            // Version is thumbnails_generated_at timestamp - changes only when final is ready
            $metadata = $asset->metadata ?? [];
            $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
            
            if ($thumbnailVersion) {
                // Add version to ETag to ensure cache invalidation when version changes
                $headers = $response->headers->all();
                if (isset($headers['etag'][0])) {
                    $headers['etag'][0] = $headers['etag'][0] . '-' . md5($thumbnailVersion);
                }
                $response->headers->set('X-Thumbnail-Version', $thumbnailVersion);
            }
            
            return $response;
        } catch (\RuntimeException $e) {
            // All errors result in 404 - NO placeholder images
            if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), '404')) {
                Log::warning('Final thumbnail not found in S3, returning 404', [
                    'asset_id' => $asset->id,
                    'style' => $style,
                    'thumbnail_path' => $thumbnailPath,
                    'error' => $e->getMessage(),
                ]);
                abort(404, 'Final thumbnail not found');
            }
            
            if (str_contains($e->getMessage(), 'too small') || str_contains($e->getMessage(), 'invalid')) {
                Log::warning('Final thumbnail file invalid, returning 404', [
                    'asset_id' => $asset->id,
                    'style' => $style,
                    'thumbnail_path' => $thumbnailPath,
                    'error' => $e->getMessage(),
                ]);
                abort(404, 'Final thumbnail file is invalid');
            }

            Log::error('Failed to stream final thumbnail from S3', [
                'asset_id' => $asset->id,
                'style' => $style,
                'thumbnail_path' => $thumbnailPath,
                'error' => $e->getMessage(),
            ]);

            // CRITICAL: Return 404, not placeholder - prevents cached fake images
            abort(404, 'Final thumbnail unavailable');
        } catch (\Exception $e) {
            Log::error('Unexpected error streaming final thumbnail', [
                'asset_id' => $asset->id,
                'style' => $style,
                'thumbnail_path' => $thumbnailPath,
                'error' => $e->getMessage(),
            ]);

            // CRITICAL: Return 404, not placeholder - prevents cached fake images
            abort(404, 'Final thumbnail unavailable');
        }
    }

    /**
     * Legacy endpoint for backward compatibility.
     *
     * GET /app/assets/{asset}/thumbnail/{style}
     *
     * DEPRECATED: Use /preview/{style} or /final/{style} instead.
     * This endpoint redirects to final if completed, otherwise returns 404.
     *
     * @param Request $request
     * @param Asset $asset
     * @param string $style Thumbnail style (thumb, medium, large)
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function show(Request $request, Asset $asset, string $style): \Symfony\Component\HttpFoundation\Response
    {
        // Legacy endpoint - delegate to final() method
        // This maintains backward compatibility while enforcing new rules
        return $this->final($request, $asset, $style);
    }

    /**
     * Authorize asset access.
     *
     * @param Request $request
     * @param Asset $asset
     * @return void
     */
    protected function authorizeAsset(Request $request, Asset $asset): void
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
    }

    /**
     * Validate thumbnail style.
     *
     * @param string $style
     * @return void
     */
    protected function validateStyle(string $style): void
    {
        $styles = config('assets.thumbnail_styles', []);
        if (!isset($styles[$style])) {
            abort(404, 'Invalid thumbnail style');
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
     * REMOVED: streamPlaceholder() method
     *
     * This method has been removed to enforce cache correctness.
     *
     * WHY REMOVED:
     * - Placeholder images (1x1 pixel, solid colors, etc.) get cached by browsers
     * - Once cached, browser never refetches, causing "green tiles" that never update
     * - Missing thumbnails MUST return 404 so browser doesn't cache fake images
     * - UI can safely handle 404s and show file icons instead
     *
     * REPLACEMENT:
     * - All endpoints now return 404 when thumbnail is not ready
     * - Preview endpoint: /app/assets/{asset}/thumbnail/preview/{style}
     * - Final endpoint: /app/assets/{asset}/thumbnail/final/{style}?v={version}
     * - These distinct URLs prevent cache confusion
     */

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
