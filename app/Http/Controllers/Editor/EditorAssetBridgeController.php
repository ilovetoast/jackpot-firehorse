<?php

namespace App\Http\Controllers\Editor;

use App\Enums\AssetType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\UploadController;
use App\Models\Asset;
use App\Models\Category;
use App\Services\Lifecycle\LifecycleResolver;
use App\Services\UploadInitiationService;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin DAM bridge for the generative asset editor (picker list + promote export).
 */
class EditorAssetBridgeController extends Controller
{
    protected ?S3Client $s3Client = null;

    public function __construct(
        protected LifecycleResolver $lifecycleResolver,
        protected UploadInitiationService $uploadInitiationService,
        protected UploadController $uploadController
    ) {}

    /**
     * GET /app/api/assets/categories
     *
     * Library and deliverable categories for the active brand (ASSET + DELIVERABLE), for publish UI.
     */
    public function categories(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Unauthorized', 'categories' => [], 'default_category_id' => null], 403);
        }

        $defaultCategory = Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->active()
            ->visible()
            ->ordered()
            ->first();

        if (! $defaultCategory) {
            $defaultCategory = Category::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('asset_type', AssetType::DELIVERABLE)
                ->active()
                ->visible()
                ->ordered()
                ->first();
        }

        $categories = Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->whereIn('asset_type', [AssetType::ASSET, AssetType::DELIVERABLE])
            ->active()
            ->visible()
            ->orderBy('asset_type')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'asset_type']);

        return response()->json([
            'categories' => $categories->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'asset_type' => $c->asset_type->value,
            ]),
            'default_category_id' => $defaultCategory?->id,
        ]);
    }

    /**
     * GET /app/api/assets/{asset}/file
     *
     * Stream original bytes through the app (same-origin). Avoids browser GET to presigned S3
     * (local/staging bucket policy and cache-busting on signed URLs often cause 403).
     */
    public function file(Request $request, Asset $asset): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            abort(403);
        }

        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            abort(404);
        }

        if (! $this->editorMayStreamOriginalFile($asset)) {
            abort(404);
        }

        Gate::authorize('view', $asset);

        $asset->loadMissing('storageBucket');

        $key = $asset->storage_root_path;
        if (! $key || ! $asset->storageBucket) {
            abort(404, 'File not available.');
        }

        return $this->streamS3KeyToResponse($asset, $key, 'private, max-age=300');
    }

    /**
     * Brand Guidelines font uploads are often type REFERENCE (builder-staged typography).
     * Allow streaming those bytes for FontFace; normal library assets stay ASSET/DELIVERABLE.
     */
    private function editorMayStreamOriginalFile(Asset $asset): bool
    {
        if (in_array($asset->type, [AssetType::ASSET, AssetType::DELIVERABLE], true)) {
            return true;
        }

        return $asset->type === AssetType::REFERENCE && $this->assetIsFontBinary($asset);
    }

    private function assetIsFontBinary(Asset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_starts_with($mime, 'font/')) {
            return true;
        }
        if (in_array($mime, [
            'application/font-woff',
            'application/font-woff2',
            'application/vnd.ms-opentype',
            'application/x-font-ttf',
            'application/x-font-otf',
        ], true)) {
            return true;
        }

        $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        if (in_array($ext, ['woff2', 'woff', 'ttf', 'otf', 'eot'], true)) {
            return true;
        }

        return $mime === 'application/octet-stream'
            && in_array($ext, ['woff2', 'woff', 'ttf', 'otf', 'eot'], true);
    }

    /**
     * GET /app/api/assets/{asset}/thumbnail?style=medium|thumb|large
     *
     * Stream a generated thumbnail through the app (same-origin).
     */
    public function thumbnail(Request $request, Asset $asset): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            abort(403);
        }

        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            abort(404);
        }

        if (! in_array($asset->type, [AssetType::ASSET, AssetType::DELIVERABLE], true)) {
            abort(404);
        }

        Gate::authorize('view', $asset);

        $asset->loadMissing('storageBucket');

        $style = $request->query('style', 'medium');
        if (! in_array($style, ['thumb', 'medium', 'large'], true)) {
            $style = 'medium';
        }

        $path = $asset->thumbnailPathForStyle($style);
        if (! $path) {
            foreach (['medium', 'thumb', 'large'] as $fallback) {
                if ($fallback === $style) {
                    continue;
                }
                $path = $asset->thumbnailPathForStyle($fallback);
                if ($path) {
                    break;
                }
            }
        }

        if ($path) {
            return $this->streamS3KeyToResponse($asset, $path, 'private, max-age=3600');
        }

        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_starts_with($mime, 'image/') && $asset->storage_root_path && $asset->storageBucket) {
            return $this->streamS3KeyToResponse($asset, $asset->storage_root_path, 'private, max-age=300');
        }

        abort(404, 'Thumbnail not available.');
    }

    /**
     * GET /app/api/assets?limit=50&asset_type=asset|deliverable&category_id=
     *
     * Image assets for the editor picker: library (ASSET) or executions (DELIVERABLE).
     * Optional category_id filters metadata.category_id (Photography, Print, etc.).
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized', 'assets' => [], 'default_category_id' => null], 403);
        }

        $limit = min(50, max(1, (int) $request->query('limit', 50)));
        $typeParam = strtolower((string) $request->query('asset_type', 'asset'));
        $assetType = $typeParam === 'deliverable' ? AssetType::DELIVERABLE : AssetType::ASSET;

        $query = Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', $assetType)
            ->normalIntakeOnly()
            ->excludeBuilderStaged()
            ->where(function ($q) {
                $q->where('mime_type', 'like', 'image/%');
            });

        $categoryFilterId = $request->query('category_id');
        if ($categoryFilterId !== null && $categoryFilterId !== '') {
            $cid = (int) $categoryFilterId;
            if ($cid > 0) {
                $filterCategory = Category::query()
                    ->where('id', $cid)
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->where('asset_type', $assetType)
                    ->active()
                    ->visible()
                    ->first();
                if ($filterCategory) {
                    $query->where(function ($q) use ($cid) {
                        $q->where('metadata->category_id', $cid)
                            ->orWhere('metadata->category_id', (string) $cid);
                    });
                }
            }
        }

        $this->lifecycleResolver->apply($query, null, $user, $tenant, $brand);

        $candidates = $query
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        $assets = $candidates
            ->filter(fn (Asset $a) => Gate::forUser($user)->allows('view', $a))
            ->take($limit)
            ->values();

        $defaultCategory = Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', $assetType)
            ->active()
            ->visible()
            ->ordered()
            ->first();

        $out = $assets->map(function (Asset $a) {
            $meta = $a->metadata ?? [];
            $thumbUrl = url('/app/api/assets/'.$a->id.'/thumbnail?style=medium');
            if (! empty($meta['thumbnails_generated_at'])) {
                $thumbUrl .= '&v='.urlencode((string) $meta['thumbnails_generated_at']);
            }
            $fileUrl = url('/app/api/assets/'.$a->id.'/file');

            return [
                'id' => (string) $a->id,
                'name' => $a->title ?? $a->original_filename ?? 'Asset',
                'thumbnail_url' => $thumbUrl,
                'file_url' => $fileUrl,
                'width' => $a->width,
                'height' => $a->height,
            ];
        });

        return response()->json([
            'assets' => $out,
            'default_category_id' => $defaultCategory?->id,
            'default_category_slug' => $defaultCategory?->slug,
        ]);
    }

    /**
     * GET /app/api/assets/{asset}
     *
     * Single asset for editor reference thumbnails when the id is not in the list response.
     */
    public function show(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (! in_array($asset->type, [AssetType::ASSET, AssetType::DELIVERABLE], true)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (! Gate::forUser($user)->allows('view', $asset)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $meta = $asset->metadata ?? [];
        $thumbUrl = url('/app/api/assets/'.$asset->id.'/thumbnail?style=medium');
        if (! empty($meta['thumbnails_generated_at'])) {
            $thumbUrl .= '&v='.urlencode((string) $meta['thumbnails_generated_at']);
        }
        $fileUrl = url('/app/api/assets/'.$asset->id.'/file');

        $out = [
            'id' => (string) $asset->id,
            'name' => $asset->title ?? $asset->original_filename ?? 'Asset',
            'thumbnail_url' => $thumbUrl,
            'file_url' => $fileUrl,
            'width' => $asset->width,
            'height' => $asset->height,
        ];

        return response()->json(['asset' => $out]);
    }

    /**
     * POST /app/api/assets
     *
     * Multipart: file, name, metadata (JSON string), optional category_id, description.
     * Promotes editor PNG into the DAM via upload finalize.
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return response()->json(['message' => 'You do not have permission to upload assets.'], 403);
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:png,jpeg,jpg,webp|max:102400',
            'name' => 'required|string|max:255',
            'metadata' => 'nullable|string|max:65535',
            'category_id' => 'nullable|integer|exists:categories,id',
            'description' => 'nullable|string|max:5000',
            'collection_ids' => 'nullable|string|max:65535',
        ]);

        $file = $request->file('file');

        $category = null;
        if (! empty($validated['category_id'])) {
            $category = Category::query()
                ->where('id', $validated['category_id'])
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereIn('asset_type', [AssetType::ASSET, AssetType::DELIVERABLE])
                ->active()
                ->visible()
                ->first();
        }

        if (! $category) {
            $category = Category::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('asset_type', AssetType::ASSET)
                ->active()
                ->visible()
                ->ordered()
                ->first();
        }

        if (! $category) {
            $category = Category::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('asset_type', AssetType::DELIVERABLE)
                ->active()
                ->visible()
                ->ordered()
                ->first();
        }

        if (! $category) {
            return response()->json(['message' => 'No category available for this brand. Create a library category first.'], 422);
        }

        $metaDecoded = [];
        if (! empty($validated['metadata'])) {
            try {
                $metaDecoded = json_decode($validated['metadata'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return response()->json(['message' => 'Invalid metadata JSON.'], 422);
            }
            if (! is_array($metaDecoded)) {
                $metaDecoded = [];
            }
        }

        $description = isset($validated['description']) ? trim((string) $validated['description']) : '';
        if ($description !== '') {
            $metaDecoded['editor_publish_description'] = $description;
        }

        $collectionIds = [];
        if (! empty($validated['collection_ids'])) {
            try {
                $decoded = json_decode($validated['collection_ids'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    foreach ($decoded as $id) {
                        $collectionIds[] = (int) $id;
                    }
                    $collectionIds = array_values(array_unique(array_filter($collectionIds, fn ($id) => $id > 0)));
                }
            } catch (\JsonException $e) {
                return response()->json(['message' => 'Invalid collection_ids JSON.'], 422);
            }
        }

        try {
            $init = $this->uploadInitiationService->initiate(
                $tenant,
                $brand,
                $file->getClientOriginalName(),
                $file->getSize(),
                $file->getMimeType()
            );
        } catch (\Throwable $e) {
            Log::warning('[EditorAssetBridge] initiate failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not start upload: '.$e->getMessage()], 502);
        }

        $uploadUrl = $init['upload_url'] ?? null;
        if (! $uploadUrl) {
            return response()->json(['message' => 'Multipart upload required for this file size; use standard uploader.'], 400);
        }

        $body = file_get_contents($file->getRealPath());
        $putResponse = Http::withHeaders([
            'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
        ])->withBody($body, $file->getMimeType() ?: 'application/octet-stream')->put($uploadUrl);

        if (! $putResponse->successful()) {
            Log::warning('[EditorAssetBridge] S3 PUT failed', ['status' => $putResponse->status()]);

            return response()->json(['message' => 'Storage upload failed.'], 502);
        }

        $manifestItem = [
            'upload_key' => $init['upload_key'],
            'expected_size' => $file->getSize(),
            'category_id' => $category->id,
            'title' => $validated['name'],
            'resolved_filename' => $file->getClientOriginalName(),
            'metadata' => $metaDecoded,
        ];

        if ($collectionIds !== []) {
            $manifestItem['collection_ids'] = $collectionIds;
        }

        $payload = json_encode(['manifest' => [$manifestItem]], JSON_THROW_ON_ERROR);

        $finalizeRequest = Request::create(
            '/app/assets/upload/finalize',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload
        );
        $finalizeRequest->setUserResolver(fn () => $user);
        if ($request->hasSession()) {
            $finalizeRequest->setLaravelSession($request->session());
        }

        return $this->uploadController->finalize($finalizeRequest);
    }

    protected function streamS3KeyToResponse(Asset $asset, string $s3Key, string $cacheControl): Response
    {
        $bucket = $asset->storageBucket;
        if (! $bucket) {
            abort(404, 'Storage not available.');
        }

        $s3Client = $this->getS3Client();

        try {
            $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);

            $contentLength = (int) ($result['ContentLength'] ?? 0);
            $contentType = $result['ContentType'] ?? $this->inferContentTypeFromPath($s3Key);

            $headers = [
                'Content-Type' => $contentType,
                'Cache-Control' => $cacheControl,
            ];
            if ($contentLength > 0) {
                $headers['Content-Length'] = (string) $contentLength;
            }

            return response()->stream(function () use ($result) {
                $body = $result['Body'];
                while (! $body->eof()) {
                    echo $body->read(8192);
                    flush();
                }
            }, 200, $headers);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                abort(404, 'File not found in storage.');
            }
            Log::warning('[EditorAssetBridge] S3 getObject failed', [
                'asset_id' => $asset->id,
                'key' => $s3Key,
                'error' => $e->getMessage(),
            ]);
            abort(502, 'Could not read file from storage.');
        }
    }

    protected function inferContentTypeFromPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    protected function getS3Client(): S3Client
    {
        if ($this->s3Client === null) {
            if (! class_exists(S3Client::class)) {
                throw new \RuntimeException('AWS SDK not installed.');
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
}
