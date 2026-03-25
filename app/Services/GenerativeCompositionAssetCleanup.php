<?php

namespace App\Services;

use App\Models\Asset;

/**
 * When composition JSON changes, orphan generative layer AI assets that are no longer referenced
 * anywhere in the brand; reactivate if they are referenced again.
 */
final class GenerativeCompositionAssetCleanup
{
    public function __construct(
        protected AssetUsageService $usage,
        protected AssetLifecycleService $lifecycle
    ) {}

    /**
     * @param  array<string, mixed>  $oldDocument
     * @param  array<string, mixed>  $newDocument
     */
    public function afterDocumentReplaced(int $tenantId, int $brandId, array $oldDocument, array $newDocument): void
    {
        $oldIds = $this->collectReferencedAssetIds($oldDocument);
        $newIds = $this->collectReferencedAssetIds($newDocument);
        $removed = array_values(array_diff($oldIds, $newIds));

        foreach ($removed as $assetId) {
            $asset = $this->findScopedAsset($assetId, $tenantId, $brandId);
            if ($asset === null) {
                continue;
            }
            if (! $this->usage->isAssetReferencedInCompositions($assetId, $tenantId, $brandId)) {
                $this->lifecycle->markOrphanedIfEligible($asset);
            }
        }

        foreach ($newIds as $assetId) {
            $asset = $this->findScopedAsset($assetId, $tenantId, $brandId);
            if ($asset === null) {
                continue;
            }
            $this->lifecycle->reactivateIfOrphaned($asset);
        }
    }

    /**
     * After a composition row is deleted — layer references are gone from DB.
     *
     * @param  array<string, mixed>  $documentJson
     */
    public function afterCompositionRemoved(int $tenantId, int $brandId, array $documentJson): void
    {
        foreach ($this->collectReferencedAssetIds($documentJson) as $assetId) {
            $asset = $this->findScopedAsset($assetId, $tenantId, $brandId);
            if ($asset === null) {
                continue;
            }
            if (! $this->usage->isAssetReferencedInCompositions($assetId, $tenantId, $brandId)) {
                $this->lifecycle->markOrphanedIfEligible($asset);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    public function collectReferencedAssetIds(array $document): array
    {
        $ids = [];
        foreach ($document['layers'] ?? [] as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (! empty($layer['assetId']) && is_string($layer['assetId'])) {
                $ids[] = $layer['assetId'];
            }
            if (! empty($layer['resultAssetId']) && is_string($layer['resultAssetId'])) {
                $ids[] = $layer['resultAssetId'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function findScopedAsset(string $assetId, int $tenantId, int $brandId): ?Asset
    {
        return Asset::query()
            ->whereKey($assetId)
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->first();
    }
}
