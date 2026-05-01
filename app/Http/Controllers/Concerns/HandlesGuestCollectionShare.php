<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Asset;
use App\Models\Collection;
use App\Services\AssetSearchService;
use App\Services\AssetSortService;
use App\Services\AssetUrlService;
use App\Services\CollectionAssetQueryService;
use App\Services\CollectionPublicShareGuestAccess;
use App\Services\CollectionZipBuilderService;
use App\Services\DownloadNameResolver;
use App\Services\PlanService;
use App\Services\PublicCollectionPageBrandingResolver;
use App\Services\TenantBucketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guest-facing password-protected collection share (slug + token routes).
 *
 * @property AssetUrlService $assetUrlService
 * @property CollectionAssetQueryService $collectionAssetQueryService
 * @property CollectionZipBuilderService $zipBuilder
 * @property PlanService $planService
 * @property DownloadNameResolver $downloadNameResolver
 * @property PublicCollectionPageBrandingResolver $brandingResolver
 * @property CollectionPublicShareGuestAccess $shareGuestAccess
 * @property AssetSearchService $assetSearchService
 * @property AssetSortService $assetSortService
 */
trait HandlesGuestCollectionShare
{
    /** Max assets returned on one unlocked guest share page (filters apply before cap). */
    protected static int $guestPublicShareAssetLimit = 500;

    /**
     * Guest share list: search, MIME type group, sort (newest | name | type). Always scoped to {@see CollectionAssetQueryService::queryPublic}.
     */
    protected function guestPublicFilteredAssetQuery(Collection $collection, Request $request): Builder
    {
        $query = $this->collectionAssetQueryService->queryPublic($collection);

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $this->assetSearchService->applyScopedSearch($query, $q);
        }

