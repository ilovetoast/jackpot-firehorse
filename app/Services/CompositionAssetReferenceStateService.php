<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Composition;
use App\Models\CompositionVersion;
use App\Support\CompositionDocumentAssetIdCollector;

/**
 * Tracks {@code metadata.composition_ref_state} for editor-sourced assets: active, stale, or orphaned.
 *
 * - active: referenced in a composition’s current document, or used as a composition/version thumbnail.
 * - stale: appears only in composition version history (snapshot) for a composition whose current document no longer references it.
 * - orphaned: editor generative/preview asset with no current document, version, or thumbnail reference.
 */
final class CompositionAssetReferenceStateService
{
    public function refreshForAsset(Asset $asset): void
    {
        if ($asset->deleted_at !== null) {
            return;
        }

        if (! $this->shouldTrackCompositionState($asset)) {
            return;
        }

        $state = $this->computeState($asset);
        if ($state === null) {
            return;
        }

        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        if (($meta['composition_ref_state'] ?? null) === $state) {
            return;
        }
        $meta['composition_ref_state'] = $state;
        $asset->update(['metadata' => $meta]);
    }

    /**
     * @param  array<int, string>  $assetIds
     */
    public function refreshForAssetIds(array $assetIds, int $tenantId, int $brandId): void
    {
        foreach (array_unique($assetIds) as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }
            $asset = Asset::query()
                ->whereKey($id)
                ->where('tenant_id', $tenantId)
                ->where('brand_id', $brandId)
                ->first();
            if ($asset !== null) {
                $this->refreshForAsset($asset);
            }
        }
    }

    private function shouldTrackCompositionState(Asset $asset): bool
    {
        $src = (string) ($asset->source ?? '');
        $role = $asset->metadata['asset_role'] ?? null;

        return $src === 'generative_editor'
            || $src === 'composition_editor'
            || $role === 'generative_layer'
            || $role === 'composition_thumbnail';
    }

    private function computeState(Asset $asset): ?string
    {
        $id = $asset->id;
        $tid = (int) $asset->tenant_id;
        $bid = (int) $asset->brand_id;

        if ($this->isThumbnailForAnyComposition($id, $tid, $bid)) {
            return 'active';
        }

        if ($this->isIdInAnyCurrentCompositionDocument($id, $tid, $bid)) {
            return 'active';
        }

        if ($this->isStaleVersionOnlyReference($id, $tid, $bid)) {
            return 'stale';
        }

        // Generative output may exist on disk before the composition document is autosaved with resultAssetId.
        if ($this->isPendingCompositionSession($asset)) {
            return 'active';
        }

        return 'orphaned';
    }

    /**
     * Layer output tied to a composition id but not yet present in any persisted document JSON (pre-autosave).
     */
    private function isPendingCompositionSession(Asset $asset): bool
    {
        if (($asset->lifecycle ?? 'active') !== 'active') {
            return false;
        }
        if (($asset->metadata['asset_role'] ?? null) !== 'generative_layer') {
            return false;
        }
        $cid = $asset->metadata['composition_id'] ?? null;
        if ($cid === null || $cid === '' || $cid === 'null') {
            return false;
        }
        $cidInt = is_numeric($cid) ? (int) $cid : 0;
        if ($cidInt < 1) {
            return false;
        }
        if (! Composition::query()
            ->where('id', $cidInt)
            ->where('tenant_id', $asset->tenant_id)
            ->where('brand_id', $asset->brand_id)
            ->exists()) {
            return false;
        }

        $id = $asset->id;
        $tid = (int) $asset->tenant_id;
        $bid = (int) $asset->brand_id;

        return ! $this->isIdInAnyCurrentCompositionDocument($id, $tid, $bid)
            && ! $this->assetIdAppearsInAnyVersionJson($id, $tid, $bid);
    }

    private function assetIdAppearsInAnyVersionJson(string $assetId, int $tenantId, int $brandId): bool
    {
        $rows = CompositionVersion::query()
            ->whereHas('composition', function ($q) use ($tenantId, $brandId) {
                $q->where('tenant_id', $tenantId)->where('brand_id', $brandId);
            })
            ->get(['document_json']);

        foreach ($rows as $vr) {
            $vdoc = is_array($vr->document_json) ? $vr->document_json : [];
            if ($this->documentContainsAssetId($vdoc, $assetId)) {
                return true;
            }
        }

        return false;
    }

    private function isThumbnailForAnyComposition(string $assetId, int $tenantId, int $brandId): bool
    {
        if (Composition::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('thumbnail_asset_id', $assetId)
            ->exists()) {
            return true;
        }

        return CompositionVersion::query()
            ->whereHas('composition', function ($q) use ($tenantId, $brandId) {
                $q->where('tenant_id', $tenantId)->where('brand_id', $brandId);
            })
            ->where('thumbnail_asset_id', $assetId)
            ->exists();
    }

    private function isIdInAnyCurrentCompositionDocument(string $assetId, int $tenantId, int $brandId): bool
    {
        $rows = Composition::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->get(['id', 'document_json']);

        foreach ($rows as $row) {
            $doc = is_array($row->document_json) ? $row->document_json : [];
            if ($this->documentContainsAssetId($doc, $assetId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Asset appears in at least one saved version snapshot for a composition, but not in that composition’s current document.
     */
    private function isStaleVersionOnlyReference(string $assetId, int $tenantId, int $brandId): bool
    {
        $versionRows = CompositionVersion::query()
            ->whereHas('composition', function ($q) use ($tenantId, $brandId) {
                $q->where('tenant_id', $tenantId)->where('brand_id', $brandId);
            })
            ->get(['composition_id', 'document_json']);

        $foundInVersion = false;
        foreach ($versionRows as $vr) {
            $vdoc = is_array($vr->document_json) ? $vr->document_json : [];
            if (! $this->documentContainsAssetId($vdoc, $assetId)) {
                continue;
            }
            $foundInVersion = true;
            $comp = Composition::query()
                ->where('id', $vr->composition_id)
                ->where('tenant_id', $tenantId)
                ->where('brand_id', $brandId)
                ->first();
            if ($comp === null) {
                continue;
            }
            $cur = is_array($comp->document_json) ? $comp->document_json : [];
            if ($this->documentContainsAssetId($cur, $assetId)) {
                return false;
            }
        }

        return $foundInVersion;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function documentContainsAssetId(array $document, string $assetId): bool
    {
        $ids = CompositionDocumentAssetIdCollector::collectReferencedAssetIds($document);

        return in_array($assetId, $ids, true);
    }
}
