<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Aligns AI tag/category candidate queries with the AI Review workspace:
 * brand managers see org-wide queues; contributors with metadata.review_candidates
 * only see suggestions on assets uploaded by someone else.
 */
final class AiReviewSuggestionScopeService
{
    public function __construct(
        private TenantPermissionResolver $resolver
    ) {}

    /**
     * True when the user sees the full brand-wide AI suggestion queues (matches AiReviewController).
     */
    public function canViewBrandWideAiReviewQueues(User $user, Brand $brand): bool
    {
        $isContributor = strtolower((string) $user->getRoleForBrand($brand)) === 'contributor';

        return ! $isContributor && $this->resolver->hasForBrand($user, $brand, 'metadata.suggestions.view');
    }

    /**
     * True when the user may open /api/ai/review (brand-wide or contributor review queue).
     */
    public function canAccessAiReviewApi(User $user, Brand $brand): bool
    {
        if ($this->canViewBrandWideAiReviewQueues($user, $brand)) {
            return true;
        }
        $isContributor = strtolower((string) $user->getRoleForBrand($brand)) === 'contributor';

        return $isContributor && $this->resolver->hasForBrand($user, $brand, 'metadata.review_candidates');
    }

    /**
     * Restrict a query that joins `assets` to the same asset scope as AI Review for this user.
     */
    public function scopeQueryToAiReviewAssetVisibility(Builder $query, User $user, Brand $brand): void
    {
        if ($this->canViewBrandWideAiReviewQueues($user, $brand)) {
            return;
        }
        if (! $this->canAccessAiReviewApi($user, $brand)) {
            $query->whereRaw('0 = 1');

            return;
        }
        $query->where('assets.user_id', '!=', $user->id);
    }
}
