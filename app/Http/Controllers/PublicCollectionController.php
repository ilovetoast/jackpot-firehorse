<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Collection;
use App\Services\CollectionAssetQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public collections (C8). No auth. Only is_public = true, resolved by slug.
 */
class PublicCollectionController extends Controller
{
    public function __construct(
        protected CollectionAssetQueryService $collectionAssetQueryService
    ) {
    }

    /**
     * Show public collection page. Resolve by slug; only is_public = true.
     */
    public function show(string $slug): Response|RedirectResponse
    {
        $collection = Collection::query()
            ->where('slug', $slug)
            ->where('is_public', true)
            ->with('brand')
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        $brand = $collection->brand;
        if (! $brand) {
            abort(404, 'Collection not found.');
        }

        $query = $this->collectionAssetQueryService->queryPublic($collection);
        $assetModels = $query->get();

        $assets = $assetModels->map(fn (Asset $asset) => $this->mapAssetToPublicGridArray($asset, $collection))
            ->values()
            ->all();

        return Inertia::render('Public/Collection', [
            'collection' => [
                'name' => $collection->name,
                'description' => $collection->description,
                'brand_name' => $brand->name,
                'slug' => $collection->slug,
            ],
            'assets' => $assets,
        ]);
    }

    /**
     * Download a single asset from a public collection. No auth.
     */
    public function download(string $slug, Asset $asset): RedirectResponse
    {
        $collection = Collection::query()
            ->where('slug', $slug)
            ->where('is_public', true)
            ->first();

        if (! $collection) {
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
            $disk = Storage::disk('s3');
            $signedUrl = $disk->temporaryUrl($asset->storage_root_path, now()->addMinutes(15));

            return redirect($signedUrl);
        } catch (\Throwable $e) {
            report($e);
            abort(500, 'Failed to generate download link.');
        }
    }

    /**
     * Public-safe asset payload: no internal metadata, download_url only.
     */
    private function mapAssetToPublicGridArray(Asset $asset, Collection $collection): array
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

        // No internal thumbnail URL on public page (app routes require auth)
        $thumbnailUrl = null;

        $downloadUrl = route('public.collections.assets.download', [
            'slug' => $collection->slug,
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
            'download_url' => $downloadUrl,
            // Optional fields for AssetCard compatibility (safe defaults)
            'thumbnail_status' => null,
            'is_published' => true, // Public collections only show published assets
            'archived_at' => null, // Public collections never show archived assets
        ];
    }
}
