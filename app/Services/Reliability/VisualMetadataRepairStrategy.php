<?php

namespace App\Services\Reliability;

use App\Models\Asset;
use App\Models\SystemIncident;
use App\Jobs\PopulateAutomaticMetadataJob;
use App\Services\Assets\AssetStateReconciliationService;
use Illuminate\Support\Facades\Log;

/**
 * Repair strategy for "Expected visual metadata missing" incidents.
 *
 * Dispatches PopulateAutomaticMetadataJob and reconciles asset state.
 */
class VisualMetadataRepairStrategy implements RepairStrategyInterface
{
    public function __construct(
        protected AssetStateReconciliationService $reconciliationService
    ) {}

    public function supports(SystemIncident $incident): bool
    {
        return $incident->title === 'Expected visual metadata missing'
            && $incident->source_type === 'asset'
            && $incident->source_id;
    }

    public function attempt(SystemIncident $incident): RepairResult
    {
        $asset = Asset::find($incident->source_id);
        if (!$asset) {
            return new RepairResult(false, []);
        }

        $asset->update(['analysis_status' => 'extracting_metadata']);
        PopulateAutomaticMetadataJob::dispatch($asset->id);

        $incident->update([
            'metadata' => array_merge($incident->metadata ?? [], [
                'retried' => true,
                'retried_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::info('[VisualMetadataRepairStrategy] Dispatched PopulateAutomaticMetadataJob', [
            'incident_id' => $incident->id,
            'asset_id' => $asset->id,
        ]);

        $result = $this->reconciliationService->reconcile($asset->fresh());
        $asset->refresh();

        $resolved = $asset->visualMetadataReady();

        return new RepairResult($resolved, $result['changes'] ?? []);
    }
}