        $type = strtolower((string) $request->query('type', 'all'));
        match ($type) {
            'images' => $query->where('mime_type', 'like', 'image/%'),
            'videos' => $query->where('mime_type', 'like', 'video/%'),
            'documents' => $query->where(function ($q) {
                $q->where('mime_type', 'like', 'application/pdf%')
                    ->orWhere('mime_type', 'like', 'application/msword%')
                    ->orWhere('mime_type', 'like', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document%')
                    ->orWhere('mime_type', 'like', 'application/vnd.ms-excel%')
                    ->orWhere('mime_type', 'like', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet%')
                    ->orWhere('mime_type', 'like', 'application/vnd.ms-powerpoint%')
                    ->orWhere('mime_type', 'like', 'application/vnd.openxmlformats-officedocument.presentationml.presentation%')
                    ->orWhere('mime_type', 'like', 'text/%');
            }),
            'other' => $query->where(function ($q) {
                $q->whereNull('mime_type')
                    ->orWhere(function ($inner) {
                        $inner->where('mime_type', 'not like', 'image/%')
                            ->where('mime_type', 'not like', 'video/%')
                            ->where('mime_type', 'not like', 'application/pdf%')
                            ->where('mime_type', 'not like', 'application/msword%')
                            ->where('mime_type', 'not like', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document%')
                            ->where('mime_type', 'not like', 'application/vnd.ms-excel%')
                            ->where('mime_type', 'not like', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet%')
                            ->where('mime_type', 'not like', 'application/vnd.ms-powerpoint%')
                            ->where('mime_type', 'not like', 'application/vnd.openxmlformats-officedocument.presentationml.presentation%')
                            ->where('mime_type', 'not like', 'text/%');
                    });
            }),
            default => null,
        };

        $sort = strtolower((string) $request->query('sort', 'newest'));
        if ($sort === 'name') {
            $this->assetSortService->applySort($query, AssetSortService::SORT_ALPHABETICAL, AssetSortService::DIRECTION_ASC);
        } elseif ($sort === 'type') {
            $query->orderBy('mime_type', 'asc')->orderBy('title', 'asc')->orderBy('assets.id', 'asc');
        } else {
            $this->assetSortService->applySort($query, AssetSortService::SORT_CREATED, AssetSortService::DIRECTION_DESC);
        }

        return $query;
    }

    protected function resolveCollectionByPublicSlug(string $brand_slug, string $collection_slug): ?Collection
    {
        return Collection::query()
            ->where('slug', $collection_slug)
            ->where('is_public', true)
            ->with(['brand', 'tenant'])
            ->whereHas('brand', fn ($q) => $q->where('slug', $brand_slug))
            ->first();
    }

    /**
     * @param  array{kind: 'slug'|'token', brand_slug?: string, collection_slug?: string, token?: string}  $routeContext
     */
    protected function respondGuestShareCollectionPage(Collection $collection, Request $request, array $routeContext): Response|RedirectResponse
    {
        $tenant = $collection->tenant;
        if (! $tenant || ! $this->shareGuestAccess->tenantAllowsShareLinks($tenant)) {
            abort(404, 'Collection not found.');
        }

        $brand = $collection->brand;
        if (! $brand) {
            abort(404, 'Collection not found.');
        }

        if (! $collection->is_public) {
            abort(404, 'Collection not found.');
        }

        if (! $collection->hasPublicPassword()) {
            abort(404, 'Collection not found.');
        }

        if ($this->shareGuestAccess->shouldShowPasswordGate($collection)) {
            $unlockUrl = $routeContext['kind'] === 'token'
                ? route('share.collections.unlock', ['token' => $routeContext['token']], false)
                : route('public.collections.unlock', [
                    'brand_slug' => $routeContext['brand_slug'],
                    'collection_slug' => $routeContext['collection_slug'],
                ], false);

            $brandingOptions = $this->brandingResolver->resolve($brand, $collection);

            return Inertia::render('Public/ShareCollectionGate', [
                'collection_title' => $collection->name,
                'brand_name' => $brand->name,
                'branding_options' => $brandingOptions,
                'unlock_url' => $unlockUrl,
                'cdn_domain' => config('cloudfront.domain'),
            ]);
        }

        return $this->renderUnlockedGuestShareInertia($collection, $routeContext, $request);
    }

    /**
     * @param  array{kind: 'slug'|'token', brand_slug?: string, collection_slug?: string, token?: string}  $routeContext
     */
    protected function renderUnlockedGuestShareInertia(Collection $collection, array $routeContext, ?Request $request = null): Response
    {
        $tenant = $collection->tenant;
        $brand = $collection->brand;
        if (! $tenant || ! $brand) {
            abort(404, 'Collection not found.');
        }

        if (! $this->shareGuestAccess->guestMayViewUnlockedContent($collection)) {
            abort(404, 'Collection not found.');
        }

        $request = $request ?? request();

        $collectionAssetTotal = $this->collectionAssetQueryService->queryPublic($collection)->count();

        $filteredQuery = $this->guestPublicFilteredAssetQuery($collection, $request);
        $guestFilteredTotal = (clone $filteredQuery)->count();

        $limit = self::$guestPublicShareAssetLimit;
        $assetModels = (clone $filteredQuery)->limit($limit)->get();

        $shareToken = $collection->public_share_token;

        $publicCollectionDownloadsEnabled = $this->shareGuestAccess->tenantAllowsPublicCollectionDownloads($tenant)
            && $collection->public_downloads_enabled;

        $assets = $assetModels
            ->map(fn (Asset $asset) => $this->mapAssetToPublicGridArray($asset, $collection, $shareToken, $publicCollectionDownloadsEnabled))
            ->values()
            ->all();

        $brandingOptions = $this->brandingResolver->resolve($brand, $collection);

        $downloadPostPath = $routeContext['kind'] === 'token' && $shareToken
            ? route('share.collections.download', ['token' => $shareToken], false)
            : route('public.collections.download', [
                'brand_slug' => $brand->slug,
                'collection_slug' => $collection->slug,
            ], false);

        $guestCollectionPath = $routeContext['kind'] === 'token' && $shareToken
            ? '/share/collections/'.$shareToken
            : '/b/'.$brand->slug.'/collections/'.$collection->slug;

        $typeRaw = strtolower((string) $request->query('type', 'all'));
        $allowedTypes = ['all', 'images', 'videos', 'documents', 'other'];
        $type = in_array($typeRaw, $allowedTypes, true) ? $typeRaw : 'all';

        $sortRaw = strtolower((string) $request->query('sort', 'newest'));
        $sort = in_array($sortRaw, ['newest', 'name', 'type'], true) ? $sortRaw : 'newest';

        $viewRaw = strtolower((string) $request->query('view', 'grid'));
        $view = $viewRaw === 'list' ? 'list' : 'grid';

        return Inertia::render('Public/Collection', [
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
                'brand_name' => $brand->name,
                'brand_slug' => $brand->slug,
                'slug' => $collection->slug,
                'public_share_token' => $shareToken,
            ],
            'assets' => $assets,
            'public_collection_downloads_enabled' => $publicCollectionDownloadsEnabled,
            'branding_options' => $brandingOptions,
            'cdn_domain' => config('cloudfront.domain'),
            'share_download_post_path' => $downloadPostPath,
            'guest_collection_path' => $guestCollectionPath,
            'guest_query' => [
                'q' => (string) $request->query('q', ''),
                'type' => $type,
                'sort' => $sort,
                'view' => $view,
            ],
            'guest_collection_asset_total' => $collectionAssetTotal,
            'guest_filtered_total' => $guestFilteredTotal,
            'guest_showing_count' => count($assets),
            'guest_asset_limit' => $limit,
        ]);
    }

    protected function finishShareUnlockAttempt(Collection $collection, string $plainPassword, RedirectResponse $successRedirect): RedirectResponse
    {
        $tenant = $collection->tenant;
        if (! $tenant || ! $this->shareGuestAccess->tenantAllowsShareLinks($tenant) || ! $collection->is_public) {
            abort(404, 'Collection not found.');
        }

        if (! $collection->hasPublicPassword()) {
            abort(404, 'Collection not found.');
        }

        if (! $this->shareGuestAccess->unlock($collection, $plainPassword)) {
            return redirect()->back()->withErrors([
                'password' => 'The password is incorrect.',
            ]);
        }

        return $successRedirect;
    }

    protected function assertGuestShareUnlockedForDownload(Collection $collection): void
    {
        if (! $this->shareGuestAccess->guestMayUseDownloads($collection)) {
            abort(404, 'Collection not found.');
        }
        if (! $this->shareGuestAccess->tenantAllowsPublicCollectionDownloads($collection->tenant)) {
            abort(404, 'Collection not found.');
        }
    }

    protected function performCreateDownload(Collection $collection, Request $request): RedirectResponse|JsonResponse
    {
        $this->assertGuestShareUnlockedForDownload($collection);

        $tenant = $collection->tenant;
        $brand = $collection->brand;
        if (! $tenant || ! $brand) {
            abort(404, 'Collection not found.');
        }

        $query = $this->collectionAssetQueryService->queryPublic($collection);
        $assetModels = $query->with('storageBucket')->get();
        $visibleIds = $assetModels->pluck('id')->all();

        if (empty($visibleIds)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'This collection has no assets to download.'], 422);
            }

            return redirect()->back()->with('error', 'This collection has no assets to download.');
        }

