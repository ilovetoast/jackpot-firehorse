<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\CollectionMember;
use App\Models\Download;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetEligibilityService;
use App\Services\AssetSearchService;
use App\Services\AssetSortService;
use App\Services\CollectionAssetQueryService;
use App\Services\CollectionAssetService;
use App\Services\Collections\CollectionGridMetadataFilterService;
use App\Services\BrandDNA\CampaignGoogleFontLibraryEntriesService;
use App\Services\FeatureGate;
use App\Services\MetadataFilterService;
use App\Services\MetadataVisibilityResolver;
use App\Support\Roles\RoleRegistry;
use App\Support\DerivativeFailureUserMessaging;
use App\Support\Typography\CampaignBannerFontEnricher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Mail\ShareCollectionPublicLinkInstructions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class CollectionController extends Controller
{
    public function __construct(
        protected CollectionAssetQueryService $collectionAssetQueryService,
        protected CollectionAssetService $collectionAssetService,
        protected FeatureGate $featureGate,
        protected AssetEligibilityService $assetEligibilityService,
        protected AssetSearchService $assetSearchService,
        protected AssetSortService $assetSortService,
        protected MetadataFilterService $metadataFilterService,
        protected CollectionGridMetadataFilterService $collectionGridMetadataFilterService,
    ) {}

    /**
     * Show the collections page (read-only UI).
     * Collections list is scoped to tenant + brand; assets only loaded when a collection is selected.
     * Returns JSON when `load_more` is set (infinite scroll append).
     */
    public function index(Request $request): Response|JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand) {
            return Inertia::render('Collections/Index', [
                'collections' => [],
                'assets' => [],
                'next_page_url' => null,
                'filtered_grid_total' => 0,
                'selected_collection' => null,
                'can_update_collection' => false,
                'can_create_collection' => false,
                'can_add_to_collection' => false,
                'can_remove_from_collection' => false,
                'public_collections_enabled' => false,
                'q' => '',
                'collection_type' => 'all',
                'category_id' => null,
                'group_by_category' => false,
                'filter_categories' => [],
                'filterable_schema' => [],
                'available_values' => [],
                'filters' => [],
                'grid_folder_total' => 0,
            ]);
        }

        // Collections: tenant + brand, then filter by CollectionPolicy::view (C6: visibility + membership)
        // C11: Include assets_count for sidebar — count only VISIBLE, non-deleted (same as grid) so sidebar matches grid
        $collectionsQuery = Collection::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->withCount(['assets as assets_count' => function ($q) {
                $q->where('assets.status', AssetStatus::VISIBLE)->whereNull('assets.deleted_at');
            }])
            ->withCount([
                'collectionAccessGrants as external_guest_grants_count' => function ($q) {
                    $q->whereNotNull('accepted_at');
                },
                'collectionInvitations as external_guest_invites_count',
            ])
            ->with(['brand', 'members', 'campaignIdentity'])
            ->orderBy('name');

        $collectionsModels = $collectionsQuery->get()
            ->filter(fn (Collection $c) => Gate::forUser($user)->allows('view', $c))
            ->values();

        // Build featured_image_url: prefer photography from collection, landscape aspect, high rating
        $collectionIds = $collectionsModels->pluck('id')->all();
        $bestAssetIds = [];
        if (! empty($collectionIds)) {
            $pairs = DB::table('asset_collections')
                ->join('assets', 'assets.id', '=', 'asset_collections.asset_id')
                ->where('assets.status', AssetStatus::VISIBLE)
                ->whereNull('assets.deleted_at')
                ->whereIn('collection_id', $collectionIds)
                ->select('collection_id', 'asset_id', 'asset_collections.created_at')
                ->orderBy('collection_id')
                ->orderBy('asset_collections.created_at')
                ->get();

            $collectionAssetIds = [];
            foreach ($pairs as $row) {
                $collectionAssetIds[$row->collection_id][] = $row->asset_id;
            }

            $allAssetIds = collect($collectionAssetIds)->flatten()->unique()->values()->all();
            if (! empty($allAssetIds)) {
                $assetsWithMeta = Asset::query()
                    ->whereIn('id', $allAssetIds)
                    ->get(['id', 'metadata', 'thumbnail_status', 'mime_type']);
                $categorySlugs = Category::query()
                    ->where('brand_id', $brand->id)
                    ->pluck('slug', 'id')
                    ->all();

                foreach ($collectionAssetIds as $collId => $assetIds) {
                    $best = $this->pickBestFeaturedAsset($assetsWithMeta->keyBy('id'), $assetIds, $categorySlugs);
                    if ($best) {
                        $bestAssetIds[$collId] = $best;
                    }
                }
            }
        }
        $featuredUrls = [];
        if (! empty($bestAssetIds)) {
            $bestAssets = Asset::query()
                ->whereIn('id', array_values($bestAssetIds))
                ->get(['id', 'metadata', 'thumbnail_status']);
            foreach ($bestAssets as $asset) {
                $status = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                    ? $asset->thumbnail_status->value
                    : ($asset->thumbnail_status ?? 'pending');
                if ($status === 'completed') {
                    $url = $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_LARGE, \App\Support\DeliveryContext::AUTHENTICATED)
                        ?: $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_MEDIUM, \App\Support\DeliveryContext::AUTHENTICATED)
                        ?: $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_SMALL, \App\Support\DeliveryContext::AUTHENTICATED);
                    $featuredUrls[$asset->id] = $url ?: null;
                } else {
                    $url = $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_PREVIEW, \App\Support\DeliveryContext::AUTHENTICATED);
                    if ($url !== '') {
                        $featuredUrls[$asset->id] = $url;
                    }
                }
            }
        }

        $collections = $collectionsModels
            ->map(fn (Collection $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'visibility' => $c->visibility,
                'access_mode' => $c->access_mode ?? 'all_brand',
                'allowed_brand_roles' => $c->allowed_brand_roles ?? [],
                'allows_external_guests' => (bool) ($c->allows_external_guests ?? false),
                'external_guest_grants_count' => (int) ($c->external_guest_grants_count ?? 0),
                'external_guest_invites_count' => (int) ($c->external_guest_invites_count ?? 0),
                'is_public' => $c->is_public,
                'assets_count' => (int) ($c->assets_count ?? 0),
                'featured_image_url' => isset($bestAssetIds[$c->id])
                    ? ($featuredUrls[$bestAssetIds[$c->id]] ?? null)
                    : null,
                'has_campaign' => $c->campaignIdentity !== null,
                'campaign_status' => $c->campaignIdentity?->campaign_status,
            ])
            ->all();

        $collectionIdParam = $request->query('collection');
        $assets = [];
        $paginator = null;
        $selectedCollection = null;
        $collectionTypeFilter = 'all';
        $categoryFilterIdForProps = null;
        $groupByCategoryEnabled = false;
        $filterCategories = [];
        $filterable_schema = [];
        $available_values = [];
        $filters = [];
        $gridFolderTotal = 0;

        if ($collectionIdParam !== null && $collectionIdParam !== '') {
            $collection = Collection::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->withCount([
                    'collectionAccessGrants as external_guest_grants_count' => function ($q) {
                        $q->whereNotNull('accepted_at');
                    },
                    'collectionInvitations as external_guest_invites_count',
                ])
                ->with(['brand', 'members', 'campaignIdentity'])
                ->find($collectionIdParam);

            if ($collection && Gate::forUser($user)->allows('view', $collection)) {
                $collectionBrand = $collection->brand;

                $campaignSummary = null;
                if ($collection->campaignIdentity) {
                    $ci = $collection->campaignIdentity;
                    $payload = is_array($ci->identity_payload) ? $ci->identity_payload : [];
                    $fonts = $payload['typography']['fonts'] ?? [];
                    $fonts = is_array($fonts) ? $fonts : [];
                    try {
                        $fontsForBanner = CampaignBannerFontEnricher::enrich($fonts);
                    } catch (\Throwable $e) {
                        Log::warning('collections.campaign_fonts_enrich_failed', [
                            'collection_id' => $collection->id,
                            'message' => $e->getMessage(),
                            'exception' => get_class($e),
                        ]);
                        $fontsForBanner = $fonts;
                    }
                    $primaryFont = $payload['typography']['primary_font']
                        ?? (count($fonts) > 0 ? ($fonts[0]['name'] ?? null) : null);

                    $featuredUrl = null;
                    if ($ci->featured_asset_id) {
                        $featuredAsset = Asset::find($ci->featured_asset_id);
                        if ($featuredAsset) {
                            $featuredUrl = $featuredAsset->deliveryUrl(\App\Support\AssetVariant::THUMB_LARGE, \App\Support\DeliveryContext::AUTHENTICATED)
                                ?: $featuredAsset->deliveryUrl(\App\Support\AssetVariant::THUMB_MEDIUM, \App\Support\DeliveryContext::AUTHENTICATED);
                        }
                    }

                    // Fallback: use the collection's own best featured image
                    if (! $featuredUrl) {
                        $collFeaturedId = $bestAssetIds[$collection->id] ?? null;
                        if ($collFeaturedId && isset($featuredUrls[$collFeaturedId])) {
                            $featuredUrl = $featuredUrls[$collFeaturedId];
                        }
                    }

                    $campaignSummary = [
                        'id' => $ci->id,
                        'campaign_name' => $ci->campaign_name,
                        'campaign_status' => $ci->campaign_status,
                        'campaign_goal' => $ci->campaign_goal,
                        'campaign_description' => $ci->campaign_description,
                        'palette' => $payload['visual']['palette'] ?? [],
                        'accent_colors' => $payload['visual']['accent_colors'] ?? [],
                        'primary_font' => $primaryFont,
                        'fonts' => $fontsForBanner,
                        'readiness_status' => $ci->readiness_status,
                        'scoring_enabled' => (bool) $ci->scoring_enabled,
                        'featured_asset_id' => $ci->featured_asset_id,
                        'featured_image_url' => $featuredUrl,
                    ];
                }

                $selectedCollection = array_merge([
                    'id' => $collection->id,
                    'name' => $collection->name,
                    'description' => $collection->description,
                    'visibility' => $collection->visibility ?? 'brand',
                    'access_mode' => $collection->access_mode ?? 'all_brand',
                    'allowed_brand_roles' => $collection->allowed_brand_roles ?? [],
                    'allows_external_guests' => (bool) ($collection->allows_external_guests ?? false),
                    'external_guest_grants_count' => (int) ($collection->external_guest_grants_count ?? 0),
                    'external_guest_invites_count' => (int) ($collection->external_guest_invites_count ?? 0),
                    'slug' => $collection->slug,
                    'brand_slug' => $collectionBrand?->slug,
                    'is_public' => $collection->is_public,
                    'campaign_summary' => $campaignSummary,
                ], $this->shareLinkPropsForCollection($collection));

                try {
                    $query = $this->collectionAssetQueryService->query($user, $collection);
                    $query->select('assets.*');

                    $collectionType = $this->normalizeCollectionTypeFilter($request->query('collection_type'));
                    $this->applyCollectionAssetTypeFilter($query, $collectionType);

                    $filterCategories = $this->categoriesPresentInCollection(
                        $user,
                        $collection,
                        $collectionType,
                        $tenant->id,
                        $brand->id
                    );
                    $filterCategories = $this->ensureFontsCategoryInCollectionFilterChips(
                        $collection,
                        $brand,
                        $tenant,
                        $collectionType,
                        $filterCategories,
                    );

                    $categoryFilterId = $request->query('category_id');
                    $categoryFilterId = is_numeric($categoryFilterId) ? (int) $categoryFilterId : null;
                    $allowedCategoryIds = collect($filterCategories)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    if ($categoryFilterId !== null && $categoryFilterId > 0 && ! in_array($categoryFilterId, $allowedCategoryIds, true)) {
                        $categoryFilterId = null;
                    }

                    if ($categoryFilterId !== null && $categoryFilterId > 0) {
                        $query->where('assets.metadata->category_id', $categoryFilterId);
                    }

                    $categoryModel = null;
                    if ($categoryFilterId !== null && $categoryFilterId > 0) {
                        $categoryModel = Category::query()
                            ->where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->find($categoryFilterId);
                    }

                    $fileType = $this->collectionGridMetadataFilterService->resolveSchemaFileType($collectionType);
                    $schema = $this->collectionGridMetadataFilterService->resolveSchema($tenant, $brand, $categoryModel?->id, $fileType);

                    $baseQueryForFilterVisibility = clone $query;
                    $parsedMetadataFilters = $this->collectionGridMetadataFilterService->parseFiltersFromRequest($request, $schema);
                    if (! empty($parsedMetadataFilters) && is_array($parsedMetadataFilters)) {
                        $this->metadataFilterService->applyFilters($query, $parsedMetadataFilters, $schema);
                    }

                    $searchQ = $request->input('q');
                    $qTrim = is_string($searchQ) ? trim($searchQ) : '';
                    if ($qTrim !== '') {
                        $this->assetSearchService->applyScopedSearch($query, $qTrim);
                        $this->assetSearchService->applyScopedSearch($baseQueryForFilterVisibility, $qTrim);
                    }

                    // Library scope for "filtered of folder" (collection + type + category + q; excludes metadata filters)
                    $gridFolderTotal = (int) (clone $baseQueryForFilterVisibility)->reorder()->count();

                    $hasNarrowingFilters = $qTrim !== ''
                        || ($categoryFilterId !== null && $categoryFilterId > 0)
                        || $collectionType !== 'all'
                        || ! empty($parsedMetadataFilters);
                    $groupByCategory = ! $hasNarrowingFilters;

                    $sort = $this->assetSortService->normalizeSort($request->input('sort'));
                    $sortDirection = $this->assetSortService->normalizeSortDirection($request->input('sort_direction'));
                    $this->assetSortService->applySort($query, $sort, $sortDirection);

                    $hueClusterCounts = $this->collectionGridMetadataFilterService->buildHueClusterCounts($query);

                    $perPage = 36;
                    $paginator = $query->paginate($perPage)->withQueryString();
                    $items = $paginator->items();
                    $incidentSeverityByAsset = $this->buildIncidentSeverityByAsset(collect($items)->pluck('id')->all());
                    $lookups = $this->buildGridLookupsForAssets(collect($items), $tenant, $brand);
                    $assets = collect($items)->map(fn (Asset $asset) => $this->mapAssetToGridArray(
                        $asset,
                        $tenant,
                        $brand,
                        $incidentSeverityByAsset,
                        $lookups['categories_by_id'],
                        $lookups['uploaders_by_id']
                    ))->values()->all();
                    $assets = $this->prependCampaignGoogleFontVirtualRowsForCollection(
                        $request,
                        $collection,
                        $tenant,
                        $brand,
                        $collectionType,
                        $categoryFilterId,
                        $paginator,
                        $assets,
                    );
                    $collectionTypeFilter = $collectionType;
                    $categoryFilterIdForProps = ($categoryFilterId !== null && $categoryFilterId > 0) ? $categoryFilterId : null;
                    $groupByCategoryEnabled = $groupByCategory;

                    $filters = $parsedMetadataFilters;
                    if (! $request->boolean('load_more')) {
                        $built = $this->collectionGridMetadataFilterService->buildFilterableSchemaAndAvailableValues(
                            $schema,
                            $categoryModel,
                            $tenant,
                            $baseQueryForFilterVisibility,
                            $hueClusterCounts,
                            collect($items),
                            $assets
                        );
                        $filterable_schema = $built['filterable_schema'];
                        $available_values = $built['available_values'];
                    }
                } catch (\Throwable $e) {
                    Log::error('collections.index.asset_grid_failed', [
                        'collection_id' => $collection->id,
                        'tenant_id' => $tenant->id,
                        'brand_id' => $brand->id,
                        'user_id' => $user->id,
                        'message' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);
                    $assets = [];
                    $paginator = null;
                    $collectionTypeFilter = $this->normalizeCollectionTypeFilter($request->query('collection_type'));
                    $categoryFilterIdForProps = null;
                    $groupByCategoryEnabled = false;
                    $filterCategories = [];
                    $filterable_schema = [];
                    $available_values = [];
                    $filters = [];
                    $gridFolderTotal = 0;
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

        $sort = $this->assetSortService->normalizeSort($request->input('sort'));
        $sortDirection = $this->assetSortService->normalizeSortDirection($request->input('sort_direction'));

        // Load-more: return JSON only so the client can append without Inertia replacing the list
        if ($request->boolean('load_more') && $selectedCollection !== null && isset($paginator)) {
            return response()->json([
                'data' => $assets,
                'next_page_url' => $paginator->nextPageUrl(),
            ]);
        }

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
            'assets' => $assets,
            'next_page_url' => isset($paginator) ? $paginator->nextPageUrl() : null,
            'filtered_grid_total' => isset($paginator) ? (int) $paginator->total() : 0,
            'grid_folder_total' => $gridFolderTotal,
            'selected_collection' => $selectedCollection,
            'can_update_collection' => $canUpdateCollection,
            'can_create_collection' => Gate::forUser($user)->allows('create', [Collection::class, $brand]),
            'can_add_to_collection' => $canAddToCollection,
            'can_remove_from_collection' => $canRemoveFromCollection,
            'public_collections_enabled' => $publicCollectionsEnabled,
            'billing_upgrade_url' => '/app/billing',
            'sort' => $sort,
            'sort_direction' => $sortDirection,
            'q' => $request->input('q', ''),
            'collection_type' => $collectionTypeFilter,
            'category_id' => $categoryFilterIdForProps,
            'group_by_category' => $groupByCategoryEnabled,
            'filter_categories' => $filterCategories,
            'filterable_schema' => $filterable_schema,
            'available_values' => $available_values,
            'filters' => $filters,
        ]);
    }

    /**
     * Grid + filters + pagination for one collection (shared by /app/collections and collection-guest view).
     *
     * @return array{
     *   assets: list<array<string, mixed>>,
     *   paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator|null,
     *   filter_categories: list<array<string, mixed>>,
     *   filterable_schema: list<array<string, mixed>>,
     *   available_values: array<string, mixed>,
     *   filters: array<string, mixed>,
     *   grid_folder_total: int,
     *   collection_type: string,
     *   category_id: int|null,
     *   group_by_category: bool,
     *   sort: string,
     *   sort_direction: string
     * }
     */
    public function buildCollectionGridPayloadForRequest(Request $request, Collection $collection, User $user): array
    {
        Gate::forUser($user)->authorize('view', $collection);

        $tenant = $collection->tenant;
        $brand = $collection->brand;

        $defaults = [
            'assets' => [],
            'paginator' => null,
            'filter_categories' => [],
            'filterable_schema' => [],
            'available_values' => [],
            'filters' => [],
            'grid_folder_total' => 0,
            'collection_type' => $this->normalizeCollectionTypeFilter($request->query('collection_type')),
            'category_id' => null,
            'group_by_category' => false,
            'sort' => $this->assetSortService->normalizeSort($request->input('sort')),
            'sort_direction' => $this->assetSortService->normalizeSortDirection($request->input('sort_direction')),
        ];

        if (! $tenant || ! $brand) {
            return $defaults;
        }

        try {
            $query = $this->collectionAssetQueryService->query($user, $collection);
            $query->select('assets.*');

            $collectionType = $this->normalizeCollectionTypeFilter($request->query('collection_type'));
            $this->applyCollectionAssetTypeFilter($query, $collectionType);

            $filterCategories = $this->categoriesPresentInCollection(
                $user,
                $collection,
                $collectionType,
                $tenant->id,
                $brand->id
            );
            $filterCategories = $this->ensureFontsCategoryInCollectionFilterChips(
                $collection,
                $brand,
                $tenant,
                $collectionType,
                $filterCategories,
            );

            $categoryFilterId = $request->query('category_id');
            $categoryFilterId = is_numeric($categoryFilterId) ? (int) $categoryFilterId : null;
            $allowedCategoryIds = collect($filterCategories)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($categoryFilterId !== null && $categoryFilterId > 0 && ! in_array($categoryFilterId, $allowedCategoryIds, true)) {
                $categoryFilterId = null;
            }

            if ($categoryFilterId !== null && $categoryFilterId > 0) {
                $query->where('assets.metadata->category_id', $categoryFilterId);
            }

            $categoryModel = null;
            if ($categoryFilterId !== null && $categoryFilterId > 0) {
                $categoryModel = Category::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->find($categoryFilterId);
            }

            $fileType = $this->collectionGridMetadataFilterService->resolveSchemaFileType($collectionType);
            $schema = $this->collectionGridMetadataFilterService->resolveSchema($tenant, $brand, $categoryModel?->id, $fileType);

            $baseQueryForFilterVisibility = clone $query;
            $parsedMetadataFilters = $this->collectionGridMetadataFilterService->parseFiltersFromRequest($request, $schema);
            if (! empty($parsedMetadataFilters) && is_array($parsedMetadataFilters)) {
                $this->metadataFilterService->applyFilters($query, $parsedMetadataFilters, $schema);
            }

            $searchQ = $request->input('q');
            $qTrim = is_string($searchQ) ? trim($searchQ) : '';
            if ($qTrim !== '') {
                $this->assetSearchService->applyScopedSearch($query, $qTrim);
                $this->assetSearchService->applyScopedSearch($baseQueryForFilterVisibility, $qTrim);
            }

            $gridFolderTotal = (int) (clone $baseQueryForFilterVisibility)->reorder()->count();

            $hasNarrowingFilters = $qTrim !== ''
                || ($categoryFilterId !== null && $categoryFilterId > 0)
                || $collectionType !== 'all'
                || ! empty($parsedMetadataFilters);
            $groupByCategory = ! $hasNarrowingFilters;

            $sort = $this->assetSortService->normalizeSort($request->input('sort'));
            $sortDirection = $this->assetSortService->normalizeSortDirection($request->input('sort_direction'));
            $this->assetSortService->applySort($query, $sort, $sortDirection);

            $hueClusterCounts = $this->collectionGridMetadataFilterService->buildHueClusterCounts($query);

            $perPage = 36;
            $paginator = $query->paginate($perPage)->withQueryString();
            $items = $paginator->items();
            $incidentSeverityByAsset = $this->buildIncidentSeverityByAsset(collect($items)->pluck('id')->all());
            $lookups = $this->buildGridLookupsForAssets(collect($items), $tenant, $brand);
            $assets = collect($items)->map(fn (Asset $asset) => $this->mapAssetToGridArray(
                $asset,
                $tenant,
                $brand,
                $incidentSeverityByAsset,
                $lookups['categories_by_id'],
                $lookups['uploaders_by_id']
            ))->values()->all();
            $assets = $this->prependCampaignGoogleFontVirtualRowsForCollection(
                $request,
                $collection,
                $tenant,
                $brand,
                $collectionType,
                $categoryFilterId,
                $paginator,
                $assets,
            );

            $filters = $parsedMetadataFilters;
            $filterable_schema = [];
            $available_values = [];
            if (! $request->boolean('load_more')) {
                $built = $this->collectionGridMetadataFilterService->buildFilterableSchemaAndAvailableValues(
                    $schema,
                    $categoryModel,
                    $tenant,
                    $baseQueryForFilterVisibility,
                    $hueClusterCounts,
                    collect($items),
                    $assets
                );
                $filterable_schema = $built['filterable_schema'];
                $available_values = $built['available_values'];
            }

            return [
                'assets' => $assets,
                'paginator' => $paginator,
                'filter_categories' => $filterCategories,
                'filterable_schema' => $filterable_schema,
                'available_values' => $available_values,
                'filters' => $filters,
                'grid_folder_total' => $gridFolderTotal,
                'collection_type' => $collectionType,
                'category_id' => ($categoryFilterId !== null && $categoryFilterId > 0) ? $categoryFilterId : null,
                'group_by_category' => $groupByCategory,
                'sort' => $sort,
                'sort_direction' => $sortDirection,
            ];
        } catch (\Throwable $e) {
            Log::error('collections.grid_payload_failed', [
                'collection_id' => $collection->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return $defaults;
        }
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

        $publicCollectionsEnabledForStore = $this->featureGate->publicCollectionsEnabled($tenant);
        $sharePasswordApplies = $request->boolean('is_public') && $publicCollectionsEnabledForStore;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'visibility' => ['nullable', 'string', 'in:brand,restricted,private'],
            'access_mode' => ['nullable', 'string', 'in:all_brand,role_limited,invite_only'],
            'allowed_brand_roles' => ['nullable', 'array'],
            'allowed_brand_roles.*' => ['string', Rule::in(RoleRegistry::brandRoles())],
            'allows_external_guests' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'public_password' => [
                Rule::excludeIf(! $sharePasswordApplies),
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
            ],
            'public_password_confirmation' => [Rule::excludeIf(! $sharePasswordApplies), 'nullable', 'string'],
            'clear_public_password' => ['nullable', 'boolean'],
            'public_downloads_enabled' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('is_public') && ! $publicCollectionsEnabledForStore) {
            throw ValidationException::withMessages([
                'is_public' => ['Share links are not available on your current plan.'],
            ]);
        }

        $exists = Collection::query()
            ->where('brand_id', $brand->id)
            ->where('name', $validated['name'])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => ['A collection with this name already exists for this brand.']]);
        }

        $accessMode = $validated['access_mode'] ?? null;
        if ($accessMode === null) {
            $legacyVis = $validated['visibility'] ?? 'brand';
            $accessMode = in_array($legacyVis, ['restricted', 'private'], true) ? 'invite_only' : 'all_brand';
        }

        $allowsExternal = (bool) ($validated['allows_external_guests'] ?? false);
        if ($accessMode === 'invite_only' && ! array_key_exists('allows_external_guests', $validated) && ! array_key_exists('access_mode', $validated)) {
            $allowsExternal = in_array($validated['visibility'] ?? 'brand', ['restricted', 'private'], true);
        }
        if ($accessMode === 'all_brand') {
            $allowsExternal = false;
        }

        $roles = $validated['allowed_brand_roles'] ?? null;
        if (is_array($roles) && $roles !== []) {
            $roles = array_values(array_intersect($roles, RoleRegistry::brandRoles()));
            $roles = $roles === [] ? null : $roles;
        } else {
            $roles = null;
        }
        if ($accessMode === 'all_brand') {
            $roles = null;
        }

        // C10: Only allow is_public = true when tenant has Public Collections feature
        $isPublic = isset($validated['is_public']) && $validated['is_public']
            && $publicCollectionsEnabledForStore;

        if ($request->boolean('clear_public_password') && $isPublic) {
            throw ValidationException::withMessages([
                'clear_public_password' => ['Removing the password is not allowed while the share link is enabled.'],
            ]);
        }

        $collection = new Collection([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'access_mode' => $accessMode,
            'allowed_brand_roles' => $roles,
            'allows_external_guests' => $allowsExternal,
            'is_public' => $isPublic,
            'public_downloads_enabled' => (bool) ($validated['public_downloads_enabled'] ?? true),
            'created_by' => $user->id,
        ]);
        $this->syncCollectionVisibilityFromAccessMode($collection);
        if ($isPublic) {
            $collection->ensurePublicShareToken();
            $collection->public_password_hash = Hash::make($validated['public_password']);
            $collection->public_password_set_at = now();
        }
        $collection->save();

        return response()->json([
            'collection' => $this->collectionJsonForEdit($collection),
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
        $passwordExistedAtStart = $collection->hasPublicPassword();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'access_mode' => ['sometimes', 'string', 'in:all_brand,role_limited,invite_only'],
            'allowed_brand_roles' => ['nullable', 'array'],
            'allowed_brand_roles.*' => ['string', Rule::in(RoleRegistry::brandRoles())],
            'allows_external_guests' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'public_password' => ['nullable', 'string', 'min:8', 'max:255', 'confirmed'],
            'public_password_confirmation' => ['nullable', 'string'],
            'clear_public_password' => ['nullable', 'boolean'],
            'public_downloads_enabled' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('is_public', $validated) && $validated['is_public'] && ! $publicCollectionsEnabled) {
            throw ValidationException::withMessages([
                'is_public' => ['Share links are not available on your current plan.'],
            ]);
        }

        if (array_key_exists('access_mode', $validated)) {
            $collection->access_mode = $validated['access_mode'];
        }
        if (array_key_exists('allowed_brand_roles', $validated)) {
            $roles = $validated['allowed_brand_roles'];
            if (is_array($roles) && $roles !== []) {
                $roles = array_values(array_intersect($roles, RoleRegistry::brandRoles()));
                $collection->allowed_brand_roles = $roles === [] ? null : $roles;
            } else {
                $collection->allowed_brand_roles = null;
            }
        }
        if (array_key_exists('allows_external_guests', $validated)) {
            $collection->allows_external_guests = (bool) $validated['allows_external_guests'];
        }
        $this->syncCollectionVisibilityFromAccessMode($collection);

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
            $newIsPublic = (bool) $validated['is_public'];
            $collection->is_public = $newIsPublic;
            // C10: When making public, ensure slug exists for legacy /b/... URL (token URL is primary)
            if ($newIsPublic && (empty($collection->slug))) {
                $baseSlug = Str::slug($collection->name);
                $slug = $baseSlug;
                $counter = 0;
                while (Collection::query()->where('slug', $slug)->where('id', '!=', $collection->id)->exists()) {
                    $counter++;
                    $slug = $baseSlug.'-'.$counter;
                }
                $collection->slug = $slug;
            }
        }

        if ($request->boolean('clear_public_password') && $collection->is_public) {
            throw ValidationException::withMessages([
                'clear_public_password' => ['Removing the password is not allowed while the share link is enabled.'],
            ]);
        }

        $newPassword = isset($validated['public_password']) && is_string($validated['public_password']) && trim($validated['public_password']) !== ''
            ? $validated['public_password']
            : null;

        if ($collection->is_public && $publicCollectionsEnabled) {
            if (! $passwordExistedAtStart && ! $newPassword) {
                throw ValidationException::withMessages([
                    'public_password' => ['Set a password to enable the share link.'],
                ]);
            }
            if ($newPassword) {
                $collection->public_password_hash = Hash::make($newPassword);
                $collection->public_password_set_at = now();
            }
            $collection->ensurePublicShareToken();
        }

        if (array_key_exists('public_downloads_enabled', $validated)) {
            $collection->public_downloads_enabled = (bool) $validated['public_downloads_enabled'];
        }

        $collection->save();

        return response()->json([
            'collection' => $this->collectionJsonForEdit($collection->fresh()),
        ]);
    }

    /**
     * Email the public share URL (and optionally the password after verification) to an external address.
     * Authorized like collection update: brand admin / brand manager (and tenant admins with cascade).
     */
    public function sendPublicShareInstructions(Request $request, Collection $collection): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $collection);

        $tenant = $collection->tenant;
        $brand = app('brand');
        if (! $tenant || ! $brand || $collection->tenant_id !== $tenant->id || $collection->brand_id !== $brand->id) {
            abort(404, 'Collection not found.');
        }

        if (! $this->featureGate->publicCollectionsEnabled($tenant)) {
            throw ValidationException::withMessages([
                'email' => ['Share links are not available on your current plan.'],
            ]);
        }

        if (! $collection->isShareableByGuests()) {
            throw ValidationException::withMessages([
                'email' => ['Turn on the share link and set a password before sending instructions.'],
            ]);
        }

        $shareUrl = $collection->publicShareUrl();
        if (! $shareUrl) {
            throw ValidationException::withMessages([
                'email' => ['Share link is not available for this collection yet.'],
            ]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'personal_message' => ['nullable', 'string', 'max:2000'],
            'include_password' => ['nullable', 'boolean'],
            'share_password' => ['nullable', 'string', 'max:500'],
        ]);

        $includePassword = $request->boolean('include_password');
        $verifiedPlain = null;
        if ($includePassword) {
            $plain = $request->string('share_password')->toString();
            if (trim($plain) === '') {
                throw ValidationException::withMessages([
                    'share_password' => ['Enter the share password to include it in the email, or turn off "Include password".'],
                ]);
            }
            if (! Hash::check($plain, $collection->public_password_hash)) {
                throw ValidationException::withMessages([
                    'share_password' => ['That password does not match this collection share password.'],
                ]);
            }
            $verifiedPlain = $plain;
        }

        $personal = isset($validated['personal_message']) ? trim((string) $validated['personal_message']) : '';
        $personal = $personal !== '' ? $personal : null;

        Mail::to($validated['email'])->send(new ShareCollectionPublicLinkInstructions(
            $collection->fresh(['brand', 'tenant']),
            $user,
            $shareUrl,
            $verifiedPlain,
            $personal,
        ));

        return response()->json(['sent' => true]);
    }

    /**
     * Brand teammates eligible for internal collection invites + current collection_members.
     */
    /**
     * Snapshot stats for the collection (assets, access rows, download links created from this collection).
     */
    public function stats(Request $request, Collection $collection): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $collection);

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand || $collection->tenant_id !== $tenant->id || $collection->brand_id !== $brand->id) {
            abort(404, 'Collection not found.');
        }

        $assetsVisible = $collection->assets()
            ->where('assets.status', AssetStatus::VISIBLE)
            ->whereNull('assets.deleted_at')
            ->count();

        $internalAccepted = $collection->members()->whereNotNull('accepted_at')->count();
        $internalPending = $collection->members()->whereNull('accepted_at')->count();

        $externalGrants = $collection->collectionAccessGrants()->whereNotNull('accepted_at')->count();
        $pendingInvites = $collection->collectionInvitations()->count();

        $downloadBase = Download::query()
            ->where('tenant_id', $tenant->id)
            ->where('download_options->collection_id', $collection->id);

        return response()->json([
            'assets_visible_count' => $assetsVisible,
            'internal_members' => [
                'accepted' => $internalAccepted,
                'pending' => $internalPending,
            ],
            'external_access' => [
                'active_grants' => $externalGrants,
                'pending_invites' => $pendingInvites,
            ],
            'downloads_from_collection' => [
                'download_groups_created' => (clone $downloadBase)->count(),
                'link_opens_recorded' => (int) (clone $downloadBase)->sum('access_count'),
            ],
            'is_public' => (bool) $collection->is_public,
        ]);
    }

    public function internalInviteData(Request $request, Collection $collection): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('invite', $collection);

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand || $collection->tenant_id !== $tenant->id || $collection->brand_id !== $brand->id) {
            abort(404, 'Collection not found.');
        }

        $brandUsers = User::query()
            ->whereHas('brands', function ($q) use ($brand) {
                $q->where('brands.id', $brand->id)->whereNull('brand_user.removed_at');
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(500)
            ->get(['id', 'first_name', 'last_name', 'email', 'avatar_url']);

        $brandUsersPayload = $brandUsers->map(function (User $u) use ($brand) {
            return [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'email' => $u->email,
                'avatar_url' => $u->avatar_url,
                'brand_role' => $u->getRoleForBrand($brand),
            ];
        })->values()->all();

        $members = $collection->members()
            ->with(['user:id,first_name,last_name,email,avatar_url'])
            ->orderByDesc('invited_at')
            ->get()
            ->map(fn (CollectionMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'invited_at' => $m->invited_at?->toIso8601String(),
                'accepted_at' => $m->accepted_at?->toIso8601String(),
                'user' => $m->user ? [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                    'first_name' => $m->user->first_name,
                    'last_name' => $m->user->last_name,
                    'avatar_url' => $m->user->avatar_url,
                ] : null,
            ])
            ->values()
            ->all();

        return response()->json([
            'brand_users' => $brandUsersPayload,
            'members' => $members,
        ]);
    }

    /**
     * Remove a brand teammate from the collection member list (C7).
     */
    public function destroyMember(Request $request, Collection $collection, CollectionMember $member): JsonResponse
    {
        Gate::forUser($request->user())->authorize('removeMember', $collection);

        if ($member->collection_id !== (int) $collection->id) {
            abort(404, 'Member not found.');
        }

        $member->delete();

        return response()->json(['ok' => true]);
    }

    private function syncCollectionVisibilityFromAccessMode(Collection $collection): void
    {
        $mode = $collection->access_mode ?? 'all_brand';
        if ($mode === 'all_brand') {
            $collection->allows_external_guests = false;
            $collection->allowed_brand_roles = null;
        }
        $collection->visibility = match ($mode) {
            'role_limited' => 'restricted',
            'invite_only' => 'private',
            default => 'brand',
        };
    }

    /**
     * Safe client props for password-protected share links (never exposes hash).
     *
     * @return array<string, mixed>
     */
    private function shareLinkPropsForCollection(Collection $collection): array
    {
        return [
            'has_public_password' => $collection->hasPublicPassword(),
            'public_password_set_at' => $collection->public_password_set_at?->toIso8601String(),
            'public_downloads_enabled' => (bool) ($collection->public_downloads_enabled ?? true),
            'public_share_url' => $collection->publicShareUrl(),
            'needs_share_password_setup' => (bool) ($collection->is_public && ! $collection->hasPublicPassword()),
            'billing_upgrade_url' => '/app/billing',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionJsonForEdit(Collection $collection): array
    {
        $collection->loadMissing('brand');
        $collection->loadCount([
            'collectionAccessGrants as external_guest_grants_count' => function ($q) {
                $q->whereNotNull('accepted_at');
            },
            'collectionInvitations as external_guest_invites_count',
        ]);

        return array_merge([
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
            'visibility' => $collection->visibility,
            'access_mode' => $collection->access_mode ?? 'all_brand',
            'allowed_brand_roles' => $collection->allowed_brand_roles ?? [],
            'allows_external_guests' => (bool) ($collection->allows_external_guests ?? false),
            'external_guest_grants_count' => (int) ($collection->external_guest_grants_count ?? 0),
            'external_guest_invites_count' => (int) ($collection->external_guest_invites_count ?? 0),
            'is_public' => $collection->is_public,
            'slug' => $collection->slug,
            'brand_slug' => $collection->brand?->slug,
        ], $this->shareLinkPropsForCollection($collection));
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

        // D6.1: Asset eligibility (published, non-archived) is enforced here. Do not bypass this for collections or downloads.
        if (! $this->assetEligibilityService->isEligibleForCollections($asset)) {
            return response()->json([
                'message' => 'Some selected assets are not published and cannot be added to collections.',
            ], 422);
        }

        $this->collectionAssetService->attach($collection, $asset);

        $this->autoSelectCampaignFeaturedImage($collection, $asset);

        \App\Services\BrandIntelligence\Campaign\CampaignScoringDispatcher::dispatchForAsset(
            $asset->id,
            $collection->id,
        );

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

        Gate::forUser($user)->authorize('update', $asset);

        if (! $tenant || ! $brand) {
            abort(403, 'Tenant or brand not resolved.');
        }

        $validated = $request->validate([
            'collection_ids' => 'present|array', // C9.1: Empty array allowed (deselects all)
            'collection_ids.*' => 'integer|exists:collections,id',
        ]);

        $requestedCollectionIds = array_map(
            static fn ($id) => (int) $id,
            $validated['collection_ids'],
        );
        $errors = [];
        $attached = [];
        $detached = [];

        // Get current collections for this asset (brand-scoped)
        $currentCollections = $asset->collections()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->pluck('collections.id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        // Determine what to add and remove
        $toAdd = array_values(array_diff($requestedCollectionIds, $currentCollections));
        $toRemove = array_values(array_diff($currentCollections, $requestedCollectionIds));

        // D6.1: Fail before any detach/attach if adds are requested but asset cannot join collections.
        // (Previously removals ran first and the request still 422'd on add — leaving a half-applied state.)
        if ($toAdd !== [] && ! $this->assetEligibilityService->isEligibleForCollections($asset)) {
            return response()->json([
                'message' => 'This asset is not published (or is archived) and cannot be added to collections. Publish it first, or exclude it from this bulk action. Removing collections from unpublished assets is allowed — use an operation that only removes memberships.',
            ], 422);
        }

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

                \App\Services\BrandIntelligence\Campaign\CampaignScoringDispatcher::dispatchForAsset(
                    $asset->id,
                    $collection->id,
                );
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
     * Pick the best asset for a collection's featured image.
     * Prefers: photography category, landscape aspect ratio, high quality_rating.
     * All images come from the collection's own assets.
     *
     * @param  \Illuminate\Support\Collection<int, Asset>  $assetsById
     * @param  array<string>  $assetIds  Ordered by asset_collections.created_at
     * @param  array<int, string>  $categorySlugs  category_id => slug
     * @return string|null Asset UUID or null if none suitable
     */
    private function pickBestFeaturedAsset($assetsById, array $assetIds, array $categorySlugs): ?string
    {
        $bestId = null;
        $bestScore = -1;

        foreach ($assetIds as $assetId) {
            $asset = $assetsById->get($assetId);
            if (! $asset) {
                continue;
            }

            $mime = $asset->mime_type ?? '';
            $isImage = str_starts_with(strtolower($mime), 'image/');
            if (! $isImage) {
                continue;
            }

            $metadata = $asset->metadata ?? [];
            $width = (int) ($metadata['image_width'] ?? $metadata['source_image_width'] ?? 0);
            $height = (int) ($metadata['image_height'] ?? $metadata['source_image_height'] ?? 0);
            $rating = (int) ($metadata['quality_rating'] ?? 0);
            $categoryId = $metadata['category_id'] ?? null;
            $categorySlug = $categoryId ? ($categorySlugs[$categoryId] ?? null) : null;
            $isPhotography = $categorySlug === 'photography';
            $isLandscape = $width > 0 && $height > 0 && $width > $height;

            $score = 0;
            if ($isPhotography) {
                $score += 20;
            }
            if ($isLandscape) {
                $score += 10;
            }
            $score += min(4, max(0, $rating));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $asset->id;
            }
        }

        return $bestId ?? ($assetIds[0] ?? null);
    }

    /**
     * Auto-select a campaign featured image when an asset is added to a campaign collection
     * that doesn't already have one set.
     */
    private function autoSelectCampaignFeaturedImage(Collection $collection, Asset $asset): void
    {
        $ci = $collection->campaignIdentity;
        if (! $ci || $ci->featured_asset_id) {
            return;
        }

        $mime = $asset->mime_type ?? '';
        if (str_starts_with(strtolower($mime), 'image/')) {
            $ci->update(['featured_asset_id' => $asset->id]);
        }
    }

    /**
     * Build asset_id => worst incident severity map for health badge.
     *
     * @param  string[]  $assetIds
     * @return array<string, string>
     */
    private function buildIncidentSeverityByAsset(array $assetIds): array
    {
        $incidentSeverityByAsset = [];
        if (empty($assetIds)) {
            return $incidentSeverityByAsset;
        }
        $incidents = \App\Models\SystemIncident::whereNull('resolved_at')
            ->whereIn('source_type', ['asset', 'job'])
            ->whereIn('source_id', $assetIds)
            ->get(['source_id', 'severity']);
        $order = ['critical' => 1, 'error' => 2, 'warning' => 3];
        foreach ($incidents as $inc) {
            $aid = $inc->source_id;
            $sev = $inc->severity ?? 'warning';
            if (! isset($incidentSeverityByAsset[$aid]) || ($order[$sev] ?? 99) < ($order[$incidentSeverityByAsset[$aid]] ?? 99)) {
                $incidentSeverityByAsset[$aid] = $sev;
            }
        }

        return $incidentSeverityByAsset;
    }

    /**
     * One query each for categories and uploaders used by the current page of assets (avoids N+1 in mapAssetToGridArray).
     *
     * @return array{categories_by_id: array<int, Category>, uploaders_by_id: array<int, User>}
     */
    private function buildGridLookupsForAssets(\Illuminate\Support\Collection $assets, Tenant $tenant, Brand $brand): array
    {
        // Keep extraction aligned with mapAssetToGridArray (metadata is array-cast on Asset).
        $categoryIds = $assets
            ->map(function (Asset $a) {
                $m = $a->metadata;
                if (! is_array($m) || ! isset($m['category_id'])) {
                    return null;
                }
                $raw = $m['category_id'];

                return is_numeric($raw) ? (int) $raw : null;
            })
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

        $categoriesById = [];
        if ($categoryIds !== []) {
            $categoriesById = Category::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereIn('id', $categoryIds)
                ->get()
                ->keyBy(fn (Category $c) => (int) $c->id)
                ->all();
        }

        $userIds = $assets->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $uploadersById = [];
        if ($userIds !== []) {
            $uploadersById = User::query()
                ->whereIn('id', $userIds)
                ->get()
                ->keyBy(fn (User $u) => (int) $u->id)
                ->all();
        }

        return [
            'categories_by_id' => $categoriesById,
            'uploaders_by_id' => $uploadersById,
        ];
    }

    /**
     * Map a single Asset model to the grid payload (same shape as AssetController/DeliverableController).
     *
     * @param  array<int, Category>  $categoriesById
     * @param  array<int, User>  $uploadersById
     */
    private function mapAssetToGridArray(
        Asset $asset,
        Tenant $tenant,
        Brand $brand,
        array $incidentSeverityByAsset = [],
        array $categoriesById = [],
        array $uploadersById = []
    ): array {
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
            $rawCatId = $asset->metadata['category_id'];
            if (is_numeric($rawCatId)) {
                $categoryId = (int) $rawCatId;
            }
            // Use batch map only. Empty map after whereIn means missing/deleted rows — do not fall back to
            // per-asset queries (that caused N+1 when the batch returned [] but assets still referenced ids).
            $category = $categoryId !== null ? ($categoriesById[$categoryId] ?? null) : null;
            if ($category) {
                $categoryName = $category->name;
            }
        }

        $uploadedBy = null;
        if ($asset->user_id) {
            $uid = (int) $asset->user_id;
            $uploader = $uploadersById[$uid] ?? null;
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

        $previewThumbnailUrl = $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_PREVIEW, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;

        $finalThumbnailUrl = null;
        $thumbnailVersion = null;
        if ($thumbnailStatus === 'completed') {
            $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
            $thumbnailStyle = $asset->thumbnailPathForStyle('medium') ? 'medium' : 'thumb';
            $variant = $thumbnailStyle === 'medium' ? \App\Support\AssetVariant::THUMB_MEDIUM : \App\Support\AssetVariant::THUMB_SMALL;
            $finalThumbnailUrl = $asset->deliveryUrl($variant, \App\Support\DeliveryContext::AUTHENTICATED);
            if ($finalThumbnailUrl && $thumbnailVersion && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
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
            'metadata' => DerivativeFailureUserMessaging::workspaceMetadata($asset->metadata),
            'category' => $categoryName ? ['id' => $categoryId, 'name' => $categoryName] : null,
            'user_id' => $asset->user_id, // For delete-own permission check
            'uploaded_by' => $uploadedBy,
            'preview_thumbnail_url' => $previewThumbnailUrl,
            'final_thumbnail_url' => $finalThumbnailUrl,
            'thumbnail_version' => $thumbnailVersion,
            'thumbnail_url' => $finalThumbnailUrl ?? null,
            'thumbnail_status' => $thumbnailStatus,
            'thumbnail_error' => DerivativeFailureUserMessaging::workspaceThumbnailError($asset->thumbnail_error),
            'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null,
            'preview_url' => null,
            'url' => null,
            'health_status' => $asset->computeHealthStatus($incidentSeverityByAsset[$asset->id] ?? null),
            'type' => $asset->type instanceof AssetType ? $asset->type->value : (string) $asset->type,
        ];
    }

    /**
     * Query param: collection_type — all | asset | deliverable | ai_generated
     */
    private function normalizeCollectionTypeFilter(mixed $value): string
    {
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return 'all';
        }
        if (! is_string($value)) {
            return 'all';
        }
        $v = strtolower(trim($value));
        if ($v === '') {
            return 'all';
        }

        return in_array($v, ['asset', 'deliverable', 'ai_generated', 'all'], true) ? $v : 'all';
    }

    private function applyCollectionAssetTypeFilter(\Illuminate\Database\Eloquent\Builder $query, string $collectionType): void
    {
        if ($collectionType === 'asset') {
            $query->where('assets.type', AssetType::ASSET);
        } elseif ($collectionType === 'deliverable') {
            $query->where('assets.type', AssetType::DELIVERABLE);
        } elseif ($collectionType === 'ai_generated') {
            $query->where('assets.type', AssetType::AI_GENERATED);
        }
    }

    private function resolveFontsCategoryForBrand(Tenant $tenant, Brand $brand): ?Category
    {
        return Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->where('slug', 'fonts')
            ->visible()
            ->first();
    }

    /**
     * Adds Fonts to collection category chips when campaign identity declares Google Fonts (no DAM member required).
     *
     * @param  list<array{id: int, name: string, asset_type: string}>  $filterCategories
     * @return list<array{id: int, name: string, asset_type: string}>
     */
    private function ensureFontsCategoryInCollectionFilterChips(
        Collection $collection,
        Brand $brand,
        Tenant $tenant,
        string $collectionType,
        array $filterCategories,
    ): array {
        if (! in_array($collectionType, ['all', 'asset'], true)) {
            return $filterCategories;
        }

        $fontsCategory = $this->resolveFontsCategoryForBrand($tenant, $brand);
        if (! $fontsCategory) {
            return $filterCategories;
        }

        $existingIds = collect($filterCategories)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (in_array((int) $fontsCategory->id, $existingIds, true)) {
            return $filterCategories;
        }

        $virtual = app(CampaignGoogleFontLibraryEntriesService::class)
            ->virtualAssetsForCollection($brand, $fontsCategory, $collection);
        if ($virtual === []) {
            return $filterCategories;
        }

        $filterCategories[] = [
            'id' => $fontsCategory->id,
            'name' => $fontsCategory->name,
            'asset_type' => $fontsCategory->asset_type instanceof AssetType ? $fontsCategory->asset_type->value : (string) $fontsCategory->asset_type,
        ];
        usort($filterCategories, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return array_values($filterCategories);
    }

    /**
     * Prepend campaign Google font virtual rows (same shape as library Fonts) on collection grid page 1.
     *
     * @param  list<array<string, mixed>>  $mappedAssets
     * @return list<array<string, mixed>>
     */
    private function prependCampaignGoogleFontVirtualRowsForCollection(
        Request $request,
        Collection $collection,
        Tenant $tenant,
        Brand $brand,
        string $collectionType,
        ?int $categoryFilterId,
        ?LengthAwarePaginator $paginator,
        array $mappedAssets,
    ): array {
        if ($paginator === null || $request->boolean('load_more')) {
            return $mappedAssets;
        }
        if ($paginator->currentPage() !== 1) {
            return $mappedAssets;
        }
        if (! in_array($collectionType, ['all', 'asset'], true)) {
            return $mappedAssets;
        }

        $fontsCategory = $this->resolveFontsCategoryForBrand($tenant, $brand);
        if (! $fontsCategory) {
            return $mappedAssets;
        }
        if ($categoryFilterId !== null && $categoryFilterId > 0 && $categoryFilterId !== (int) $fontsCategory->id) {
            return $mappedAssets;
        }

        $virtual = app(CampaignGoogleFontLibraryEntriesService::class)
            ->virtualAssetsForCollection($brand, $fontsCategory, $collection);
        if ($virtual === []) {
            return $mappedAssets;
        }

        return array_merge($virtual, $mappedAssets);
    }

    /**
     * Category filter options: only categories that appear on at least one asset in this collection,
     * respecting the current type filter (all / asset / execution / generative).
     *
     * @return array<int, array{id: int, name: string, asset_type: string}>
     */
    private function categoriesPresentInCollection(
        User $user,
        Collection $collection,
        string $collectionType,
        int $tenantId,
        int $brandId
    ): array {
        $q = $this->collectionAssetQueryService->query($user, $collection);
        $this->applyCollectionAssetTypeFilter($q, $collectionType);

        $ids = $q->pluck('metadata')
            ->map(fn ($m) => is_array($m) ? ($m['category_id'] ?? null) : null)
            ->filter(fn ($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return Category::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->whereIn('id', $ids)
            ->visible()
            ->orderBy('name')
            ->get(['id', 'name', 'asset_type'])
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'asset_type' => $c->asset_type instanceof AssetType ? $c->asset_type->value : (string) $c->asset_type,
            ])
            ->values()
            ->all();
    }

    /**
     * C9.2: Check if collection field is visible for a category.
     *
     * GET /app/collections/field-visibility?category_id={id}
     *
     * Returns whether the collection metadata field is visible for the given category.
     * Uses the same visibility rules as other system metadata fields.
     */
    public function checkFieldVisibility(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $categoryId = $request->query('category_id');

        if (! $tenant || ! $categoryId) {
            // If no category, assume visible (no suppression)
            return response()->json(['visible' => true]);
        }

        $category = Category::where('id', $categoryId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $category) {
            // Category not found, assume visible
            return response()->json(['visible' => true]);
        }

        // Get collection metadata field (if it exists and is not deprecated)
        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->first();

        if (! $collectionField) {
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
