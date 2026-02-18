<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\SupportTicket;
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
            // P1: Reconcile before recording incident
            try {
                app(\App\Services\Assets\AssetStateReconciliationService::class)->reconcile($asset->fresh());
                $asset->refresh();
                if (!in_array($asset->analysis_status, ['uploading', 'generating_thumbnails'], true)) {
                    continue;
                }
            } catch (\Throwable $e) {
                // Continue to record incident
            }

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

                // P6: Auto-create SupportTicket if stuck and no open ticket exists
                $signature = "asset_stalled:{$asset->id}";
                $hasOpenTicket = SupportTicket::where('source_type', 'asset')
                    ->where('source_id', $asset->id)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->exists();

                if (!$hasOpenTicket) {
                    try {
                        $metadata = $asset->metadata ?? [];
                        $payload = [
                            'asset_id' => $asset->id,
                            'tenant_id' => $asset->tenant_id,
                            'brand_id' => $asset->brand_id,
                            'analysis_status' => $asset->analysis_status,
                            'thumbnail_status' => $asset->thumbnail_status?->value ?? null,
                            'thumbnail_error' => $asset->thumbnail_error,
                            'metadata' => [
                                'pipeline_completed_at' => $metadata['pipeline_completed_at'] ?? null,
                                'thumbnail_timeout' => $metadata['thumbnail_timeout'] ?? null,
                                'thumbnail_timeout_reason' => $metadata['thumbnail_timeout_reason'] ?? null,
                            ],
                            'unique_signature' => $signature,
                        ];

                        SupportTicket::create([
                            'source_type' => 'asset',
                            'source_id' => $asset->id,
                            'summary' => "Asset stalled: {$asset->title}",
                            'description' => "Auto-created by assets:watchdog. Asset stuck in {$asset->analysis_status}.",
                            'severity' => $asset->analysis_status === 'uploading' ? 'critical' : 'warning',
                            'status' => 'open',
                            'source' => 'system',
                            'payload' => $payload,
                            'auto_created' => true,
                        ]);
                    } catch (\Throwable $e) {
                        $this->warn("Failed to create auto-ticket for asset {$asset->id}: {$e->getMessage()}");
                    }
                }
            }
        }

        if ($recorded > 0) {
            $this->info("Recorded {$recorded} incident(s) for stuck assets.");
        }

        return self::SUCCESS;
    }
}
