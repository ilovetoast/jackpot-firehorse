<?php

namespace App\Services\Reliability;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\SystemIncident;
use App\Support\ThumbnailMetadata;

/**
 * Human-readable explanation for Operations Center: why an incident is still open and what to do next.
 * Heuristics are best-effort and based on current {@see Asset} / version state when the page loads.
 */
class IncidentPlaybookService
{
    /**
     * @return array{why: string, action: string}
     */
    public function summarize(SystemIncident $incident, ?Asset $asset): array
    {
        if ($incident->source_type !== 'asset' || ! $incident->source_id) {
            return [
                'why' => 'This incident is not tied to a single asset row. Use the title/message and related jobs to triage.',
                'action' => 'Attempt Repair if available, open Failed Jobs / Horizon, or resolve when the underlying system issue is fixed.',
            ];
        }

        if (! $asset) {
            return [
                'why' => 'The asset ID on this incident no longer exists (deleted) or could not be loaded.',
                'action' => 'Resolve (Manual) to clear the incident, or restore the asset if removal was accidental.',
            ];
        }

        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        $version = $asset->currentVersion;
        $vMeta = $version && is_array($version->metadata) ? $version->metadata : [];

        $pipelineComplete = ! empty($meta['pipeline_completed_at']);
        $analysis = (string) ($asset->analysis_status ?? 'uploading');
        $exhausted = (bool) data_get($incident->metadata, 'auto_repair_exhausted');
        $title = strtolower((string) ($incident->title ?? ''));

        if ($pipelineComplete && $analysis === 'complete') {
            return [
                'why' => 'The asset already has `pipeline_completed_at` and analysis is complete; this incident is stale.',
                'action' => 'Resolve (Manual) to clear the row.',
            ];
        }

        $thumbsComplete = $this->thumbnailsLookComplete($meta, $vMeta, $asset);
        $preferredFailed = (data_get($meta, 'thumbnail_modes_status.preferred') === 'failed')
            || ! empty($meta['preferred_thumbnail_error']);

        $versionStuck = $version
            && $version->pipeline_status !== 'complete'
            && ($vMeta['processing_started'] ?? false) === true;

        // Studio / pipeline-finalization mismatch (most common confusion for "stalled" + thumbs visible)
        if ($thumbsComplete && ! $pipelineComplete) {
            $why = 'Thumbnails and metadata exist, but finalization did not run: there is no `metadata.pipeline_completed_at`. '
                .'The incident title often reflects a stale `analysis_status` (e.g. `generating_thumbnails`), not missing standard thumb sizes.';
            if ($preferredFailed) {
                $why .= ' The optional **preferred** crop step failed; core sizes (thumb/medium/large) can still be present.';
            }
            if ($versionStuck) {
                $why .= ' Current version is still `pipeline_status='.$version->pipeline_status.'` with processing started — `FinalizeAssetJob` needs the version gate set to `complete`.';
            }

            $action = 'Deploy the latest hotfix (thumbnail idempotent skip + Rule 7b), keep **queue workers / Horizon** running, then click **Attempt Repair** once per asset. '
                .'Use admin **Retry pipeline** if your build exposes it.';
            if ($exhausted) {
                $action = '**Auto-repair is off** (attempt cap reached). '.$action.' You can still use Attempt Repair manually.';
            }

            return ['why' => $why, 'action' => $action];
        }

        if (str_contains($title, 'thumbnail') && str_contains($title, 'stall')) {
            return [
                'why' => 'Watchdog saw `analysis_status` stuck in an early stage for more than ~10 minutes (`'.$analysis.'`). Thumbnail raster work may still be in progress or blocked.',
                'action' => 'Confirm **Horizon/queue workers** are up, check **Failed Jobs** for `GenerateThumbnailsJob`, then **Attempt Repair**. Verify FFmpeg/S3 on workers for video.',
            ];
        }

        if (str_contains($title, 'uploading')) {
            return [
                'why' => 'Watchdog reported the asset stuck while `analysis_status` was `uploading` (or similar) for an extended time.',
                'action' => 'Run **Attempt Repair** with workers running. If the asset already has thumbs, the real issue is likely pipeline finalization — see deployment notes for `ProcessAssetJob` + Rule 7.',
            ];
        }

        if ($exhausted) {
            return [
                'why' => 'Scheduled auto-repair stopped after reaching the maximum attempt count for this incident.',
                'action' => 'Use **Attempt Repair** manually after confirming workers and code; **Resolve (Manual)** once the asset is healthy.',
            ];
        }

        return [
            'why' => (string) ($incident->message ?: 'See incident title; open the asset admin view for Pipeline / Failed Jobs.'),
            'action' => '**Attempt Repair** (workers must be running) → re-check the asset → **Create ticket** or **Resolve** as appropriate.',
        ];
    }

    /**
     * @param  array<string, mixed>  $assetMeta
     * @param  array<string, mixed>  $versionMeta
     */
    protected function thumbnailsLookComplete(array $assetMeta, array $versionMeta, Asset $asset): bool
    {
        if (! empty($assetMeta['thumbnails_generated'])) {
            return true;
        }
        if (in_array($asset->thumbnail_status, [ThumbnailStatus::COMPLETED, ThumbnailStatus::SKIPPED], true)) {
            return true;
        }
        if (ThumbnailMetadata::hasThumb($assetMeta)) {
            return true;
        }
        if (ThumbnailMetadata::hasThumb($versionMeta)) {
            return true;
        }

        return false;
    }
}
