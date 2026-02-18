<?php

namespace App\Services\Assets;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Services\SystemIncidentService;
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
                app(SystemIncidentService::class)->recordIfNotExists([
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

        return [
            'updated' => !empty($changes),
            'changes' => $changes,
        ];
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
}
