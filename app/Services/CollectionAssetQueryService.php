<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Collection Asset Query Service (Collections C3)
 *
 * Enforces that querying assets by collection acts purely as a filtering layer
 * and never grants asset access. Use this service whenever building an asset
 * query scoped to a collection.
 *
 * Rules:
 * - Resolves collection and validates user can view it (CollectionPolicy::view).
 * - Restricts results to assets belonging to the collection's brand.
 * - Applies same visibility constraints as normal asset grid (VISIBLE, not deleted).
 * - Collections do not grant asset access; user must already have brand access.
 */
class CollectionAssetQueryService
{
    /**
     * Build an asset query scoped to a collection.
     *
     * Resolves the collection, authorizes the user can view it, then returns
     * a query restricted to: collection's brand, assets in the collection
     * (asset_collections pivot), and existing visibility rules (status VISIBLE,
     * not soft-deleted). Does not use collection_members.
     *
     * @param  int|Collection  $collection  Collection ID or model
     * @return Builder<Asset>
     *
     * @throws AuthorizationException If user cannot view the collection
     */
    public function query(User $user, int|Collection $collection): Builder
    {
        $collection = $collection instanceof Collection
            ? $collection
            : Collection::findOrFail($collection);

        Gate::forUser($user)->authorize('view', $collection);

        return Asset::query()
            ->where('tenant_id', $collection->tenant_id)
            ->where('brand_id', $collection->brand_id)
            ->where('type', AssetType::ASSET)
            ->where('status', AssetStatus::VISIBLE)
            ->whereNull('deleted_at')
            ->whereIn('id', function ($q) use ($collection) {
                $q->select('asset_id')
                    ->from('asset_collections')
                    ->where('collection_id', $collection->id);
            });
    }

    /**
     * Build an asset query for a public collection (C8). No user; no Gate.
     * Extra guards: VISIBLE, not deleted, not archived, published, not expired.
     *
     * IMPORTANT: Asset eligibility (published, non-archived) is enforced here. Do not bypass this query for collections or downloads.
     *
     * @return Builder<Asset>
     */
    public function queryPublic(Collection $collection): Builder
    {
        return Asset::query()
            ->where('tenant_id', $collection->tenant_id)
            ->where('brand_id', $collection->brand_id)
            ->where('type', AssetType::ASSET)
            ->where('status', AssetStatus::VISIBLE)
            ->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->whereNotNull('published_at') // D6.1: Only published assets eligible for public collection downloads
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereIn('id', function ($q) use ($collection) {
                $q->select('asset_id')
                    ->from('asset_collections')
                    ->where('collection_id', $collection->id);
            });
    }
}
