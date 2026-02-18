<?php

namespace App\Http\Controllers;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Collection;
use Illuminate\Support\Facades\Auth;
use App\Models\Download;
use App\Services\FeatureGate;
use Aws\S3\S3Client;
use Illuminate\Support\Arr;
use Aws\S3\Exception\S3Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
    public function __construct(
        protected FeatureGate $featureGate
    ) {
        // Lazy-load S3 client only when needed
    }

    /**
     * Stream thumbnail for Admin Asset Operations (cross-tenant).
     *
     * GET /app/admin/assets/{asset}/thumbnail
     *
     * Uses the asset's storage bucket (not the default s3 disk) so thumbnails
     * load correctly in staging/production where per-tenant buckets are used.
     */
    public function adminThumbnail(string $asset): \Symfony\Component\HttpFoundation\Response
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        $isEngineering = in_array('site_engineering', $siteRoles);
        $canRegenerate = $user->can('assets.regenerate_thumbnails_admin');
        if (!$isSiteOwner && !$isSiteAdmin && !$isEngineering && !$canRegenerate) {
            abort(403, 'Admin access required');
        }

        $asset = Asset::withTrashed()->with('storageBucket')->findOrFail($asset);
        $this->validateStyle('medium');

        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            abort(404, 'Thumbnail not ready');
        }

        $thumbnailPath = $asset->thumbnailPathForStyle('medium');
        if (!$thumbnailPath) {
            abort(404, 'Thumbnail path not found');
        }

        try {
            return $this->streamThumbnailFromS3($asset, $thumbnailPath);
        } catch (\Throwable $e) {
            Log::warning('Admin asset thumbnail stream failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            abort(404, 'Thumbnail not available');
        }
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
     * Public background image for download landing page. No auth — so guests can load the image.
     *
     * GET /d/{download}/background
     *
     * Resolves the download's brand background asset (random one of background_asset_ids),
     * then streams that asset's medium thumbnail from S3. Used for public download/error pages.
     *
     * @param Request $request
     * @param Download $download
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function streamThumbnailForPublicDownload(Request $request, Download $download): \Symfony\Component\HttpFoundation\Response
    {
        $download->loadMissing('brand');
        $brand = $download->brand_id ? $download->brand : null;
        if (! $brand) {
            abort(404, 'Background not available.');
        }
        $brand->refresh();
        $brandSettings = $brand->download_landing_settings ?? [];
        $backgroundIds = $brandSettings['background_asset_ids'] ?? [];
        if (! is_array($backgroundIds) || empty($backgroundIds)) {
            abort(404, 'Background not available.');
        }
        $backgroundIds = array_values($backgroundIds);
        $chosenId = Arr::random($backgroundIds);
        $asset = Asset::where('brand_id', $brand->id)->where('id', $chosenId)->first();
        if (! $asset && count($backgroundIds) > 1) {
            foreach ($backgroundIds as $id) {
                if ((string) $id === (string) $chosenId) {
                    continue;
                }
                $asset = Asset::where('brand_id', $brand->id)->where('id', $id)->first();
                if ($asset) {
                    break;
                }
            }
        }
        if (! $asset || $asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            abort(404, 'Background not available.');
        }
        $this->validateStyle('medium');
        $thumbnailPath = $asset->thumbnailPathForStyle('medium');
        if (! $thumbnailPath) {
            abort(404, 'Background not available.');
        }
        try {
            return $this->streamThumbnailFromS3($asset, $thumbnailPath);
        } catch (\Throwable $e) {
            Log::warning('Public download background thumbnail stream failed', [
                'download_id' => $download->id,
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            abort(404, 'Background not available.');
        }
    }

    /**
     * Stream logo for public download page (no auth).
     *
     * GET /d/{download}/logo
     *
     * Resolves the download's brand logo_asset_id, then streams the asset's
     * medium thumbnail (transparent for logos).
     *
     * @param Request $request
     * @param Download $download
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function streamLogoForPublicDownload(Request $request, Download $download): \Symfony\Component\HttpFoundation\Response
    {
        $download->loadMissing('brand');
        $brand = $download->brand_id ? $download->brand : null;
        if (! $brand) {
            abort(404, 'Logo not available.');
        }
        $brand->refresh();
        $brandSettings = $brand->download_landing_settings ?? [];
        $logoAssetId = $brandSettings['logo_asset_id'] ?? null;
        if (! $logoAssetId) {
            abort(404, 'Logo not available.');
        }
        $asset = Asset::where('brand_id', $brand->id)->where('id', $logoAssetId)->first();
        if (! $asset || $asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            abort(404, 'Logo not available.');
        }
        $thumbnailPath = $asset->thumbnailPathForStyle('medium') ?: $asset->thumbnailPathForStyle('medium_display');
        if (! $thumbnailPath) {
            abort(404, 'Logo not available.');
        }
        try {
            return $this->streamThumbnailFromS3($asset, $thumbnailPath);
        } catch (\Throwable $e) {
            Log::warning('Public download logo thumbnail stream failed', [
                'download_id' => $download->id,
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            abort(404, 'Logo not available.');
        }
    }

    /**
     * Public logo for public collection page. No auth.
     *
     * GET /b/{brand_slug}/collections/{collection_slug}/logo
     *
     * Resolves logo_asset_id from download_landing_settings, or falls back to brand
     * identity (logo_id) when empty. Streams the asset's medium thumbnail.
     */
    public function streamLogoForPublicCollection(string $brand_slug, string $collection_slug): \Symfony\Component\HttpFoundation\Response
    {
        $collection = Collection::query()
            ->where('slug', $collection_slug)
            ->where('is_public', true)
            ->with(['brand', 'tenant'])
            ->whereHas('brand', fn ($q) => $q->where('slug', $brand_slug))
            ->first();

        if (! $collection) {
            abort(404, 'Logo not available.');
        }

        $tenant = $collection->tenant;
        if (! $tenant || ! $this->featureGate->publicCollectionsEnabled($tenant)) {
            abort(404, 'Logo not available.');
        }

        $brand = $collection->brand;
        if (! $brand) {
            abort(404, 'Logo not available.');
        }

        $brand->refresh();
        $settings = $brand->download_landing_settings ?? [];
        $logoAssetId = $settings['logo_asset_id'] ?? null;

        if (! $logoAssetId) {
            $logoAssetId = $brand->logo_id ?? null;
        }

        if (! $logoAssetId) {
            abort(404, 'Logo not available.');
        }

        $asset = Asset::where('brand_id', $brand->id)->where('id', $logoAssetId)->first();
        if (! $asset || $asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            abort(404, 'Logo not available.');
        }

        $thumbnailPath = $asset->thumbnailPathForStyle('medium') ?: $asset->thumbnailPathForStyle('medium_display');
        if (! $thumbnailPath) {
            abort(404, 'Logo not available.');
        }

        try {
            return $this->streamThumbnailFromS3($asset, $thumbnailPath);
        } catch (\Throwable $e) {
            Log::warning('Public collection logo stream failed', [
                'collection_id' => $collection->id,
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            abort(404, 'Logo not available.');
        }
    }

    /**
     * Public background image for public collection page. No auth.
     *
     * GET /b/{brand_slug}/collections/{collection_slug}/background
     *
     * Resolves background_asset_ids from download_landing_settings, picks one at random,
     * streams that asset's medium thumbnail. Randomized per visit.
     */
    public function streamBackgroundForPublicCollection(string $brand_slug, string $collection_slug): \Symfony\Component\HttpFoundation\Response
    {
        $collection = Collection::query()
            ->where('slug', $collection_slug)
            ->where('is_public', true)
            ->with(['brand', 'tenant'])
            ->whereHas('brand', fn ($q) => $q->where('slug', $brand_slug))
            ->first();

        if (! $collection) {
            abort(404, 'Background not available.');
        }

        $tenant = $collection->tenant;
        if (! $tenant || ! $this->featureGate->publicCollectionsEnabled($tenant)) {
            abort(404, 'Background not available.');
        }

        $brand = $collection->brand;
        if (! $brand) {
            abort(404, 'Background not available.');
        }

        $brand->refresh();
        $settings = $brand->download_landing_settings ?? [];
        $backgroundIds = $settings['background_asset_ids'] ?? [];
        if (! is_array($backgroundIds) || empty($backgroundIds)) {
            abort(404, 'Background not available.');
        }

        $backgroundIds = array_values($backgroundIds);
        $chosenId = Arr::random($backgroundIds);
        $asset = Asset::where('brand_id', $brand->id)->where('id', $chosenId)->first();
        if (! $asset && count($backgroundIds) > 1) {
            foreach ($backgroundIds as $id) {
                if ((string) $id === (string) $chosenId) {
                    continue;
                }
                $asset = Asset::where('brand_id', $brand->id)->where('id', $id)->first();
                if ($asset) {
                    break;
                }
            }
        }

        if (! $asset || $asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            abort(404, 'Background not available.');
        }

        $this->validateStyle('medium');
        $thumbnailPath = $asset->thumbnailPathForStyle('medium');
        if (! $thumbnailPath) {
            abort(404, 'Background not available.');
        }

        try {
            return $this->streamThumbnailFromS3($asset, $thumbnailPath);
        } catch (\Throwable $e) {
            Log::warning('Public collection background stream failed', [
                'collection_id' => $collection->id,
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            abort(404, 'Background not available.');
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
     * Retry thumbnail generation for an asset.
     *
     * POST /app/assets/{asset}/thumbnails/retry
     *
     * Allows users to manually retry thumbnail generation from the asset drawer UI.
     * This endpoint validates retry eligibility, enforces retry limits, and dispatches
     * the existing GenerateThumbnailsJob without modifying it.
     *
     * IMPORTANT: This feature respects the locked thumbnail pipeline:
     * - Does not modify existing GenerateThumbnailsJob
     * - Does not mutate Asset.status (status represents visibility only)
     * - Retry attempts are tracked for audit purposes
     *
     * @param Request $request
     * @param Asset $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function retry(Request $request, Asset $asset): \Illuminate\Http\JsonResponse
    {
        // Authorize: User must have permission to retry thumbnails
        $this->authorize('retryThumbnails', $asset);

        $user = $request->user();
        $retryService = app(\App\Services\ThumbnailRetryService::class);

        // Validate retry eligibility
        $canRetry = $retryService->canRetry($asset);
        if (!$canRetry['allowed']) {
            // Determine appropriate HTTP status code
            $statusCode = 422; // Unprocessable Entity (default)
            if (str_contains($canRetry['reason'] ?? '', 'Maximum retry attempts')) {
                $statusCode = 429; // Too Many Requests
            } elseif (str_contains($canRetry['reason'] ?? '', 'already in progress')) {
                $statusCode = 409; // Conflict
            } elseif (str_contains($canRetry['reason'] ?? '', 'missing')) {
                $statusCode = 422; // Unprocessable Entity
            }

            Log::warning('[AssetThumbnailController] Thumbnail retry not allowed', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'reason' => $canRetry['reason'] ?? 'unknown',
            ]);

            return response()->json([
                'error' => $canRetry['reason'] ?? 'Retry not allowed',
            ], $statusCode);
        }

        // Dispatch retry
        $result = $retryService->dispatchRetry($asset, $user->id);

        if (!$result['success']) {
            Log::error('[AssetThumbnailController] Failed to dispatch thumbnail retry', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $result['error'] ?? 'unknown',
            ]);

            return response()->json([
                'error' => $result['error'] ?? 'Failed to dispatch thumbnail retry',
            ], 500);
        }

        // Log activity event
        try {
            \App\Services\ActivityRecorder::logAsset(
                $asset,
                \App\Enums\EventType::ASSET_THUMBNAIL_RETRY_REQUESTED,
                [
                    'retry_count' => $asset->thumbnail_retry_count,
                    'previous_status' => $asset->thumbnail_status?->value ?? 'unknown',
                    'triggered_by_user_id' => $user->id,
                    'job_id' => $result['job_id'] ?? null,
                ]
            );
        } catch (\Exception $e) {
            // Activity logging must never break the request
            Log::error('Failed to log thumbnail retry event', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Refresh asset to get updated retry count
        $asset->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Thumbnail retry job dispatched',
            'retry_count' => $asset->thumbnail_retry_count,
            'job_id' => $result['job_id'] ?? null,
        ], 200);
    }

    /**
     * Generate thumbnail for an existing asset that doesn't have one yet.
     *
     * POST /app/assets/{asset}/thumbnails/generate
     *
     * Allows users to manually trigger thumbnail generation for existing assets
     * that were previously skipped (e.g., PDFs before PDF support was added).
     * This is a user-triggered regeneration only - does not modify the thumbnail pipeline.
     *
     * IMPORTANT: This feature respects the locked thumbnail pipeline:
     * - Does not modify existing GenerateThumbnailsJob
     * - Does not mutate Asset.status (status represents visibility only)
     * - Uses existing job and pipeline without changes
     * - Idempotent: safe to call if thumbnail already exists
     *
     * @param Request $request
     * @param Asset $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate(Request $request, Asset $asset): \Illuminate\Http\JsonResponse
    {
        // Authorize: User must have permission to generate thumbnails (same as retry)
        $this->authorize('retryThumbnails', $asset);

        $user = $request->user();

        // Safety check: If thumbnail already exists and is completed, return no-op
        // This prevents unnecessary job dispatch and respects idempotency
        if ($asset->thumbnail_status === \App\Enums\ThumbnailStatus::COMPLETED) {
            Log::info('[AssetThumbnailController] Thumbnail generation skipped - already completed', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Thumbnail already exists',
                'thumbnail_status' => 'completed',
            ], 200);
        }

        // Safety check: If thumbnail is currently processing, return conflict
        // This prevents duplicate job dispatch and respects existing job execution
        if ($asset->thumbnail_status === \App\Enums\ThumbnailStatus::PROCESSING) {
            Log::warning('[AssetThumbnailController] Thumbnail generation already in progress', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'error' => 'Thumbnail generation is already in progress',
            ], 409); // Conflict
        }

        // Note: PENDING status is allowed - we reset to PENDING to allow generation
        // The job itself has idempotency checks (checks if COMPLETED) so it's safe

        // Validate file type is supported for thumbnail generation
        // Must align with GenerateThumbnailsJob / ThumbnailGenerationService supported types
        $mimeType = strtolower($asset->mime_type ?? '');
        $extension = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));

        $isSupported = false;
        $supportReason = '';

        // Check PDF support
        if ($mimeType === 'application/pdf' || $extension === 'pdf') {
            if (class_exists(\Spatie\PdfToImage\Pdf::class)) {
                $isSupported = true;
                $supportReason = 'PDF (page 1)';
            } else {
                return response()->json([
                    'error' => 'PDF thumbnail generation requires spatie/pdf-to-image package',
                ], 422);
            }
        }

        // Check SVG support (rasterized via Imagick)
        if (!$isSupported && ($mimeType === 'image/svg+xml' || $extension === 'svg')) {
            $isSupported = true;
            $supportReason = 'SVG';
        }

        // Check TIFF/AVIF support (Imagick)
        if (!$isSupported && extension_loaded('imagick')) {
            if (($mimeType === 'image/tiff' || $mimeType === 'image/tif' || $extension === 'tiff' || $extension === 'tif') ||
                ($mimeType === 'image/avif' || $extension === 'avif')) {
                $isSupported = true;
                $supportReason = $mimeType === 'image/avif' || $extension === 'avif' ? 'AVIF' : 'TIFF';
            }
        }

        // Check image support (GD library)
        if (!$isSupported) {
            $supportedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $supportedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if ($mimeType && in_array($mimeType, $supportedMimeTypes)) {
                $isSupported = true;
                $supportReason = 'Image';
            } elseif ($extension && in_array($extension, $supportedExtensions)) {
                $isSupported = true;
                $supportReason = 'Image';
            }
        }

        if (!$isSupported) {
            Log::warning('[AssetThumbnailController] Thumbnail generation not supported for file type', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'mime_type' => $mimeType,
                'extension' => $extension,
            ]);

            return response()->json([
                'error' => 'Thumbnail generation is not supported for this file type',
            ], 422);
        }

        // Check if asset has required storage information
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            Log::warning('[AssetThumbnailController] Asset missing storage information', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'error' => 'Asset storage information is missing',
            ], 422);
        }

        // Reset thumbnail status to PENDING to allow generation
        // This is safe because we're explicitly triggering generation
        // IMPORTANT: We do NOT mutate Asset.status (status represents visibility only)
        $asset->update([
            'thumbnail_status' => \App\Enums\ThumbnailStatus::PENDING,
            'thumbnail_error' => null,
            'thumbnail_started_at' => null,
        ]);

        // Dispatch existing GenerateThumbnailsJob (unchanged, respects locked pipeline)
        // The job will handle all thumbnail generation logic
        // Note: Job ID is not available immediately after dispatch
        // The job ID will be available inside the job execution via $this->job->getJobId()
        \App\Jobs\GenerateThumbnailsJob::dispatch($asset->id);

        Log::info('[AssetThumbnailController] Thumbnail generation job dispatched (manual request)', [
            'asset_id' => $asset->id,
            'user_id' => $user->id,
            'file_type' => $supportReason,
        ]);

        // Log activity event for timeline
        try {
            \App\Services\ActivityRecorder::logAsset(
                $asset,
                \App\Enums\EventType::ASSET_THUMBNAIL_STARTED,
                [
                    'triggered_by' => 'user_manual_request',
                    'triggered_by_user_id' => $user->id,
                    'file_type' => $supportReason,
                ]
            );
        } catch (\Exception $e) {
            // Activity logging must never break the request
            Log::error('Failed to log thumbnail generation started event', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Thumbnail generation job dispatched',
            'file_type' => $supportReason,
        ], 200);
    }

    /**
     * Regenerate specific thumbnail styles for an asset (admin only).
     *
     * Site roles (site_owner, site_admin, site_support, site_engineering) can:
     * - Regenerate specific thumbnail styles
     * - Troubleshoot thumbnail issues
     * - Test new file types
     *
     * @param Request $request
     * @param Asset $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerateStyles(Request $request, Asset $asset): \Illuminate\Http\JsonResponse
    {
        // Authorize: User must have admin regeneration permission (site role only)
        $this->authorize('regenerateThumbnailsAdmin', $asset);

        $user = $request->user();
        $styleNames = $request->input('styles', []);

        // Validate style names
        $allStyles = config('assets.thumbnail_styles', []);
        $validStyles = [];
        
        foreach ($styleNames as $styleName) {
            if (isset($allStyles[$styleName])) {
                $validStyles[] = $styleName;
            } else {
                Log::warning('[AssetThumbnailController] Invalid style name requested', [
                    'asset_id' => $asset->id,
                    'user_id' => $user->id,
                    'invalid_style' => $styleName,
                ]);
            }
        }

        if (empty($validStyles)) {
            return response()->json([
                'error' => 'No valid thumbnail styles specified',
            ], 422);
        }

        // Check if asset has required storage information
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            Log::warning('[AssetThumbnailController] Asset missing storage information', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'error' => 'Asset storage information is missing',
            ], 422);
        }

        try {
            // Admin override: Check if user wants to force ImageMagick (bypass file type checks)
            $forceImageMagick = $request->input('force_imagick', false);
            
            // Regenerate specific styles
            $thumbnailService = app(\App\Services\ThumbnailGenerationService::class);
            $regenerated = $thumbnailService->regenerateThumbnailStyles($asset, $validStyles, $forceImageMagick);

            Log::info('[AssetThumbnailController] Thumbnail styles regenerated (admin)', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'styles' => $validStyles,
                'regenerated_styles' => array_keys($regenerated),
            ]);

            // Log activity event for timeline
            try {
                // Separate preview and final styles for proper tracking
                $previewStyles = [];
                $finalStyles = [];
                foreach (array_keys($regenerated) as $styleName) {
                    if ($styleName === 'preview') {
                        $previewStyles[] = $styleName;
                    } else {
                        $finalStyles[] = $styleName;
                    }
                }
                
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_COMPLETED,
                    [
                        'triggered_by' => 'admin_regeneration',
                        'triggered_by_user_id' => $user->id,
                        'styles' => $finalStyles, // Only final styles for timeline indicators
                        'preview_styles' => $previewStyles, // Preview styles separately
                        'thumbnail_count' => count($regenerated),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Failed to log thumbnail regeneration event', [
                    'asset_id' => $asset->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Thumbnail styles regenerated',
                'regenerated_styles' => array_keys($regenerated),
            ], 200);
        } catch (\Exception $e) {
            Log::error('[AssetThumbnailController] Thumbnail style regeneration failed', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'styles' => $validStyles,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to regenerate thumbnail styles: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove preview thumbnails for an asset.
     *
     * DELETE /app/assets/{asset}/thumbnails/preview
     *
     * Removes preview thumbnails from S3 and clears preview_thumbnail_url from metadata.
     * This forces the UI to show the file type icon instead of preview thumbnails.
     * Useful when preview thumbnails are bad/corrupted and need to be removed.
     *
     * @param Request $request
     * @param Asset $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function removePreview(Request $request, Asset $asset): \Illuminate\Http\JsonResponse
    {
        // Authorize: User must have permission to manage thumbnails
        $this->authorize('retryThumbnails', $asset);

        $user = $request->user();
        $s3Client = $this->getS3Client();

        try {
            $metadata = $asset->metadata ?? [];
            $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
            $finalThumbnails = $metadata['thumbnails'] ?? []; // Final thumbnails are stored in 'thumbnails' key
            
            // Check if there are any thumbnails to remove (preview or final)
            $hasPreviewThumbnails = !empty($previewThumbnails);
            $hasFinalThumbnails = !empty($finalThumbnails);
            $hasPreviewUrl = !empty($asset->preview_thumbnail_url);
            $hasFinalUrl = !empty($asset->final_thumbnail_url);
            
            if (!$hasPreviewThumbnails && !$hasFinalThumbnails && !$hasPreviewUrl && !$hasFinalUrl) {
                return response()->json([
                    'success' => true,
                    'message' => 'No thumbnails to remove',
                ], 200);
            }

            $bucket = $asset->storageBucket;
            if (!$bucket) {
                return response()->json([
                    'error' => 'Asset missing storage bucket',
                ], 422);
            }

            $deletedPaths = [];
            $errors = [];
            $deletedStyles = [];

            // Delete preview thumbnail files from S3
            foreach ($previewThumbnails as $styleName => $thumbnailData) {
                $thumbnailPath = $thumbnailData['path'] ?? null;
                if (!$thumbnailPath) {
                    continue;
                }

                try {
                    $s3Client->deleteObject([
                        'Bucket' => $bucket->name,
                        'Key' => $thumbnailPath,
                    ]);
                    $deletedPaths[] = $thumbnailPath;
                    $deletedStyles[] = "preview:{$styleName}";
                    
                    Log::info('[AssetThumbnailController] Preview thumbnail deleted from S3', [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                        's3_path' => $thumbnailPath,
                        'user_id' => $user->id,
                    ]);
                } catch (S3Exception $e) {
                    // Log error but continue with other thumbnails
                    $errors[] = "Failed to delete preview {$styleName}: {$e->getMessage()}";
                    Log::warning('[AssetThumbnailController] Failed to delete preview thumbnail from S3', [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                        's3_path' => $thumbnailPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Delete final thumbnail files from S3
            foreach ($finalThumbnails as $styleName => $thumbnailData) {
                $thumbnailPath = $thumbnailData['path'] ?? null;
                if (!$thumbnailPath) {
                    continue;
                }

                try {
                    $s3Client->deleteObject([
                        'Bucket' => $bucket->name,
                        'Key' => $thumbnailPath,
                    ]);
                    $deletedPaths[] = $thumbnailPath;
                    $deletedStyles[] = "final:{$styleName}";
                    
                    Log::info('[AssetThumbnailController] Final thumbnail deleted from S3', [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                        's3_path' => $thumbnailPath,
                        'user_id' => $user->id,
                    ]);
                } catch (S3Exception $e) {
                    // Log error but continue with other thumbnails
                    $errors[] = "Failed to delete final {$styleName}: {$e->getMessage()}";
                    Log::warning('[AssetThumbnailController] Failed to delete final thumbnail from S3', [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                        's3_path' => $thumbnailPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Remove thumbnails from metadata
            unset($metadata['preview_thumbnails']);
            unset($metadata['thumbnails']); // Remove final thumbnails
            unset($metadata['thumbnails_generated_at']); // Clear generation timestamp
            unset($metadata['thumbnails_generated']); // Clear generation flag
            
            // Also clear preview_thumbnail_url if it exists in metadata (some assets might store it there)
            if (isset($metadata['preview_thumbnail_url'])) {
                unset($metadata['preview_thumbnail_url']);
            }
            
            // Update asset metadata
            $asset->update(['metadata' => $metadata]);
            
            // Also clear thumbnail_status to allow regeneration
            $asset->update(['thumbnail_status' => \App\Enums\ThumbnailStatus::PENDING]);

            // Log activity event
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_REMOVED,
                    [
                        'removed_preview_styles' => array_keys($previewThumbnails),
                        'removed_final_styles' => array_keys($finalThumbnails),
                        'deleted_paths' => $deletedPaths,
                        'errors' => $errors,
                        'triggered_by_user_id' => $user->id,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break the request
                Log::error('Failed to log thumbnail removal event', [
                    'asset_id' => $asset->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $message = 'Thumbnails removed successfully';
            if (!empty($errors)) {
                $message .= ' (some files could not be deleted: ' . implode(', ', $errors) . ')';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'deleted_styles' => $deletedStyles,
                'deleted_paths' => $deletedPaths,
                'errors' => $errors,
            ], 200);

        } catch (\Exception $e) {
            Log::error('[AssetThumbnailController] Failed to remove preview thumbnails', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to remove preview thumbnails: ' . $e->getMessage(),
            ], 500);
        }
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

            // Validate thumbnail file size - only reject truly broken/corrupted files
            // Minimum size: 50 bytes - allows small valid thumbnails (e.g., compressed WebP)
            // Small thumbnails are acceptable, especially for small source images or highly compressed formats
            $contentLength = $result['ContentLength'] ?? 0;
            $minValidSize = 50; // Only catch broken/corrupted files
            
            if ($contentLength < $minValidSize) {
                Log::error('Thumbnail file too small (likely corrupted or empty)', [
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
                'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
            ];
            if (config('filesystems.disks.s3.endpoint')) {
                $config['endpoint'] = config('filesystems.disks.s3.endpoint');
                $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
            }

            $this->s3Client = new S3Client($config);
        }

        return $this->s3Client;
    }

    /**
     * Regenerate video thumbnail (poster frame) for a video asset.
     *
     * POST /app/assets/{asset}/thumbnails/regenerate-video-thumbnail
     *
     * Regenerates the video poster thumbnail using FFmpeg.
     * Only works for video assets.
     *
     * @param Request $request
     * @param Asset $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerateVideoThumbnail(Request $request, Asset $asset): \Illuminate\Http\JsonResponse
    {
        // Authorize: User must have permission to regenerate thumbnails
        $this->authorize('retryThumbnails', $asset);

        $user = $request->user();

        // Verify asset is a video
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);

        if ($fileType !== 'video') {
            return response()->json([
                'error' => 'This endpoint is only available for video assets',
            ], 422);
        }

        // Check if asset has required storage information
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            return response()->json([
                'error' => 'Asset storage information is missing',
            ], 422);
        }

        try {
            // Reset thumbnail status to PENDING to allow regeneration
            $asset->update([
                'thumbnail_status' => \App\Enums\ThumbnailStatus::PENDING,
                'thumbnail_error' => null,
                'thumbnail_started_at' => null,
            ]);

            // Dispatch GenerateThumbnailsJob which will handle video thumbnail generation
            \App\Jobs\GenerateThumbnailsJob::dispatch($asset->id);

            Log::info('[AssetThumbnailController] Video thumbnail regeneration job dispatched', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
            ]);

            // Log activity event
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_STARTED,
                    [
                        'triggered_by' => 'user_manual_video_thumbnail_regeneration',
                        'triggered_by_user_id' => $user->id,
                        'file_type' => 'video',
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Failed to log video thumbnail regeneration event', [
                    'asset_id' => $asset->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Video thumbnail regeneration job dispatched',
            ], 200);
        } catch (\Exception $e) {
            Log::error('[AssetThumbnailController] Video thumbnail regeneration failed', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to dispatch video thumbnail regeneration: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Regenerate video preview (hover preview video) for a video asset.
     *
     * POST /app/assets/{asset}/thumbnails/regenerate-video-preview
     *
     * Regenerates the hover preview video using FFmpeg.
     * Only works for video assets.
     *
     * @param Request $request
     * @param Asset $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerateVideoPreview(Request $request, Asset $asset): \Illuminate\Http\JsonResponse
    {
        // Authorize: User must have permission to regenerate thumbnails
        $this->authorize('retryThumbnails', $asset);

        $user = $request->user();

        // Verify asset is a video
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);

        if ($fileType !== 'video') {
            return response()->json([
                'error' => 'This endpoint is only available for video assets',
            ], 422);
        }

        // Check if asset has required storage information
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            return response()->json([
                'error' => 'Asset storage information is missing',
            ], 422);
        }

        // Check if video has a thumbnail/poster (required for preview generation)
        // Video preview generation requires the video file to exist, which should have a poster thumbnail
        if (!$asset->video_poster_url && !$asset->thumbnail_url && !$asset->final_thumbnail_url) {
            return response()->json([
                'error' => 'Cannot generate video preview: Video thumbnail does not exist. Please generate a thumbnail first.',
            ], 422);
        }

        try {
            // Check if video_preview_url column exists in the database
            // This handles cases where the migration hasn't been run yet
            $hasVideoPreviewColumn = \Illuminate\Support\Facades\Schema::hasColumn('assets', 'video_preview_url');
            
            if (!$hasVideoPreviewColumn) {
                Log::error('[AssetThumbnailController] Video preview column missing', [
                    'asset_id' => $asset->id,
                    'user_id' => $user->id,
                    'message' => 'video_preview_url column does not exist in assets table. Please run migrations.',
                ]);

                return response()->json([
                    'error' => 'Video preview feature is not available. The database migration for video preview support has not been run. Please contact your administrator.',
                ], 500);
            }

            // Clear existing preview URL to allow regeneration
            $asset->update([
                'video_preview_url' => null,
            ]);

            // Dispatch GenerateVideoPreviewJob
            \App\Jobs\GenerateVideoPreviewJob::dispatch($asset->id);

            Log::info('[AssetThumbnailController] Video preview regeneration job dispatched', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Video preview regeneration job dispatched',
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle database errors (like missing column)
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            // Check if error is about missing column
            if (str_contains($errorMessage, "Unknown column 'video_preview_url'")) {
                Log::error('[AssetThumbnailController] Video preview column missing (QueryException)', [
                    'asset_id' => $asset->id,
                    'user_id' => $user->id,
                    'error' => $errorMessage,
                ]);

                return response()->json([
                    'error' => 'Video preview feature is not available. The database migration for video preview support has not been run. Please contact your administrator.',
                ], 500);
            }

            // Other database errors
            Log::error('[AssetThumbnailController] Video preview regeneration failed (QueryException)', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $errorMessage,
                'code' => $errorCode,
            ]);

            return response()->json([
                'error' => 'Failed to dispatch video preview regeneration: Database error occurred. Please contact your administrator.',
            ], 500);
        } catch (\Exception $e) {
            Log::error('[AssetThumbnailController] Video preview regeneration failed', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to dispatch video preview regeneration: ' . $e->getMessage(),
            ], 500);
        }
    }
}
