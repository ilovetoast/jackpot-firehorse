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
    /** @var string Incident row can be closed after spot-check; no missing raster / obvious asset defect. */
    public const RESOLVE_MANUAL_OK = 'manual_ok';

    /** @var string Thumbnail failed, missing, or other signal that the asset (or jobs) may still be broken. */
    public const RESOLVE_FIX_ASSET_FIRST = 'fix_first';

    /** @var string Non-asset, unknown, or not enough to classify. */
    public const RESOLVE_NEUTRAL = 'neutral';

    /**
     * `resolve_kind`: {@see self::RESOLVE_MANUAL_OK} (safe to close after verify), {@see self::RESOLVE_FIX_ASSET_FIRST}, or {@see self::RESOLVE_NEUTRAL}.
     *
     * @return array{why: string, action: string, resolve_kind: string}
     */
    public function summarize(SystemIncident $incident, ?Asset $asset): array
    {
        if ($incident->source_type !== 'asset' || ! $incident->source_id) {
            return $this->result(
                self::RESOLVE_NEUTRAL,
                'This incident is not tied to a single asset row. Use the title/message and related jobs to triage.',
                'Attempt Repair if available, open Failed Jobs / Horizon, or resolve when the underlying system issue is fixed.'
            );
        }

        if (! $asset) {
            return $this->result(
                self::RESOLVE_MANUAL_OK,
                'The asset ID on this incident no longer exists (deleted) or could not be loaded.',
                'Resolve (Manual) to clear the incident, or restore the asset if removal was accidental.'
            );
        }

        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        $version = $asset->currentVersion;
        $vMeta = $version && is_array($version->metadata) ? $version->metadata : [];

        $pipelineComplete = ! empty($meta['pipeline_completed_at']);
        $analysis = (string) ($asset->analysis_status ?? 'uploading');
        $exhausted = (bool) data_get($incident->metadata, 'auto_repair_exhausted');
        $title = strtolower((string) ($incident->title ?? ''));

        $thumbsComplete = $this->thumbnailsLookComplete($meta, $vMeta, $asset);
        $noRasterDefect = $this->noRasterOrContentDefect($asset, $thumbsComplete);

        if ($pipelineComplete && $analysis === 'complete') {
            return $this->result(
                self::RESOLVE_MANUAL_OK,
                'Indication: no ongoing problem on the asset — it already has `pipeline_completed_at` and analysis is complete. The incident is a stale row left open.',
                'Use Resolve (Manual) to remove it from the queue; nothing more is required on the asset for this alert.'
            );
        }

        $preferredFailed = (data_get($meta, 'thumbnail_modes_status.preferred') === 'failed')
            || ! empty($meta['preferred_thumbnail_error']);

        $versionStuck = $version
            && $version->pipeline_status !== 'complete'
            && ($vMeta['processing_started'] ?? false) === true;

        // Studio / pipeline-finalization mismatch (stalled + thumbs visible)
        if ($thumbsComplete && ! $pipelineComplete) {
            $why = 'Indication: no missing standard thumbnails — raster sizes exist. The open incident is from pipeline/analysis state (e.g. finalization or a misleading watchdog title), not from absent thumb files.';
            if ($preferredFailed) {
                $why .= ' The optional preferred crop failed; thumb/medium/large can still be fine.';
            }
            $why .= ' Thumbnails and metadata are present, but `metadata.pipeline_completed_at` is missing, so the pipeline did not fully finalize.';
            if ($versionStuck) {
                $why .= ' Current version is still `pipeline_status='.$version->pipeline_status.'` with processing started — `FinalizeAssetJob` must see `version.pipeline_status=complete`.';
            } else {
                $why .= ' The incident can stay critical in Ops even when previews already show in the UI.';
            }

            $action = 'After Attempt Repair with workers up (or deploy for Rule 7b), if the asset in admin is acceptable, Resolve (Manual) clears this row — you are not hiding a missing-thumbnail problem.';
            if ($exhausted) {
                $action = 'Auto-repair is off (cap reached). '.$action;
            } else {
                $action = 'Unlike a real thumb failure (no file written), here the alert is out of sync with the files on disk. '.$action;
            }

            return $this->result(
                $noRasterDefect ? self::RESOLVE_MANUAL_OK : self::RESOLVE_FIX_ASSET_FIRST,
                $why,
                $action
            );
        }

        if (str_contains($title, 'thumbnail') && str_contains($title, 'stall')) {
            if ($noRasterDefect) {
                return $this->result(
                    self::RESOLVE_MANUAL_OK,
                    'Indication: thumbnails appear present (metadata or paths) — the “stalled” title may not mean missing files. If analysis_status is still early, the alert can be a false match to the admin preview.',
                    'Confirm workers, run Attempt Repair once, then if the asset looks good, Resolve (Manual) is appropriate. If thumbnail_status=FAILED or no paths exist, treat as a real processing issue and fix before resolving.'
                );
            }

            return $this->result(
                self::RESOLVE_FIX_ASSET_FIRST,
                'Watchdog saw `analysis_status` stuck in an early stage for more than ~10 minutes (`'.$analysis.'`). Thumbnail work may still be in progress, blocked, or failed.',
                'Confirm Horizon/queue workers are up, check Failed Jobs for `GenerateThumbnailsJob`, then Attempt Repair. Do not resolve until thumbs succeed or you accept a skip/failure state.'
            );
        }

        if (str_contains($title, 'uploading')) {
            if ($noRasterDefect) {
                return $this->result(
                    self::RESOLVE_MANUAL_OK,
                    'Indication: content previews exist — the “stuck in uploading” label often reflects a stale analysis_status, not a file that never arrived.',
                    'Run Attempt Repair with workers; if the asset already shows complete thumbs/metadata in admin, you may Resolve (Manual) the incident. Otherwise wait for `ProcessAssetJob`/finalize fixes.'
                );
            }

            return $this->result(
                self::RESOLVE_FIX_ASSET_FIRST,
                'Watchdog reported the asset stuck while `analysis_status` was in an early stage for a long time.',
                'Run Attempt Repair with workers running. Resolve only when the asset is actually healthy in admin.'
            );
        }

        if ($exhausted) {
            return $this->result(
                $noRasterDefect ? self::RESOLVE_MANUAL_OK : self::RESOLVE_FIX_ASSET_FIRST,
                'Scheduled auto-repair stopped after reaching the maximum attempt count for this incident.',
                $noRasterDefect
                    ? 'If the asset already looks fine (thumbs present, no FAILED status), use Resolve (Manual) after verification — auto-repair off only stops the scheduler, not your ability to clear the row.'
                    : 'Use Attempt Repair manually after fixing workers; Resolve (Manual) only once the asset is healthy.'
            );
        }

        return $this->result(
            $noRasterDefect ? self::RESOLVE_MANUAL_OK : self::RESOLVE_NEUTRAL,
            (string) ($incident->message ?: 'See incident title; open the asset admin view for Pipeline / Failed Jobs.'),
            $noRasterDefect
                ? 'Indication: no obvious missing-thumbnail state on the asset. Resolve (Manual) is OK after you confirm the asset, unless Failed Jobs show a hard error.'
                : 'Attempt Repair (workers must be running) → re-check the asset → create ticket or resolve as appropriate.'
        );
    }

    /**
     * Thumbnails (or skip) in place: not a “never got a preview file” class of bug.
     */
    protected function noRasterOrContentDefect(Asset $asset, bool $thumbsComplete): bool
    {
        if ($asset->thumbnail_status === ThumbnailStatus::FAILED) {
            return false;
        }

        return $thumbsComplete;
    }

    /**
     * @return array{why: string, action: string, resolve_kind: string}
     */
    protected function result(string $resolveKind, string $why, string $action): array
    {
        return [
            'why' => $why,
            'action' => $action,
            'resolve_kind' => $resolveKind,
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
