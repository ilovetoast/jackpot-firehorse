<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Lifecycle\LifecycleResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Per-category and total counts for the asset/deliverable library sidebar.
 * Uses driver-safe metadata.category_id casting (MySQL/Postgres/SQLite) and short-lived cache.
 */
class BrandLibraryCategoryCountService
{
    private const CACHE_TTL_SECONDS = 120;

    public function __construct(
        protected LifecycleResolver $lifecycleResolver
    ) {}

    /**
     * @return array{total: int, by_category: array<int, int>}
     */
    public function getCounts(
        Tenant $tenant,
        Brand $brand,
        User $user,
        array $viewableCategoryIds,
        array $categoryIdsForGrouping,
        ?string $normalizedLifecycle,
        bool $isTrashView,
        AssetType $assetType,
        bool $normalIntakeOnly,
        bool $explicitSoftDeleteWhenNotTrash
    ): array {
        if (empty($viewableCategoryIds)) {
            return ['total' => 0, 'by_category' => []];
        }

        $cacheKey = $this->cacheKey(
            $tenant->id,
            $brand->id,
            $user->id,
            $normalizedLifecycle,
            $isTrashView,
            $assetType,
            $normalIntakeOnly,
            $explicitSoftDeleteWhenNotTrash,
            $viewableCategoryIds
        );

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeCounts(
                $tenant,
                $brand,
                $user,
                $viewableCategoryIds,
                $categoryIdsForGrouping,
                $normalizedLifecycle,
                $isTrashView,
                $assetType,
                $normalIntakeOnly,
                $explicitSoftDeleteWhenNotTrash
            )
        );
    }

    /**
     * @return array{total: int, by_category: array<int, int>}
     */
    private function computeCounts(
        Tenant $tenant,
        Brand $brand,
        User $user,
        array $viewableCategoryIds,
        array $categoryIdsForGrouping,
        ?string $normalizedLifecycle,
        bool $isTrashView,
        AssetType $assetType,
        bool $normalIntakeOnly,
        bool $explicitSoftDeleteWhenNotTrash
    ): array {
        $cast = Asset::categoryIdMetadataCastExpression();

        $countQuery = Asset::query()
            ->when($normalIntakeOnly, fn ($q) => $q->normalIntakeOnly())
            ->excludeBuilderStaged()
            ->when($isTrashView, fn ($q) => $q->onlyTrashed(), function ($q) use ($explicitSoftDeleteWhenNotTrash) {
                if ($explicitSoftDeleteWhenNotTrash) {
                    $q->whereNull('deleted_at');
                }
            })
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', $assetType)
            ->whereNotNull('metadata')
            ->whereIn(DB::raw($cast), array_map('intval', $viewableCategoryIds));

        $this->lifecycleResolver->apply(
            $countQuery,
            $normalizedLifecycle,
            $user,
            $tenant,
            $brand
        );

        $totalAssetCount = (clone $countQuery)->count();
        $assetCounts = [];

        if (! empty($categoryIdsForGrouping)) {
            $countRows = (clone $countQuery)
                ->selectRaw("{$cast} as category_id, COUNT(*) as count")
                ->groupBy(DB::raw($cast))
                ->get();

            foreach ($countRows as $row) {
                $cid = (int) ($row->category_id ?? 0);
                if ($cid > 0) {
                    $assetCounts[$cid] = (int) ($row->count ?? 0);
                }
            }
        }

        return [
            'total' => $totalAssetCount,
            'by_category' => $assetCounts,
        ];
    }

    private function cacheKey(
        int $tenantId,
        int $brandId,
        int $userId,
        ?string $normalizedLifecycle,
        bool $isTrashView,
        AssetType $assetType,
        bool $normalIntakeOnly,
        bool $explicitSoftDeleteWhenNotTrash,
        array $viewableCategoryIds
    ): string {
        $ids = array_map('intval', $viewableCategoryIds);
        sort($ids);
        $idsHash = hash('sha256', json_encode($ids));

        $lifecyclePart = $normalizedLifecycle ?? 'default';

        return sprintf(
            'brand_category_sidebar_counts:%d:%d:%d:%s:%s:%s:%s:%s:%s',
            $tenantId,
            $brandId,
            $userId,
            $lifecyclePart,
            $isTrashView ? '1' : '0',
            $assetType->value,
            $normalIntakeOnly ? '1' : '0',
            $explicitSoftDeleteWhenNotTrash ? '1' : '0',
            $idsHash
        );
    }
}
