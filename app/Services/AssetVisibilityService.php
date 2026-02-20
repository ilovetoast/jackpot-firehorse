<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Asset;

/**
 * Asset grid visibility diagnostics.
 *
 * Provides detailed "why not visible" and "recommended fix" for assets that
 * fail the default grid visibility check. Uses Asset::isVisibleInGrid() as
 * the canonical visibility check; when false, determines the primary reason
 * and actionable recommendation.
 *
 * Priority order matches user impact: deleted → failed → archived → unpublished → hidden.
 */
class AssetVisibilityService
{
    /**
     * Get visibility status with reason and recommended action.
     *
     * @return array{visible: bool, reason: string|null, recommended_action: string|null, action_key: string|null}
     */
    public function getVisibilityDetail(Asset $asset): array
    {
        if ($asset->isVisibleInGrid()) {
            return [
                'visible' => true,
                'reason' => null,
                'recommended_action' => null,
                'action_key' => null,
            ];
        }

        $metadata = $asset->metadata ?? [];

        if ($asset->deleted_at) {
            return [
                'visible' => false,
                'reason' => 'Asset is deleted',
                'recommended_action' => 'Restore the asset to make it visible again',
                'action_key' => 'restore',
            ];
        }

        // status=FAILED is the only processing-related visibility gate (processing_failed in metadata no longer hides assets)
        if ($asset->status === AssetStatus::FAILED) {
            $failureReason = $metadata['failure_reason'] ?? 'Processing failed';
            return [
                'visible' => false,
                'reason' => $failureReason,
                'recommended_action' => 'Retry Pipeline — clears failure flags and re-runs processing',
                'action_key' => 'retry-pipeline',
            ];
        }

        if ($asset->archived_at) {
            return [
                'visible' => false,
                'reason' => 'Asset is archived',
                'recommended_action' => 'Restore from archive to make it visible',
                'action_key' => null,
            ];
        }

        if (! $asset->published_at) {
            return [
                'visible' => false,
                'reason' => 'Asset is not published',
                'recommended_action' => 'Publish the asset from the brand asset view',
                'action_key' => null,
            ];
        }

        if ($asset->status === AssetStatus::HIDDEN) {
            return [
                'visible' => false,
                'reason' => 'Asset is hidden',
                'recommended_action' => 'Publish or unhide from the brand asset view',
                'action_key' => null,
            ];
        }

        // Category is required for grid visibility (grid filters by metadata->category_id)
        $categoryId = $metadata['category_id'] ?? null;
        if ($categoryId === null || $categoryId === '' || (is_string($categoryId) && strtolower(trim($categoryId)) === 'null')) {
            return [
                'visible' => false,
                'reason' => 'Category not set (metadata.category_id is missing)',
                'recommended_action' => 'Run: php artisan assets:recover-category-id --category=<id> to restore, or re-upload with a category',
                'action_key' => 'recover-category',
            ];
        }

        return [
            'visible' => false,
            'reason' => 'Unknown',
            'recommended_action' => null,
            'action_key' => null,
        ];
    }
}
