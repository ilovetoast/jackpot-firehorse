<?php

namespace App\Services\Assets;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Services\Reliability\ReliabilityEngine;
use Illuminate\Support\Facades\Log;

/**
 * Asset State Reconciliation Service
 *
 * Prevents invalid asset state combinations. Only promotes forward, never downgrades.
 */
class AssetStateReconciliationService
{
    /**
     * Reconcile asset state to fix invalid combinations.
     *
     * @return array{updated: bool, changes: array}
     */
    public function reconcile(Asset $asset): array
    {
        $changes = [];
        $metadata = $asset->metadata ?? [];

        // Rule 1 — Completed Pipeline Must Finalize Analysis
        if (
            isset($metadata['pipeline_completed_at'])
            && ($metadata['thumbnails_generated'] ?? false) === true
        ) {
            $promotions = $this->applyRule1($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 2 — Timeout But Later Success
        if (
            ($metadata['thumbnail_timeout'] ?? false) === true
            && ($metadata['thumbnails_generated'] ?? false) === true
        ) {
            $promotions = $this->applyRule2($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 3 — Uploading With Extracted Metadata
        if (
            ($metadata['metadata_extracted'] ?? false) === true
            && ($asset->analysis_status ?? 'uploading') === 'uploading'
        ) {
            $promotions = $this->applyRule3($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 4 — Promotion Failed + Thumbnails Missing: record critical incident
        if (($asset->analysis_status ?? 'uploading') === 'promotion_failed') {
            $hasThumbnails = (bool) data_get($asset->metadata, 'thumbnails.large.path');
            if (!$hasThumbnails) {
                app(ReliabilityEngine::class)->report([
                    'source_type' => 'asset',
                    'source_id' => $asset->id,
                    'tenant_id' => $asset->tenant_id,
                    'severity' => 'critical',
                    'title' => 'Promotion failed and thumbnails missing',
                    'retryable' => true,
                    'unique_signature' => "promotion_failed_no_thumbnails:{$asset->id}",
                ]);
            }
        }

        // Rule 5 — Pipeline outputs present but analysis_status stuck (e.g. post-lifecycle migration)
        // Fixes assets that completed pipeline but weren't backfilled (migration only touched brand_compliance_scores)
        if (($asset->analysis_status ?? 'uploading') !== 'complete') {
            $promotions = $this->applyRule5($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 5b — Non-image assets stuck at generating_embedding (PDF, video, etc.)
        // Embedding job is never dispatched for non-images; nothing advances status. Fix stuck assets.
        if (($asset->analysis_status ?? 'uploading') === 'generating_embedding') {
            $promotions = $this->applyRule5b($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 6 — Visual metadata now ready: auto-resolve "Expected visual metadata missing" incident
        // Prevents incident from lingering in Ops center after backfill or recovery
        if ($asset->visualMetadataReady()) {
            $this->resolveVisualMetadataIncidentIfExists($asset);
        }

        return [
            'updated' => !empty($changes),
            'changes' => $changes,
        ];
    }

    /**
     * Resolve "Expected visual metadata missing" incident when asset now has visualMetadataReady.
     * Only resolves this specific incident type — does not touch unrelated incidents.
     */
    protected function resolveVisualMetadataIncidentIfExists(Asset $asset): void
    {
        $incident = \App\Models\SystemIncident::where('source_type', 'asset')
            ->where('source_id', $asset->id)
            ->whereNull('resolved_at')
            ->where('title', 'Expected visual metadata missing')
            ->first();

        if ($incident) {
            $incident->update([
                'resolved_at' => now(),
                'auto_resolved' => true,
                'metadata' => array_merge($incident->metadata ?? [], ['auto_recovered' => true]),
            ]);
            Log::info('[AssetStateReconciliationService] Auto-resolved visual metadata incident', [
                'asset_id' => $asset->id,
                'incident_id' => $incident->id,
            ]);
        }
    }

    /**
     * Rule 1: pipeline_completed_at + thumbnails_generated → analysis_status=complete, thumbnail_status=completed
     */
    protected function applyRule1(Asset $asset): array
    {
        $changes = [];
        $updates = [];

        if (($asset->analysis_status ?? 'uploading') !== 'complete') {
            $updates['analysis_status'] = 'complete';
            $changes[] = 'analysis_status → complete';
        }

        if (($asset->thumbnail_status?->value ?? null) !== ThumbnailStatus::COMPLETED->value) {
            $updates['thumbnail_status'] = ThumbnailStatus::COMPLETED;
            $updates['thumbnail_error'] = null;
            $updates['thumbnail_started_at'] = null;
            $changes[] = 'thumbnail_status → completed';
        }

        if (!empty($updates)) {
            $asset->update($updates);
            Log::info('[AssetStateReconciliationService] Rule 1 applied', [
                'asset_id' => $asset->id,
                'changes' => $changes,
            ]);
        }

        return $changes;
    }

    /**
     * Rule 2: thumbnail_timeout + thumbnails_generated → clear timeout, thumbnail_status=completed
     */
    protected function applyRule2(Asset $asset): array
    {
        $changes = [];
        $metadata = $asset->metadata ?? [];

        unset($metadata['thumbnail_timeout']);
        unset($metadata['thumbnail_timeout_reason']);

        $updates = [
            'metadata' => $metadata,
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'thumbnail_error' => null,
            'thumbnail_started_at' => null,
        ];

        $asset->update($updates);
        $changes[] = 'cleared thumbnail_timeout, thumbnail_status → completed';

        Log::info('[AssetStateReconciliationService] Rule 2 applied', [
            'asset_id' => $asset->id,
        ]);

        return $changes;
    }

    /**
     * Rule 3: metadata_extracted + analysis_status=uploading → analysis_status=generating_thumbnails
     */
    protected function applyRule3(Asset $asset): array
    {
        $changes = [];

        if (($asset->analysis_status ?? 'uploading') === 'uploading') {
            $asset->update(['analysis_status' => 'generating_thumbnails']);
            $changes[] = 'analysis_status → generating_thumbnails';

            Log::info('[AssetStateReconciliationService] Rule 3 applied', [
                'asset_id' => $asset->id,
            ]);
        }

        return $changes;
    }

    /**
     * Rule 5: All pipeline outputs present → analysis_status=complete
     *
     * Fixes assets that completed the pipeline but analysis_status was never set
     * (e.g. post analysis_status migration, assets without brand_compliance_scores).
     */
    protected function applyRule5(Asset $asset): array
    {
        $hasDominantColors = ! empty(data_get($asset->metadata, 'dominant_colors'))
            || ! empty(data_get($asset->metadata, 'fields.dominant_colors'));
        $hasDominantHueGroup = ! empty($asset->dominant_hue_group);
        $hasEmbedding = $asset->embedding()->exists();
        $thumbEnum = $asset->thumbnail_status instanceof ThumbnailStatus ? $asset->thumbnail_status : null;
        $thumbnailDone = $thumbEnum === ThumbnailStatus::COMPLETED || $thumbEnum === ThumbnailStatus::SKIPPED;

        if (!$hasDominantColors || !$hasDominantHueGroup || !$hasEmbedding || !$thumbnailDone) {
            return [];
        }

        $asset->update(['analysis_status' => 'complete']);
        Log::info('[AssetStateReconciliationService] Rule 5 applied (pipeline outputs present)', [
            'asset_id' => $asset->id,
        ]);

        return ['analysis_status → complete'];
    }

    /**
     * Rule 5b: Non-image assets stuck at generating_embedding.
     *
     * PDF/video/etc. never get GenerateAssetEmbeddingJob dispatched, so status stays stuck.
     * If pipeline completed (thumbnails, metadata, etc.), advance to complete.
     */
    protected function applyRule5b(Asset $asset): array
    {
        if (\App\Services\ImageEmbeddingService::isImageMimeType($asset->mime_type ?? '')) {
            return [];
        }
        $metadata = $asset->metadata ?? [];
        $thumbEnum = $asset->thumbnail_status instanceof ThumbnailStatus ? $asset->thumbnail_status : null;
        $thumbnailDone = $thumbEnum === ThumbnailStatus::COMPLETED || $thumbEnum === ThumbnailStatus::SKIPPED;
        $pipelineDone = isset($metadata['pipeline_completed_at'])
            || ($thumbnailDone && ($metadata['ai_tagging_completed'] ?? false) && ($metadata['metadata_extracted'] ?? false));

        if ($pipelineDone) {
            $asset->update(['analysis_status' => 'complete']);
            Log::info('[AssetStateReconciliationService] Rule 5b applied (non-image stuck at generating_embedding)', [
                'asset_id' => $asset->id,
            ]);
            return ['analysis_status → complete'];
        }
        return [];
    }
}
