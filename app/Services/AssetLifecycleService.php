<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle transitions for generative editor assets (soft orphaning before delayed purge).
 *
 * Boundaries (do not orphan ineligible rows):
 * - Only {@see AssetType::AI_GENERATED} with {@code metadata.asset_role === generative_layer} participate.
 * - Published assets ({@see Asset::$published_at}) are never marked orphaned — they are user-facing outputs or imports.
 * - Non-active lifecycle rows are left unchanged (idempotent).
 *
 * Orphaning is reconciled by {@see GenerativeCompositionAssetCleanup}; hard delete after grace period runs in
 * {@see \App\Jobs\CleanupOrphanedGenerativeAssetsJob}.
 */
final class AssetLifecycleService
{
    public function isGenerativeLayerAiTarget(Asset $asset): bool
    {
        if ($asset->type !== AssetType::AI_GENERATED) {
            return false;
        }

        $role = $asset->metadata['asset_role'] ?? null;

        return $role === 'generative_layer';
    }

    /**
     * Mark unused generative layer AI assets as orphaned (never uploads / published finals).
     */
    public function markOrphanedIfEligible(Asset $asset): void
    {
        if (! $this->isGenerativeLayerAiTarget($asset)) {
            return;
        }

        if ($asset->published_at !== null) {
            return;
        }

        if (($asset->lifecycle ?? 'active') !== 'active') {
            return;
        }

        $asset->update([
            'lifecycle' => 'orphaned',
        ]);

        Log::info('[AssetLifecycle] Generative layer marked orphaned', [
            'asset_id' => (string) $asset->id,
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'lifecycle' => 'orphaned',
            'event' => 'mark_orphaned_eligible',
        ]);
    }

    /**
     * When a previously orphaned generative asset is referenced again, return it to active.
     */
    public function reactivateIfOrphaned(Asset $asset): void
    {
        if (($asset->lifecycle ?? 'active') !== 'orphaned') {
            return;
        }

        if (! $this->isGenerativeLayerAiTarget($asset)) {
            return;
        }

        $asset->update([
            'lifecycle' => 'active',
        ]);

        Log::info('[AssetLifecycle] Generative layer reactivated from orphaned', [
            'asset_id' => (string) $asset->id,
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'lifecycle' => 'active',
            'event' => 'reactivate_from_orphaned',
        ]);
    }
}
