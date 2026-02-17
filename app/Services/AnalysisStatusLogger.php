<?php

namespace App\Services;

use App\Models\AnalysisEvent;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Logs analysis_status transitions to analysis_events for audit and debugging.
 * Failures are logged but never break the main pipeline.
 */
class AnalysisStatusLogger
{
    public static function log(Asset $asset, ?string $previousStatus, string $newStatus, ?string $job = null): void
    {
        try {
            AnalysisEvent::create([
                'asset_id' => $asset->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'job' => $job,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AnalysisStatusLogger] Failed to log status transition', [
                'asset_id' => $asset->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
