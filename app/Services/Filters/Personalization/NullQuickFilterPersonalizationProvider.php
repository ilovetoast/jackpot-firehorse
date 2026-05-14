<?php

namespace App\Services\Filters\Personalization;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Filters\Contracts\QuickFilterPersonalizationProvider;

/**
 * Phase 2 default {@see QuickFilterPersonalizationProvider}.
 *
 * Returns an empty list for every query. Bound as the default implementation
 * in {@see \App\Providers\AppServiceProvider}. Phase 6 swaps this for a real
 * implementation; no other code changes.
 */
class NullQuickFilterPersonalizationProvider implements QuickFilterPersonalizationProvider
{
    public function getPinnedFilterIds(User $user, Tenant $tenant, ?Category $folder = null): array
    {
        return [];
    }

    public function getRecentlyUsedFilterIds(
        User $user,
        Tenant $tenant,
        ?Category $folder = null,
        int $limit = 10,
    ): array {
        return [];
    }

    public function getRoleDefaultFilterIds(
        User $user,
        Tenant $tenant,
        ?Category $folder = null,
    ): array {
        return [];
    }

    public function getFavoriteFilterIds(
        User $user,
        Tenant $tenant,
        ?Category $folder = null,
    ): array {
        return [];
    }
}
