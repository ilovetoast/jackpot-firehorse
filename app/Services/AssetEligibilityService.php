<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * D6.1 — Asset Eligibility Enforcement
 *
 * Canonical rule: An asset is eligible for collections, internal downloads, and public
 * collection downloads ONLY IF: archived_at IS NULL AND published_at IS NOT NULL
 * (and visible to the current scope).
 *
 * IMPORTANT: Asset eligibility (published, non-archived) is enforced here.
 * Do not bypass this query for collections or downloads.
 */
class AssetEligibilityService
{
    /**
     * Base eligibility filter: non-archived, published.
     * Use when building scoped queries (tenant/brand/collection).
     *
     * @return Builder<Asset>
     */
    public function baseEligibleQuery(): Builder
    {
        return Asset::query()
            ->whereNull('archived_at')
            ->whereNotNull('published_at');
    }

    /**
     * Query for assets eligible to be added to collections.
     *
     * @return Builder<Asset>
     */
    public function eligibleForCollections(): Builder
    {
        return $this->baseEligibleQuery();
    }

    /**
     * Query for assets eligible for internal downloads (bucket, create download).
     *
     * @return Builder<Asset>
     */
    public function eligibleForDownloads(): Builder
    {
        return $this->baseEligibleQuery();
    }

    /**
     * Query for assets eligible for public collection downloads.
     *
     * @return Builder<Asset>
     */
    public function eligibleForPublic(): Builder
    {
        return $this->baseEligibleQuery();
    }

    /**
     * Check if a single asset is eligible for collections.
     */
    public function isEligibleForCollections(Asset $asset): bool
    {
        return $asset->archived_at === null && $asset->published_at !== null;
    }

    /**
     * Check if a single asset is eligible for downloads.
     */
    public function isEligibleForDownloads(Asset $asset): bool
    {
        return $asset->archived_at === null && $asset->published_at !== null;
    }

    /**
     * D10.1: Check if asset is eligible for download landing background (brand-level).
     * Must: belong to brand (caller enforces), be eligible for public display, be image, width >= 1920, height >= 1080.
     */
    public function isEligibleForDownloadBackground(Asset $asset): bool
    {
        if (! ($asset->archived_at === null && $asset->published_at !== null)) {
            return false;
        }

        $width = null;
        $height = null;
        $metadata = $asset->metadata ?? [];
        if (isset($metadata['image_width'], $metadata['image_height'])) {
            $width = (int) $metadata['image_width'];
            $height = (int) $metadata['image_height'];
        } elseif ($asset->video_width !== null && $asset->video_height !== null) {
            $width = (int) $asset->video_width;
            $height = (int) $asset->video_height;
        } elseif (isset($metadata['dimensions']) && is_string($metadata['dimensions'])) {
            if (preg_match('/^(\d+)\s*[x×]\s*(\d+)$/i', trim($metadata['dimensions']), $m)) {
                $width = (int) $m[1];
                $height = (int) $m[2];
            }
        }

        if ($width === null || $height === null) {
            return false;
        }

        return $width >= 1920 && $height >= 1080;
    }

    /**
     * Filter asset IDs to only those that are eligible (for the given query scope).
     * Optionally log filtered count for observability.
     *
     * @param  array<string|int>  $ids
     * @return array<string|int>
     */
    public function filterIdsToEligible(array $ids, Builder $eligibleQuery, ?string $context = null, ?int $tenantId = null): array
    {
        if (empty($ids)) {
            return [];
        }

        $eligibleIds = (clone $eligibleQuery)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if ($context !== null && count($eligibleIds) !== count($ids)) {
            Log::info('asset.eligibility.filtered', [
                'context' => $context,
                'tenant_id' => $tenantId,
                'requested' => count($ids),
                'accepted' => count($eligibleIds),
            ]);
        }

        return array_values($eligibleIds);
    }
}
