<?php

namespace App\Services\Reliability;

use App\Models\Asset;
use App\Models\SystemIncident;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\ProcessAssetJob;
use App\Services\Assets\AssetStateReconciliationService;
use Illuminate\Support\Facades\Log;

/**
 * Repair strategy for thumbnail generation failures.
 *
 * Dispatches GenerateThumbnailsJob or ProcessAssetJob and reconciles.
 */
class ThumbnailRetryStrategy implements RepairStrategyInterface
{
    protected const THUMBNAIL_TITLES = [
        'Thumbnail generation failed',
        'Thumbnail generation stalled',
    ];

    public function __construct(
        protected AssetStateReconciliationService $reconciliationService
    ) {}

    public function supports(SystemIncident $incident): bool
    {
        if (!$incident->source_id) {
            return false;
        }

        $title = strtolower($incident->title ?? '');
        $isThumbnail = str_contains($title, 'thumbnail');

        return $isThumbnail
            && in_array($incident->source_type, ['asset', 'job'], true);
    }

    public function attempt(SystemIncident $incident): RepairResult
    {
        $asset = Asset::find($incident->source_id);
        if (!$asset) {
            return new RepairResult(false, []);
        }

        $result = $this->reconciliationService->reconcile($asset->fresh());
        $asset->refresh();

        $status = $asset->analysis_status ?? 'uploading';
        if ($status === 'complete') {
            return new RepairResult(true, $result['changes'] ?? []);
        }

        if ($incident->retryable && !($incident->metadata['retried'] ?? false)) {
            ProcessAssetJob::dispatch($asset->id);
            $incident->update([
                'metadata' => array_merge($incident->metadata ?? [], [
                    'retried' => true,
                    'retried_at' => now()->toIso8601String(),
                ]),
            ]);
            Log::info('[ThumbnailRetryStrategy] Dispatched ProcessAssetJob', [
                'incident_id' => $incident->id,
                'asset_id' => $asset->id,
            ]);
        }

        return new RepairResult(false, $result['changes'] ?? []);
    }
}
