<?php

namespace App\Studio\Animation\Services;

use App\Models\StudioAnimationJob;
use App\Studio\Animation\Support\StudioAnimationProviderTelemetry;

/**
 * Shared merge path for poll + future webhooks so normalized telemetry stays consistent.
 */
final class StudioAnimationProviderStatusService
{
    /**
     * @param  array<string, mixed>  $traceEntry
     * @param  array<string, mixed>|null  $lastRaw
     * @return array<string, mixed>
     */
    public function mergeProviderTelemetry(StudioAnimationJob $job, array $traceEntry, ?array $lastRaw): array
    {
        $merged = StudioAnimationProviderTelemetry::merge($job->provider_response_json, $traceEntry);
        if ($lastRaw !== null) {
            $merged['last_provider_raw_response'] = $lastRaw;
        }

        return $merged;
    }
}
