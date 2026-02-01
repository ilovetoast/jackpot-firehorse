<?php

namespace App\Http\Controllers;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\DownloadStatus;
use App\Enums\DownloadType;
use App\Enums\ThumbnailStatus;
use App\Enums\ZipStatus;
use App\Jobs\BuildDownloadZipJob;
use App\Models\Asset;
use App\Models\Collection;
use App\Models\Download;
use App\Services\CollectionAssetQueryService;
use App\Services\DownloadExpirationPolicy;
use App\Services\FeatureGate;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        protected CollectionAssetQueryService $collectionAssetQueryService,
        protected FeatureGate $featureGate,
        protected PlanService $planService,
        protected DownloadExpirationPolicy $expirationPolicy
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

        $expiresAt = $this->expirationPolicy->calculateExpiresAt($tenant, DownloadType::SNAPSHOT);
        $requestExpiresAt = $request->input('expires_at');
        $maxDays = $this->planService->getMaxDownloadExpirationDays($tenant);
        if (is_string($requestExpiresAt) && $requestExpiresAt !== '') {
            try {
                $parsed = Carbon::parse($requestExpiresAt);
                if ($parsed->isFuture()) {
                    $daysFromNow = (int) now()->diffInDays($parsed, false);
                    if ($daysFromNow <= $maxDays) {
                        $expiresAt = $parsed;
                    }
                }
            } catch (\Throwable $e) {
                // Keep default expiresAt
            }
        }

        $hardDeleteAt = $expiresAt ? $this->expirationPolicy->calculateHardDeleteAt(
            (new Download)->setRelation('tenant', $tenant),
            $expiresAt
        ) : null;

        $title = $request->input('name');
        if (! is_string($title) || trim($title) === '') {
            $title = $collection->name . '-download-' . now()->format('Y-m-d');
        } else {
            $title = trim($title);
        }

        $slug = $this->uniqueSlugForTenant($tenant->id);

        DB::beginTransaction();
        try {
            $download = Download::create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'created_by_user_id' => null,
                'download_type' => DownloadType::SNAPSHOT,
                'source' => DownloadSource::PUBLIC_COLLECTION,
                'title' => $title,
                'slug' => $slug,
                'version' => 1,
                'status' => DownloadStatus::READY,
                'zip_status' => ZipStatus::NONE,
                'expires_at' => $expiresAt,
                'hard_delete_at' => $hardDeleteAt,
                'download_options' => [
                    'context' => 'public_collection',
                    'collection_id' => $collection->id,
                ],
                'access_mode' => DownloadAccessMode::PUBLIC,
                'allow_reshare' => true,
            ]);

            foreach ($visibleIds as $i => $assetId) {
                $download->assets()->attach($assetId, ['is_primary' => $i === 0]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[PublicCollectionController] Failed to create download', [
                'source' => 'public_collection',
                'collection_id' => $collection->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Failed to create download.'], 500);
            }
            return redirect()->back()->with('error', 'Failed to create download.');
        }

        Log::info('download.created', [
            'source' => 'public_collection',
            'collection_id' => $collection->id,
            'download_id' => $download->id,
            'tenant_id' => $tenant->id,
            'asset_count' => count($visibleIds),
        ]);

        BuildDownloadZipJob::dispatch($download->id);

        $publicUrl = route('downloads.public', ['download' => $download->id]);

        if ($request->expectsJson()) {
            return response()->json([
                'download_id' => $download->id,
                'public_url' => $publicUrl,
                'expires_at' => $expiresAt?->toIso8601String(),
                'asset_count' => count($visibleIds),
            ], 201);
        }

        return redirect($publicUrl);
    }

    private function uniqueSlugForTenant(int $tenantId): string
    {
        do {
            $slug = Str::lower(Str::random(12));
        } while (Download::where('tenant_id', $tenantId)->where('slug', $slug)->exists());

        return $slug;
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
