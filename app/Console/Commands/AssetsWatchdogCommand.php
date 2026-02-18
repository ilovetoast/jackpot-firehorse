<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\SystemIncidentService;
use Illuminate\Console\Command;

/**
 * Asset Processing Watchdog.
 *
 * Detects assets stuck in uploading or generating_thumbnails for > 10 minutes.
 * Records system incidents. Does NOT mutate assets automatically.
 */
class AssetsWatchdogCommand extends Command
{
    protected $signature = 'assets:watchdog';

    protected $description = 'Detect assets stuck in uploading or thumbnail generation and record incidents';

    public function handle(SystemIncidentService $incidentService): int
    {
        $cutoff = now()->subMinutes(10);

        $stuck = Asset::whereNull('deleted_at')
            ->whereIn('analysis_status', ['uploading', 'generating_thumbnails'])
            ->where('updated_at', '<', $cutoff)
            ->get();

        $recorded = 0;

        foreach ($stuck as $asset) {
            $metadata = $asset->metadata ?? [];
            $uniqueSignature = "asset_stuck:{$asset->id}:{$asset->analysis_status}:" . ($asset->updated_at?->timestamp ?? 0);

            $basePayload = [
                'source_type' => 'asset',
                'source_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'metadata' => array_merge(
                    [
                        'analysis_status' => $asset->analysis_status,
                        'processing_started' => $metadata['processing_started'] ?? null,
                        'last_updated' => $asset->updated_at?->toIso8601String(),
                    ],
                    ['unique_signature' => $uniqueSignature]
                ),
                'unique_signature' => $uniqueSignature,
            ];

            if ($asset->analysis_status === 'uploading') {
                $basePayload['severity'] = 'critical';
                $basePayload['title'] = 'Asset stuck in uploading state';
                $basePayload['message'] = "Asset {$asset->id} has been in uploading state for over 10 minutes.";
                $basePayload['retryable'] = true;
                $basePayload['requires_support'] = true;
            } else {
                $basePayload['severity'] = 'error';
                $basePayload['title'] = 'Thumbnail generation stalled';
                $basePayload['message'] = "Asset {$asset->id} thumbnail generation has been stalled for over 10 minutes.";
                $basePayload['retryable'] = true;
            }

            $incident = $incidentService->recordIfNotExists($basePayload);
            if ($incident) {
                $recorded++;
            }
        }

        if ($recorded > 0) {
            $this->info("Recorded {$recorded} incident(s) for stuck assets.");
        }

        return self::SUCCESS;
    }
}
