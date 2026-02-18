<?php

namespace App\Services\Reliability;

use App\Models\Asset;
use App\Models\SystemIncident;
use App\Jobs\GenerateThumbnailsJob;
use App\Services\Assets\AssetStateReconciliationService;
use Illuminate\Support\Facades\Log;

/**
 * Repair strategy for thumbnail generation failures.
 *
 * Dispatches GenerateThumbnailsJob directly. ProcessAssetJob is NOT used because it
 * only runs when analysis_status=uploading and processing_started=false — stuck
 * assets have processing_started=true and analysis_status=generating_thumbnails, so
 * ProcessAssetJob would return early and do nothing.
 */
class ThumbnailRetryStrategy implements RepairStrategyInterface
{
    protected const MAX_RETRIES = 3;

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

        $metadata = $incident->metadata ?? [];
        $retryCount = (int) ($metadata['retry_count'] ?? 0);

        if ($incident->retryable && $retryCount < self::MAX_RETRIES) {
            GenerateThumbnailsJob::dispatch($asset->id);
            $incident->update([
                'metadata' => array_merge($metadata, [
                    'retried' => true,
                    'retried_at' => now()->toIso8601String(),
                    'retry_count' => $retryCount + 1,
                ]),
            ]);
            Log::info('[ThumbnailRetryStrategy] Dispatched GenerateThumbnailsJob', [
                'incident_id' => $incident->id,
                'asset_id' => $asset->id,
                'retry_count' => $retryCount + 1,
            ]);
        }

        return new RepairResult(false, $result['changes'] ?? []);
    }
}
