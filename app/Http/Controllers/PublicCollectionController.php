<?php

namespace App\Http\Controllers;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Collection;
use App\Services\CollectionAssetQueryService;
use App\Services\FeatureGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public collections (C8). No auth. Only is_public = true, resolved by slug.
 * C10: Gated by tenant feature; when Public Collections disabled, return 404 (not unauthorized).
 */
class PublicCollectionController extends Controller
{
    public function __construct(
        protected CollectionAssetQueryService $collectionAssetQueryService,
        protected FeatureGate $featureGate
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

        return Inertia::render('Public/Collection', [
            'collection' => [
                'name' => $collection->name,
                'description' => $collection->description,
                'brand_name' => $brand->name,
                'brand_slug' => $brand->slug,
                'slug' => $collection->slug,
            ],
            'assets' => $assets,
        ]);
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

        try {
            Log::info('Public collection download', [
                'collection_id' => $collection->id,
                'asset_id' => $asset->id,
                'brand_slug' => $brand_slug,
            ]);

            $disk = Storage::disk('s3');
            $signedUrl = $disk->temporaryUrl($asset->storage_root_path, now()->addMinutes(15));

            return redirect($signedUrl);
        } catch (\Throwable $e) {
            report($e);
            abort(500, 'Failed to generate download link.');
        }
    }

    /**
     * Redirect to a signed thumbnail URL for an asset in a public collection. No auth.
     * Same validation as download; only serves thumbnails for assets visible in the collection.
     */
    public function thumbnail(string $brand_slug, string $collection_slug, Asset $asset): RedirectResponse
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

        $inCollection = $asset->collections()->where('collection_id', $collection->id)->exists();
        if (! $inCollection) {
            abort(404, 'Asset not found.');
        }

        $query = $this->collectionAssetQueryService->queryPublic($collection);
        $allowed = (clone $query)->where('assets.id', $asset->id)->exists();
        if (! $allowed) {
            abort(404, 'Asset not available.');
        }

        $asset->load('storageBucket');
        $path = null;
        if ($asset->thumbnail_status === ThumbnailStatus::COMPLETED) {
            $path = $asset->thumbnailPathForStyle('thumb');
        }
        if (! $path && $asset->metadata) {
            $preview = $asset->metadata['preview_thumbnails']['preview']['path'] ?? null;
            if ($preview) {
                $path = $preview;
            }
        }
        if (! $path) {
            abort(404, 'Thumbnail not available.');
        }

        try {
            $signedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15));
            return redirect($signedUrl);
        } catch (\Throwable $e) {
            report($e);
            abort(404, 'Thumbnail not available.');
        }
    }

    /**
     * Public-safe asset payload: thumbnail_url (route), download_url, no internal metadata.
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

        $thumbnailUrl = route('public.collections.assets.thumbnail', [
            'brand_slug' => $brand->slug,
            'collection_slug' => $collection->slug,
            'asset' => $asset->id,
        ]);

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
