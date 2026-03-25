<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;

/**
 * Lifecycle transitions for generative editor assets (soft orphaning before delayed purge).
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
    }
}
