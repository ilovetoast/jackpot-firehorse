<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Lifecycle\LifecycleResolver;
use Illuminate\Support\Facades\DB;

/**
 * Per-category and total counts for the asset/deliverable library sidebar.
 * Uses driver-safe metadata.category_id casting (MySQL/Postgres/SQLite).
 * Not cached: sidebar counts must match the grid immediately after uploads/recategorization.
 */
class BrandLibraryCategoryCountService
{
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

        return $this->computeCounts(
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
            // Alias must NOT be `category_id`: Asset model has a categoryId() accessor that reads
            // metadata JSON, which is unset on these aggregate rows — so $row->category_id was always null.
            $countRows = (clone $countQuery)
                ->selectRaw("{$cast} as library_category_id, COUNT(*) as aggregate")
                ->groupBy(DB::raw($cast))
                ->get();

            foreach ($countRows as $row) {
                $cid = (int) ($row->library_category_id ?? 0);
                if ($cid > 0) {
                    $assetCounts[$cid] = (int) ($row->aggregate ?? 0);
                }
            }
        }

        return [
            'total' => $totalAssetCount,
            'by_category' => $assetCounts,
        ];
    }
}
