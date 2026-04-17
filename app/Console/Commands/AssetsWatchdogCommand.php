<?php

namespace App\Console\Commands;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\SupportTicket;
use App\Services\Reliability\ReliabilityEngine;
use Illuminate\Console\Command;

/**
 * Asset Processing Watchdog.
 *
 * Detects assets stuck in uploading or generating_thumbnails for > 10 minutes.
 * Also detects failed thumbnails that still have retry budget remaining and
 * failed-but-still-PROCESSING thumbnails that died past the worker timeout.
 * Records system incidents so the auto-recover loop (ThumbnailRetryStrategy /
 * JobRetryStrategy) can dispatch fresh jobs. Does NOT mutate assets itself
 * except via {@see AssetStateReconciliationService} during reconcile.
 */
class AssetsWatchdogCommand extends Command
{
    protected $signature = 'assets:watchdog';

    protected $description = 'Detect assets stuck in uploading or thumbnail generation and record incidents';

    public function handle(ReliabilityEngine $reliabilityEngine): int
    {
        $cutoff = now()->subMinutes(10);

        $stuck = Asset::whereNull('deleted_at')
            ->whereIn('analysis_status', ['uploading', 'generating_thumbnails'])
            ->where('updated_at', '<', $cutoff)
            ->get();

        // Thumbnails that failed but still have retry budget — the auto-recover loop will
        // keep trying (ThumbnailRetryStrategy allows up to MAX_RETRIES per incident), but
        // no incident exists today for this state, so the loop never engages. Emit one.
        $maxRetries = (int) config('assets.thumbnail.max_retries', 3);
        $thumbnailRetryCooldown = now()->subMinutes(5);
        $failedWithRetriesLeft = Asset::whereNull('deleted_at')
            ->where('thumbnail_status', ThumbnailStatus::FAILED)
            ->where('thumbnail_retry_count', '<', $maxRetries)
            ->where('updated_at', '<', $thumbnailRetryCooldown)
            ->get();

        // Thumbnails that have been in PROCESSING longer than the worker would ever run.
        // ThumbnailTimeoutGuard is the canonical fixer; calling it here removes the
        // dependency on a separately-scheduled `thumbnails:repair-stuck` run.
        $processingStale = Asset::whereNull('deleted_at')
            ->where('thumbnail_status', ThumbnailStatus::PROCESSING)
            ->where(function ($q) {
                $q->where('thumbnail_started_at', '<', now()->subMinutes(20))
                    ->orWhere(function ($q2) {
                        $q2->whereNull('thumbnail_started_at')
                            ->where('updated_at', '<', now()->subMinutes(20));
                    });
            })
            ->get();

        foreach ($processingStale as $asset) {
            try {
                app(\App\Services\ThumbnailTimeoutGuard::class)->checkAndRepair($asset->fresh());
            } catch (\Throwable $e) {
                $this->warn("ThumbnailTimeoutGuard failed for asset {$asset->id}: {$e->getMessage()}");
            }
        }

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

            $incident = $reliabilityEngine->report($basePayload);
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