        $validatedIds = $this->validatedSubsetAssetIds($request, $visibleIds);
        if ($validatedIds === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid selection.'], 422);
            }

            return redirect()->back()->with('error', 'Invalid selection.');
        }

        $subsetRequested = $request->has('asset_ids');
        $modelsForZip = (! $subsetRequested || $validatedIds === [])
            ? $assetModels
            : $assetModels->whereIn('id', $validatedIds)->values();

        if ($modelsForZip->isEmpty()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'This collection has no assets to download.'], 422);
            }

            return redirect()->back()->with('error', 'This collection has no assets to download.');
        }

        $maxAssets = $this->planService->getMaxDownloadAssets($tenant);
        if ($modelsForZip->count() > $maxAssets) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "This plan allows up to {$maxAssets} assets per download.",
                ], 422);
            }

            return redirect()->back()->with('error', "This plan allows up to {$maxAssets} assets per download.");
        }

        $estimatedBytes = $modelsForZip->sum(fn (Asset $a) => (int) ($a->metadata['file_size'] ?? $a->metadata['size'] ?? 0));
        $maxZipBytes = $this->planService->getMaxDownloadZipBytes($tenant);
        if ($estimatedBytes > $maxZipBytes) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Estimated ZIP size exceeds plan limit.',
                ], 422);
            }

            return redirect()->back()->with('error', 'Estimated ZIP size exceeds plan limit.');
        }

        $bucketService = app(TenantBucketService::class);
        $bucket = $bucketService->resolveActiveBucketOrFail($tenant);

        try {
            $s3Client = $this->zipBuilder->createS3Client();
            if ($subsetRequested && $validatedIds !== []) {
                $tempPath = $this->zipBuilder->buildZipFromAssets($modelsForZip, $bucket, $s3Client);
                $s3Key = 'tmp/public-collection-partial/'.$collection->id.'/'.Str::uuid()->toString().'.zip';
                try {
                    $s3Client->putObject([
                        'Bucket' => $bucket->name,
                        'Key' => $s3Key,
                        'SourceFile' => $tempPath,
                        'ContentType' => 'application/zip',
                        'CacheControl' => 'private, max-age=3600',
                    ]);
                } finally {
                    if (file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                }
            } else {
                $s3Key = $this->zipBuilder->getOrBuildCachedZip($collection, $assetModels, $bucket, $s3Client);
            }
            $signedUrl = $this->zipBuilder->getSignedZipUrl($bucket, $s3Key, $s3Client, ttlMinutes: 30);
        } catch (\Throwable $e) {
            Log::error('[PublicCollectionController] Failed to build/serve collection ZIP', [
                'collection_id' => $collection->id,
                'error' => $e->getMessage(),
            ]);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Failed to prepare download. Please try again.'], 500);
            }

            return redirect()->back()->with('error', 'Failed to prepare download. Please try again.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'zip_url' => $signedUrl,
                'asset_count' => $modelsForZip->count(),
                'cached' => ($subsetRequested && $validatedIds !== []) ? false : $collection->hasPublicZipCached(),
            ], 200);
        }

        return redirect()->away($signedUrl);
    }

    /**
     * @param  list<string>  $visibleIds
     * @return list<string>|null  null = invalid request
     */
    protected function validatedSubsetAssetIds(Request $request, array $visibleIds): ?array
    {
        $ids = $request->input('asset_ids');
        if ($ids === null) {
            return [];
        }
        if (! is_array($ids)) {
            return null;
        }
        $visibleSet = array_fill_keys($visibleIds, true);
        $out = [];
        foreach ($ids as $id) {
            if (! is_string($id) && ! is_int($id)) {
                return null;
            }
            $sid = (string) $id;
            if (! isset($visibleSet[$sid])) {
                return null;
            }
            $out[$sid] = $sid;
        }

        return array_values($out);
    }

    protected function performStreamZip(Collection $collection, Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $this->assertGuestShareUnlockedForDownload($collection);

        $tenant = $collection->tenant;
        if (! $tenant) {
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
        $filename = preg_replace('/[\r\n"\\\\]/', '', $baseName.'-download-'.now()->format('Y-m-d').'.zip') ?: 'collection-download.zip';

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    protected function performAssetDownload(Collection $collection, Asset $asset, string $brand_slug_for_log): RedirectResponse
    {
        $this->assertGuestShareUnlockedForDownload($collection);

        $tenant = $collection->tenant;
        if (! $tenant || ! $this->shareGuestAccess->tenantAllowsShareLinks($tenant)) {
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
            'brand_slug' => $brand_slug_for_log,
        ]);

        if (! $asset->isPublic()) {
            abort(403, 'Asset not publicly accessible.');
        }

        $downloadUrl = $this->assetUrlService->getPublicDownloadUrl($asset);
        if (! $downloadUrl) {
            abort(404, 'File not available.');
        }

        return redirect()->away($downloadUrl);
    }

    protected function mapAssetToPublicGridArray(Asset $asset, Collection $collection, ?string $shareToken, bool $guestDownloadsEnabled): array
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

        $thumbnailUrl = $this->assetUrlService->getPublicThumbnailUrl($asset);

        $downloadUrl = null;
        if ($guestDownloadsEnabled && $asset->isPublic()) {
            $brand = $collection->brand;
            if ($brand) {
                $downloadUrl = $shareToken
                    ? url('/share/collections/'.$shareToken.'/assets/'.$asset->id.'/download')
                    : route('public.collections.assets.download', [
                        'brand_slug' => $brand->slug,
                        'collection_slug' => $collection->slug,
                        'asset' => $asset->id,
                    ], false);
            }
        }

        return [
            'id' => $asset->id,
            'title' => $title,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'file_extension' => $fileExtension,
            'size_bytes' => $asset->size_bytes,
            'thumbnail_url' => $thumbnailUrl,
            'final_thumbnail_url' => $thumbnailUrl,
            'download_url' => $downloadUrl,
            'thumbnail_status' => $thumbnailUrl ? 'completed' : 'pending',
            'is_published' => true,
            'archived_at' => null,
        ];
    }
}
