<?php

namespace App\Services\Reliability;

use App\Models\Asset;
use App\Models\SystemIncident;
use App\Jobs\ProcessAssetJob;
use App\Jobs\PromoteAssetJob;
use App\Jobs\PopulateAutomaticMetadataJob;
use App\Services\Assets\AssetStateReconciliationService;
use Illuminate\Support\Facades\Log;

/**
 * Repair strategy for generic job failures and promotion failures.
 *
 * Dispatches appropriate retry job based on incident/asset state.
 */
class JobRetryStrategy implements RepairStrategyInterface
{
    public function __construct(
        protected AssetStateReconciliationService $reconciliationService
    ) {}

    public function supports(SystemIncident $incident): bool
    {
        if (!in_array($incident->source_type, ['asset', 'job'], true) || !$incident->source_id) {
            return false;
        }
        // "Expected visual metadata missing" is handled by VisualMetadataRepairStrategy
        if ($incident->title === 'Expected visual metadata missing') {
            return false;
        }
        return true;
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
            $this->dispatchRetry($incident, $asset);
        }

        return new RepairResult(false, $result['changes'] ?? []);
    }

    protected function dispatchRetry(SystemIncident $incident, Asset $asset): void
    {
        if (($asset->analysis_status ?? '') === 'promotion_failed') {
            PromoteAssetJob::dispatch($asset->id);
            Log::info('[JobRetryStrategy] Dispatched PromoteAssetJob', [
                'incident_id' => $incident->id,
                'asset_id' => $asset->id,
            ]);
        } else {
            ProcessAssetJob::dispatch($asset->id);
            Log::info('[JobRetryStrategy] Dispatched ProcessAssetJob', [
                'incident_id' => $incident->id,
                'asset_id' => $asset->id,
            ]);
        }

        $incident->update([
            'metadata' => array_merge($incident->metadata ?? [], [
                'retried' => true,
                'retried_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
