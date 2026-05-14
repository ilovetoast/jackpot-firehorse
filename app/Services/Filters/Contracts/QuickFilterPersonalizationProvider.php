<?php

namespace App\Services\Filters\Contracts;

use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Models\User;

/**
 * Future seam (Phase 6+) for user-pinned / favorite / recently-used quick
 * filters and role-based overrides. Phase 2 binds
 * {@see \App\Services\Filters\Personalization\NullQuickFilterPersonalizationProvider}
 * — every method returns an empty list. Phase 6 will swap in a real
 * implementation backed by user metadata / events / preferences.
 *
 * Design rules:
 *   - Returns a list of metadata_field_ids (NOT MetadataField models). The
 *     assignment + facet services already know how to hydrate. Keeps the
 *     seam cheap and trivially serialisable for future API responses.
 *   - Returns are tenant-scoped for safety, even when the conceptual data
 *     might be cross-tenant (e.g. a global recent list could leak filter
 *     existence). Implementations MUST scope by tenant.
 *   - Folder-scoped methods return filters relevant to that folder; the
 *     caller is responsible for layering them onto the assignment list.
 *   - Implementations are free to return an empty list when they have no
 *     signal yet — callers must NOT treat that as an error.
 */
interface QuickFilterPersonalizationProvider
{
    /**
     * @return list<int> metadata_field_ids the user has explicitly pinned.
     */
    public function getPinnedFilterIds(User $user, Tenant $tenant, ?Category $folder = null): array;

    /**
     * @return list<int> metadata_field_ids the user has recently interacted
     *                   with as filters, most-recent first.
     */
    public function getRecentlyUsedFilterIds(
        User $user,
        Tenant $tenant,
        ?Category $folder = null,
        int $limit = 10,
    ): array;

    /**
     * Role-based default overrides — e.g. "agency contractors always see Brand
     * as a quick filter on Photography". Returns a list scoped to the user's
     * role(s) inside the tenant. The empty list means "no role override".
     *
     * @return list<int> metadata_field_ids surfaced by the user's role.
     */
    public function getRoleDefaultFilterIds(
        User $user,
        Tenant $tenant,
        ?Category $folder = null,
    ): array;

    /**
     * Future hook for "favorite" filters distinct from pinned (Phase 6 may
     * choose to implement only one of these, but the interface keeps both
     * shapes available so call sites do not have to change later).
     *
     * @return list<int>
     */
    public function getFavoriteFilterIds(
        User $user,
        Tenant $tenant,
        ?Category $folder = null,
    ): array;
}
