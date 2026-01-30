<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Tenant;
use App\Services\CollectionAssetQueryService;
use App\Services\CollectionAssetService;
use App\Services\FeatureGate;
use App\Services\MetadataVisibilityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CollectionController extends Controller
{
    public function __construct(
        protected CollectionAssetQueryService $collectionAssetQueryService,
        protected CollectionAssetService $collectionAssetService,
        protected FeatureGate $featureGate
    ) {
    }

    /**
     * Show the collections page (read-only UI).
     * Collections list is scoped to tenant + brand; assets only loaded when a collection is selected.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand) {
            return Inertia::render('Collections/Index', [
                'collections' => [],
                'assets' => [],
                'selected_collection' => null,
                'can_update_collection' => false,
                'can_create_collection' => false,
                'can_add_to_collection' => false,
                'can_remove_from_collection' => false,
                'public_collections_enabled' => false,
            ]);
        }

        // Collections: tenant + brand, then filter by CollectionPolicy::view (C6: visibility + membership)
        // C11: Include assets_count for sidebar signals (presentation only; no schema change)
        $collectionsQuery = Collection::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->withCount('assets')
            ->with(['brand', 'members'])
            ->orderBy('name');

        $collections = $collectionsQuery->get()
            ->filter(fn (Collection $c) => Gate::forUser($user)->allows('view', $c))
            ->values()
            ->map(fn (Collection $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'visibility' => $c->visibility,
                'is_public' => $c->is_public,
                'assets_count' => (int) ($c->assets_count ?? 0),
            ])
            ->all();

        $collectionIdParam = $request->query('collection');
        $assets = [];
        $selectedCollection = null;

        if ($collectionIdParam !== null && $collectionIdParam !== '') {
            $collection = Collection::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->with(['brand', 'members'])
                ->find($collectionIdParam);

            if ($collection && Gate::forUser($user)->allows('view', $collection)) {
                try {
                    $query = $this->collectionAssetQueryService->query($user, $collection);
                    $assetModels = $query->get();
                    $assets = $assetModels->map(fn (Asset $asset) => $this->mapAssetToGridArray($asset, $tenant, $brand))->values()->all();
                    $brand = $collection->brand;
                    $selectedCollection = [
                        'id' => $collection->id,
                        'name' => $collection->name,
                        'description' => $collection->description,
                        'visibility' => $collection->visibility ?? 'brand',
                        'slug' => $collection->slug,
                        'brand_slug' => $brand?->slug,
                        'is_public' => $collection->is_public,
                    ];
                } catch (\Throwable) {
                    // Unauthorized or other: leave assets empty, selected_collection null
                }
            }
        }

        // C11.1: can_update_collection = user can update the selected collection (policy: update)
        $canUpdateCollection = false;
        if ($selectedCollection !== null) {
            $collectionForUpdate = Collection::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->find($selectedCollection['id'] ?? null);
            if ($collectionForUpdate) {
                $canUpdateCollection = Gate::forUser($user)->allows('update', $collectionForUpdate);
            }
        }

        // C5: can_add_to_collection = user can add assets to at least one collection (role-based)
        $canAddToCollection = false;
        $canRemoveFromCollection = false;
        $anyCollection = Collection::query()->where('brand_id', $brand->id)->first();
        if ($anyCollection) {
            $canAddToCollection = Gate::forUser($user)->allows('addAsset', $anyCollection);
            $canRemoveFromCollection = Gate::forUser($user)->allows('removeAsset', $anyCollection);
        } else {
            $dummy = new Collection(['brand_id' => $brand->id]);
            $dummy->setRelation('brand', $brand);
            $canAddToCollection = Gate::forUser($user)->allows('addAsset', $dummy);
            $canRemoveFromCollection = Gate::forUser($user)->allows('removeAsset', $dummy);
        }

        // C10: Public Collections feature (plan-gated); when disabled, public toggle is hidden/disabled
        $publicCollectionsEnabled = $this->featureGate->publicCollectionsEnabled($tenant);

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
            'assets' => $assets,
            'selected_collection' => $selectedCollection,
            'can_update_collection' => $canUpdateCollection,
            'can_create_collection' => Gate::forUser($user)->allows('create', $brand),
            'can_add_to_collection' => $canAddToCollection,
            'can_remove_from_collection' => $canRemoveFromCollection,
            'public_collections_enabled' => $publicCollectionsEnabled,
        ]);
    }

    /**
     * JSON list of collections for dropdown (C5). Collections user can view (and add to).
     */
    public function listForDropdown(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand) {
            return response()->json(['collections' => []]);
        }
        $collections = Collection::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->with(['brand', 'members'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Collection $c) => Gate::forUser($user)->allows('view', $c))
            ->values()
            ->map(fn (Collection $c) => ['id' => $c->id, 'name' => $c->name, 'is_public' => $c->is_public])
            ->all();
        return response()->json(['collections' => $collections]);
    }

    /**
     * Create a collection (C5). JSON response.
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');

        // C9: Resolve brand explicitly when missing (e.g. uploader context) so authorization has correct context
        if (! $brand && $tenant && $request->user()) {
            $brandId = $request->input('brand_id') ?? session('brand_id');
            if ($brandId) {
                $brand = Brand::query()
                    ->where('tenant_id', $tenant->id)
                    ->find($brandId);
                if ($brand) {
                    app()->instance('brand', $brand);
                }
            }
        }

        $user = $request->user();
        if (! $tenant || ! $brand) {
            abort(403, 'Tenant or brand not resolved.');
        }

        // C9: Use Collection policy with brand explicitly so uploader and collections page behave the same
        Gate::forUser($user)->authorize('create', [Collection::class, $brand]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'visibility' => ['nullable', 'string', 'in:brand,restricted,private'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $exists = Collection::query()
            ->where('brand_id', $brand->id)
            ->where('name', $validated['name'])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => ['A collection with this name already exists for this brand.']]);
        }

        $visibility = $validated['visibility'] ?? 'brand';
        // C10: Only allow is_public = true when tenant has Public Collections feature
        $isPublic = isset($validated['is_public']) && $validated['is_public']
            && $this->featureGate->publicCollectionsEnabled($tenant);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'visibility' => $visibility,
            'is_public' => $isPublic,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
                'visibility' => $collection->visibility,
                'is_public' => $collection->is_public,
            ],
        ], 201);
    }

    /**
     * C10: Update collection (name, description, is_public). is_public only applied when feature enabled.
     */
    public function update(Request $request, Collection $collection): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $collection);

        $tenant = $collection->tenant;
        $publicCollectionsEnabled = $tenant && $this->featureGate->publicCollectionsEnabled($tenant);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'visibility' => ['nullable', 'string', 'in:brand,restricted,private'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('visibility', $validated) && in_array($validated['visibility'], ['brand', 'restricted', 'private'], true)) {
            $collection->visibility = $validated['visibility'];
        }
        if (array_key_exists('name', $validated) && $validated['name'] !== $collection->name) {
            $exists = Collection::query()
                ->where('brand_id', $collection->brand_id)
                ->where('id', '!=', $collection->id)
                ->where('name', $validated['name'])
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['name' => ['A collection with this name already exists for this brand.']]);
            }
            $collection->name = $validated['name'];
        }
        if (array_key_exists('description', $validated)) {
            $collection->description = $validated['description'];
        }
        if (array_key_exists('is_public', $validated)) {
            $newIsPublic = $publicCollectionsEnabled && $validated['is_public'];
            $collection->is_public = $newIsPublic;
            // C10: When making public, ensure slug exists for public URL
            if ($newIsPublic && (empty($collection->slug))) {
                $baseSlug = Str::slug($collection->name);
                $slug = $baseSlug;
                $counter = 0;
                while (Collection::query()->where('slug', $slug)->where('id', '!=', $collection->id)->exists()) {
                    $counter++;
                    $slug = $baseSlug . '-' . $counter;
                }
                $collection->slug = $slug;
            }
        }
        $collection->save();

        return response()->json([
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
                'visibility' => $collection->visibility,
                'is_public' => $collection->is_public,
                'slug' => $collection->slug,
            ],
        ]);
    }

    /**
     * Attach an asset to a collection (C5). JSON response. Idempotent.
     */
    public function addAsset(Request $request, Collection $collection): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('addAsset', $collection);

        $validated = $request->validate([
            'asset_id' => ['required', 'string', 'uuid'],
        ]);

        $asset = Asset::query()
            ->where('tenant_id', $collection->tenant_id)
            ->where('brand_id', $collection->brand_id)
            ->find($validated['asset_id']);
        if (! $asset) {
            throw ValidationException::withMessages(['asset_id' => ['Asset not found or does not belong to this collection\'s brand.']]);
        }

        $this->collectionAssetService->attach($collection, $asset);

        return response()->json(['attached' => true], 201);
    }

    /**
     * Detach an asset from a collection (C5). JSON response.
     */
    public function removeAsset(Collection $collection, Asset $asset): JsonResponse
    {
        $user = request()->user();
        Gate::forUser($user)->authorize('removeAsset', $collection);

        if ($asset->tenant_id !== $collection->tenant_id || $asset->brand_id !== $collection->brand_id) {
            abort(404, 'Asset not in this collection.');
        }

        $this->collectionAssetService->detach($collection, $asset);

        return response()->json(['detached' => true]);
    }

    /**
     * JSON list of collections this asset is in (C5). For "In X collections" in drawer.
     */
    public function assetCollections(Request $request, Asset $asset): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $asset);

        $collections = $asset->collections()
            ->where('tenant_id', $asset->tenant_id)
            ->where('brand_id', $asset->brand_id)
            ->with(['brand', 'members'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Collection $c) => Gate::forUser($user)->allows('view', $c))
            ->values()
            ->map(fn (Collection $c) => ['id' => $c->id, 'name' => $c->name, 'is_public' => $c->is_public])
            ->all();

        // C9.1: DEBUG - Log collections returned
        \Log::info('[CollectionController::assetCollections] Returning collections', [
            'asset_id' => $asset->id,
            'collections_count' => count($collections),
            'collection_ids' => array_column($collections, 'id'),
        ]);

        return response()->json(['collections' => $collections]);
    }

    /**
     * C9.1: Sync asset collections (add/remove in one operation). JSON response.
     * Handles full state sync: empty array = remove from all, partial = add/remove as needed.
     */
    public function syncAssetCollections(Request $request, Asset $asset): JsonResponse
    {
        $user = $request->user();
        $tenant = app('tenant');
        $brand = app('brand');

        Gate::forUser($user)->authorize('view', $asset);

        if (! $tenant || ! $brand) {
            abort(403, 'Tenant or brand not resolved.');
        }

        $validated = $request->validate([
            'collection_ids' => 'present|array', // C9.1: Empty array allowed (deselects all)
            'collection_ids.*' => 'integer|exists:collections,id',
        ]);

        $requestedCollectionIds = $validated['collection_ids'];
        $errors = [];
        $attached = [];
        $detached = [];

        // Get current collections for this asset (brand-scoped)
        $currentCollections = $asset->collections()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->pluck('collections.id')
            ->toArray();

        // Determine what to add and remove
        $toAdd = array_diff($requestedCollectionIds, $currentCollections);
        $toRemove = array_diff($currentCollections, $requestedCollectionIds);

        // Process removals first
        foreach ($toRemove as $collectionId) {
            $collection = Collection::query()
                ->where('id', $collectionId)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();

            if (! $collection) {
                $errors[] = "Collection {$collectionId} not found or does not belong to this brand.";
                continue;
            }

            if (! Gate::forUser($user)->allows('removeAsset', $collection)) {
                $errors[] = "You do not have permission to remove assets from collection: {$collection->name}.";
                continue;
            }

            try {
                $this->collectionAssetService->detach($collection, $asset);
                $detached[] = $collectionId;
            } catch (\Throwable $e) {
                $errors[] = "Failed to remove from collection {$collection->name}: {$e->getMessage()}";
            }
        }

        // Process additions
        foreach ($toAdd as $collectionId) {
            $collection = Collection::query()
                ->where('id', $collectionId)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();

            if (! $collection) {
                $errors[] = "Collection {$collectionId} not found or does not belong to this brand.";
                continue;
            }

            if (! Gate::forUser($user)->allows('addAsset', $collection)) {
                $errors[] = "You do not have permission to add assets to collection: {$collection->name}.";
                continue;
            }

            try {
                $this->collectionAssetService->attach($collection, $asset);
                $attached[] = $collectionId;
            } catch (\Throwable $e) {
                $errors[] = "Failed to add to collection {$collection->name}: {$e->getMessage()}";
            }
        }

        // Return result with any errors
        if (! empty($errors)) {
            return response()->json([
                'message' => 'Some collection assignments failed.',
                'errors' => $errors,
                'attached' => $attached,
                'detached' => $detached,
            ], 422);
        }

        return response()->json([
            'message' => 'Collections synced successfully.',
            'attached' => $attached,
            'detached' => $detached,
        ]);
    }

    /**
     * Map a single Asset model to the grid payload (same shape as AssetController/DeliverableController).
     */
    private function mapAssetToGridArray(Asset $asset, Tenant $tenant, Brand $brand): array
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
                'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/tiff' => 'tif', 'image/tif' => 'tif',
                'image/bmp' => 'bmp', 'application/pdf' => 'pdf', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
            ];
            $fileExtension = $mimeToExt[strtolower(trim($asset->mime_type))] ?? null;
        }

        $title = $asset->title;
        if (empty($title) || in_array($title, ['Unknown', 'Untitled Asset'], true)) {
            $title = $asset->original_filename ? (pathinfo($asset->original_filename, PATHINFO_FILENAME) ?? $asset->original_filename) : null;
        }
        if ($title === '') {
            $title = null;
        }

        $categoryName = null;
        $categoryId = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = Category::where('id', $categoryId)->where('tenant_id', $tenant->id)->where('brand_id', $brand->id)->first();
            if ($category) {
                $categoryName = $category->name;
            }
        }

        $uploadedBy = null;
        if ($asset->user_id) {
            $uploader = \App\Models\User::find($asset->user_id);
            if ($uploader) {
                $uploadedBy = [
                    'id' => $uploader->id,
                    'name' => $uploader->name,
                    'first_name' => $uploader->first_name,
                    'last_name' => $uploader->last_name,
                    'email' => $uploader->email,
                    'avatar_url' => $uploader->avatar_url,
                ];
            }
        }

        $metadata = $asset->metadata ?? [];
        $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
            ? $asset->thumbnail_status->value
            : ($asset->thumbnail_status ?? 'pending');

        $previewThumbnailUrl = null;
        $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
        if (! empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
            $previewThumbnailUrl = route('assets.thumbnail.preview', ['asset' => $asset->id, 'style' => 'preview']);
        }

        $finalThumbnailUrl = null;
        $thumbnailVersion = null;
        if ($thumbnailStatus === 'completed') {
            $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
            $finalThumbnailUrl = route('assets.thumbnail.final', ['asset' => $asset->id, 'style' => 'thumb']);
            if ($thumbnailVersion) {
                $finalThumbnailUrl .= '?v='.urlencode($thumbnailVersion);
            }
        }

        return [
            'id' => $asset->id,
            'title' => $title,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'file_extension' => $fileExtension,
            'status' => $asset->status instanceof \App\Enums\AssetStatus ? $asset->status->value : (string) $asset->status,
            'size_bytes' => $asset->size_bytes,
            'created_at' => $asset->created_at?->toIso8601String(),
            'metadata' => $asset->metadata,
            'category' => $categoryName ? ['id' => $categoryId, 'name' => $categoryName] : null,
            'uploaded_by' => $uploadedBy,
            'preview_thumbnail_url' => $previewThumbnailUrl,
            'final_thumbnail_url' => $finalThumbnailUrl,
            'thumbnail_version' => $thumbnailVersion,
            'thumbnail_url' => $finalThumbnailUrl ?? null,
            'thumbnail_status' => $thumbnailStatus,
            'thumbnail_error' => $asset->thumbnail_error,
            'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null,
            'preview_url' => null,
            'url' => null,
        ];
    }

    /**
     * C9.2: Check if collection field is visible for a category.
     * 
     * GET /app/collections/field-visibility?category_id={id}
     * 
     * Returns whether the collection metadata field is visible for the given category.
     * Uses the same visibility rules as other system metadata fields.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function checkFieldVisibility(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $categoryId = $request->query('category_id');
        
        if (!$tenant || !$categoryId) {
            // If no category, assume visible (no suppression)
            return response()->json(['visible' => true]);
        }
        
        $category = Category::where('id', $categoryId)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$category) {
            // Category not found, assume visible
            return response()->json(['visible' => true]);
        }
        
        // Get collection metadata field (if it exists and is not deprecated)
        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->first();
        
        if (!$collectionField) {
            // Collection field doesn't exist or is deprecated
            // C9.2: Default to visible if field doesn't exist (Collections are a feature, not deprecated)
            return response()->json(['visible' => true]);
        }
        
        // Use MetadataVisibilityResolver to check visibility
        $visibilityResolver = app(MetadataVisibilityResolver::class);
        
        // Create a field array with field_id for the resolver
        $field = [
            'field_id' => $collectionField->id,
            'key' => 'collection',
        ];
        
        $isVisible = $visibilityResolver->isFieldVisible($field, $category, $tenant);
        
        return response()->json(['visible' => $isVisible]);
    }
}
