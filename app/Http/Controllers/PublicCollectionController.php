<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Collection;
use App\Services\AssetDeliveryService;
use App\Services\CollectionAssetQueryService;
use App\Services\CollectionZipBuilderService;
use App\Services\DownloadNameResolver;
use App\Services\FeatureGate;
use App\Services\PlanService;
use App\Services\PublicCollectionPageBrandingResolver;
use App\Services\TenantBucketService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public collections (C8). No auth. Only is_public = true, resolved by slug.
 * C10: Gated by tenant feature; when Public Collections disabled, return 404 (not unauthorized).
 * D6: createDownload — create a collection-scoped download (ZIP) from public page.
 */
class PublicCollectionController extends Controller
{
    public function __construct(
        protected AssetDeliveryService $assetDelivery,
        protected CollectionAssetQueryService $collectionAssetQueryService,
        protected CollectionZipBuilderService $zipBuilder,
        protected FeatureGate $featureGate,
        protected PlanService $planService,
        protected DownloadNameResolver $downloadNameResolver,
        protected PublicCollectionPageBrandingResolver $brandingResolver
    ) {
    }

    /**
     * Show public collection page. Resolve by brand_slug + collection_slug; only is_public = true.
     * C10: Return 404 when tenant does not have Public Collections feature (not 401/403).
     */
    public function show(string $brand_slug, string $collection_slug): Response|RedirectResponse
    {
        $collection = Collection::query()
            ->where('slug', $collection_slug)
            ->where('is_public', true)
            ->with(['brand', 'tenant'])
            ->whereHas('brand', fn ($q) => $q->where('slug', $brand_slug))
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        $tenant = $collection->tenant;
        if (! $tenant || ! $this->featureGate->publicCollectionsEnabled($tenant)) {
            abort(404, 'Collection not found.');
        }

        $brand = $collection->brand;
        if (! $brand) {
            abort(404, 'Collection not found.');
        }

        $query = $this->collectionAssetQueryService->queryPublic($collection);
        $assetModels = $query->get();

        $assets = $assetModels->map(fn (Asset $asset) => $this->mapAssetToPublicGridArray($asset, $collection, $brand))
            ->values()
            ->all();

        $publicCollectionDownloadsEnabled = $this->featureGate->publicCollectionDownloadsEnabled($tenant);

        $brandingOptions = $this->brandingResolver->resolve($brand, $collection);

        return Inertia::render('Public/Collection', [
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
                'brand_name' => $brand->name,
                'brand_slug' => $brand->slug,
                'slug' => $collection->slug,
            ],
            'assets' => $assets,
            'public_collection_downloads_enabled' => $publicCollectionDownloadsEnabled,
            'branding_options' => $brandingOptions,
        ]);
    }

    /**
     * D6: Create a download (ZIP) for the public collection. No auth.
     * Validates collection is public and tenant has public_collection_downloads_enabled.
     * Resolves asset IDs server-side from collection. Redirects to public download status page.
     */
    public function createDownload(string $brand_slug, string $collection_slug, Request $request): RedirectResponse|JsonResponse
    {
        $collection = Collection::query()
            ->where('slug', $collection_slug)
            ->where('is_public', true)
            ->with(['brand', 'tenant'])
            ->whereHas('brand', fn ($q) => $q->where('slug', $brand_slug))
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        $tenant = $collection->tenant;
        if (! $tenant || ! $this->featureGate->publicCollectionDownloadsEnabled($tenant)) {
            abort(404, 'Collection not found.');
        }

        $brand = $collection->brand;
        if (! $brand) {
            abort(404, 'Collection not found.');
        }

        $query = $this->collectionAssetQueryService->queryPublic($collection);
        $assetModels = $query->get();
        $visibleIds = $assetModels->pluck('id')->all();

        if (empty($visibleIds)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'This collection has no assets to download.'], 422);
            }
            return redirect()->route('public.collections.show', [
                'brand_slug' => $brand_slug,
                'collection_slug' => $collection_slug,
            ])->with('error', 'This collection has no assets to download.');
        }

        $maxAssets = $this->planService->getMaxDownloadAssets($tenant);
        if (count($visibleIds) > $maxAssets) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "This plan allows up to {$maxAssets} assets per download.",
                ], 422);
            }
            return redirect()->back()->with('error', "This plan allows up to {$maxAssets} assets per download.");
        }

        $estimatedBytes = $assetModels->sum(fn (Asset $a) => (int) ($a->metadata['file_size'] ?? $a->metadata['size'] ?? 0));
        $maxZipBytes = $this->planService->getMaxDownloadZipBytes($tenant);
        if ($estimatedBytes > $maxZipBytes) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Estimated ZIP size exceeds plan limit.',
                ], 422);
            }
            return redirect()->back()->with('error', 'Estimated ZIP size exceeds plan limit.');
        }

        $zipUrl = URL::temporarySignedRoute(
            'public.collections.zip',
            now()->addMinutes(15),
            ['brand_slug' => $brand_slug, 'collection_slug' => $collection_slug]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'zip_url' => $zipUrl,
                'asset_count' => count($visibleIds),
            ], 200);
        }

        return redirect()->away($zipUrl);
    }

    /**
     * D6: Stream collection ZIP on-the-fly. Signed URL required; no Download record.
     * Builds ZIP from current collection assets and streams it; temp file deleted after send.
     */
    public function streamZip(string $brand_slug, string $collection_slug, Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $collection = Collection::query()
            ->where('slug', $collection_slug)
            ->where('is_public', true)
            ->with(['brand', 'tenant'])
            ->whereHas('brand', fn ($q) => $q->where('slug', $brand_slug))
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        $tenant = $collection->tenant;
        if (! $tenant || ! $this->featureGate->publicCollectionDownloadsEnabled($tenant)) {
            abort(404, 'Collection not found.');
        }

        $query = $this->collectionAssetQueryService->queryPublic($collection);
        $assets = $query->with('storageBucket')->get();

        if ($assets->isEmpty()) {
            abort(422, 'This collection has no assets to download.');
        }

        $bucketService = app(TenantBucketService::class);
        $bucket = $bucketService->resolveActiveBucketOrFail($tenant);

        $tempPath = null;
        try {
            $s3Client = $this->zipBuilder->createS3Client();
            $tempPath = $this->zipBuilder->buildZipFromAssets($assets, $bucket, $s3Client);
        } catch (\Throwable $e) {
            Log::error('[PublicCollectionController] Failed to build collection ZIP', [
                'collection_id' => $collection->id,
                'error' => $e->getMessage(),
            ]);
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
            abort(500, 'Failed to build download. Please try again.');
        }

        $baseName = $this->downloadNameResolver->sanitizeFilename($collection->name);
        $filename = preg_replace('/[\r\n"\\\\]/', '', $baseName . '-download-' . now()->format('Y-m-d') . '.zip') ?: 'collection-download.zip';

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Download a single asset from a public collection. No auth.
     * C10: Return 404 when tenant does not have Public Collections feature (not 401/403).
     */
    public function download(string $brand_slug, string $collection_slug, Asset $asset): RedirectResponse
    {
        $collection = Collection::query()
            ->where('slug', $collection_slug)
            ->where('is_public', true)
            ->with('tenant')
            ->whereHas('brand', fn ($q) => $q->where('slug', $brand_slug))
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        $tenant = $collection->tenant;
        if (! $tenant || ! $this->featureGate->publicCollectionsEnabled($tenant)) {
            abort(404, 'Collection not found.');
        }

        if ($asset->tenant_id !== $collection->tenant_id || $asset->brand_id !== $collection->brand_id) {
            abort(404, 'Asset not found.');
        }

        $inCollection = $asset->collections()
            ->where('collection_id', $collection->id)
            ->exists();
        if (! $inCollection) {
            abort(404, 'Asset not found.');
        }

        $query = $this->collectionAssetQueryService->queryPublic($collection);
        $allowed = (clone $query)->where('assets.id', $asset->id)->exists();
        if (! $allowed) {
            abort(404, 'Asset not available.');
        }

        if (! $asset->storage_root_path) {
            abort(404, 'File not available.');
        }

        Log::info('Public collection download', [
            'collection_id' => $collection->id,
            'asset_id' => $asset->id,
            'brand_slug' => $brand_slug,
        ]);

        $downloadUrl = $this->assetDelivery->url(
            $asset,
            AssetVariant::ORIGINAL->value,
            DeliveryContext::PUBLIC_COLLECTION->value
        );

        return redirect($downloadUrl);
    }

    /**
     * Public-safe asset payload: thumbnail_url (CDN), download_url, no internal metadata.
     */
    private function mapAssetToPublicGridArray(Asset $asset, Collection $collection, \App\Models\Brand $brand): array
    {
        $fileExtension = null;
        if ($asset->original_filename && $asset->original_filename !== 'unknown') {
            $ext = pathinfo($asset->original_filename, PATHINFO_EXTENSION);
            if ($ext && trim($ext) !== '') {
                $fileExtension = strtolower(trim($ext, '.'));
            }
        }
        if (empty($fileExtension) && $asset->mime_type) {
            $mimeToExt = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
                'application/pdf' => 'pdf', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
            ];
            $fileExtension = $mimeToExt[strtolower(trim($asset->mime_type))] ?? null;
        }

        $title = $asset->title;
        if (empty($title) || in_array($title, ['Unknown', 'Untitled Asset'], true)) {
            $title = $asset->original_filename ? (pathinfo($asset->original_filename, PATHINFO_FILENAME) ?? $asset->original_filename) : null;
        }

        $thumbnailUrl = $this->assetDelivery->url(
            $asset,
            AssetVariant::THUMB_LARGE->value,
            DeliveryContext::PUBLIC_COLLECTION->value
        );
        if ($thumbnailUrl === '') {
            $thumbnailUrl = $this->assetDelivery->url(
                $asset,
                AssetVariant::THUMB_MEDIUM->value,
                DeliveryContext::PUBLIC_COLLECTION->value
            );
        }
        if ($thumbnailUrl === '') {
            $thumbnailUrl = $this->assetDelivery->url(
                $asset,
                AssetVariant::THUMB_SMALL->value,
                DeliveryContext::PUBLIC_COLLECTION->value
            );
        }

        $downloadUrl = route('public.collections.assets.download', [
            'brand_slug' => $brand->slug,
            'collection_slug' => $collection->slug,
            'asset' => $asset->id,
        ]);

        return [
            'id' => $asset->id,
            'title' => $title,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'file_extension' => $fileExtension,
            'size_bytes' => $asset->size_bytes,
            'thumbnail_url' => $thumbnailUrl,
            'final_thumbnail_url' => $thumbnailUrl, // ThumbnailPreview uses this for initial display
            'download_url' => $downloadUrl,
            // thumbnail_status 'completed' so getThumbnailState returns AVAILABLE and card shows img
            'thumbnail_status' => 'completed',
            'is_published' => true, // Public collections only show published assets
            'archived_at' => null, // Public collections never show archived assets
        ];
    }
}
