<?php

namespace App\Console\Commands;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\SupportTicket;
use App\Services\Reliability\ReliabilityEngine;
use App\Support\ThumbnailMetadata;
use Illuminate\Console\Command;

/**
 * Asset Processing Watchdog.
 *
 * Detects assets stuck in uploading or generating_thumbnails (grace: config reliability.watchdog).
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
        $stuckGrace = max(5, (int) config('reliability.watchdog.stuck_analysis_grace_minutes', 22));
        $failedCooldown = max(3, (int) config('reliability.watchdog.failed_thumbnail_cooldown_minutes', 12));
        $processingStaleMinutes = max(10, (int) config('reliability.watchdog.processing_stale_minutes', 28));
        $watchdog = config('reliability.watchdog', []);
        $autoSupportTicket = filter_var($watchdog['auto_support_ticket_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $supportTicketMinStale = max($stuckGrace, (int) ($watchdog['support_ticket_min_stale_minutes'] ?? 40));
        $uploadingNeedsAgent = filter_var($watchdog['uploading_requires_support_agent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $cutoff = now()->subMinutes($stuckGrace);

        $stuck = Asset::whereNull('deleted_at')
            ->whereIn('analysis_status', ['uploading', 'generating_thumbnails'])
            ->where('updated_at', '<', $cutoff)
            ->get();

        // Thumbnails that failed but still have retry budget — the auto-recover loop will
        // keep trying (ThumbnailRetryStrategy allows up to MAX_RETRIES per incident), but
        // no incident exists today for this state, so the loop never engages. Emit one.
        $maxRetries = (int) config('assets.thumbnail.max_retries', 3);
        $thumbnailRetryCooldown = now()->subMinutes($failedCooldown);
        $failedWithRetriesLeft = Asset::whereNull('deleted_at')
            ->where('thumbnail_status', ThumbnailStatus::FAILED)
            ->where('thumbnail_retry_count', '<', $maxRetries)
            ->where('updated_at', '<', $thumbnailRetryCooldown)
            ->get();

        // Thumbnails that have been in PROCESSING longer than the worker would ever run.
        // ThumbnailTimeoutGuard is the canonical fixer; calling it here removes the
        // dependency on a separately-scheduled `thumbnails:repair-stuck` run.
        $processingCutoff = now()->subMinutes($processingStaleMinutes);
        $processingStale = Asset::whereNull('deleted_at')
            ->where('thumbnail_status', ThumbnailStatus::PROCESSING)
            ->where(function ($q) use ($processingCutoff) {
                $q->where('thumbnail_started_at', '<', $processingCutoff)
                    ->orWhere(function ($q2) use ($processingCutoff) {
                        $q2->whereNull('thumbnail_started_at')
                            ->where('updated_at', '<', $processingCutoff);
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

            // analysis_status lags: studio/eager paths can leave "generating_thumbnails" (or "uploading")
            // even when thumbnails already exist; the real problem is finalization, not a stalled GenerateThumbnailsJob.
            // Do not emit "Thumbnail generation stalled" / "stuck in uploading" for that false shape.
            if ($this->thumbnailsLookComplete($asset->fresh())) {
                continue;
            }

            $metadata = $asset->metadata ?? [];
            // Stable signature: reconciliation may touch updated_at and must not spawn a new incident per run.
            $uniqueSignature = "asset_stuck:{$asset->id}:{$asset->analysis_status}";

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
                $basePayload['message'] = "Asset {$asset->id} has been in uploading state for over {$stuckGrace} minutes.";
                $basePayload['retryable'] = true;
                $basePayload['requires_support'] = $uploadingNeedsAgent;
            } else {
                $basePayload['severity'] = 'error';
                $basePayload['title'] = 'Thumbnail generation stalled';
                $basePayload['message'] = "Asset {$asset->id} thumbnail generation has been stalled for over {$stuckGrace} minutes.";
                $basePayload['retryable'] = true;
            }

            $incident = $reliabilityEngine->report($basePayload);
            if ($incident) {
                $recorded++;
            }

            // SupportTicket even when the incident row was deduped (recordIfNotExists returned null),
            // once the asset crosses the stale threshold and no open ticket exists.
            if ($autoSupportTicket) {
                $staleEnoughForTicket = $asset->updated_at && $asset->updated_at->lte(now()->subMinutes($supportTicketMinStale));
                $hasOpenTicket = SupportTicket::where('source_type', 'asset')
                    ->where('source_id', $asset->id)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->exists();
                if ($staleEnoughForTicket && !$hasOpenTicket) {
                    try {
                        $signature = "asset_stalled:{$asset->id}";
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

        foreach ($failedWithRetriesLeft as $asset) {
            try {
                app(\App\Services\Assets\AssetStateReconciliationService::class)->reconcile($asset->fresh());
                $asset->refresh();
                if ($asset->thumbnail_status !== ThumbnailStatus::FAILED) {
                    continue;
                }
            } catch (\Throwable $e) {
                // Continue — still worth recording an incident.
            }

            // Include retry_count in the signature so each new failure cycle emits a fresh
            // incident (ThumbnailRetryStrategy allows MAX_RETRIES retries *per incident*).
            $uniqueSignature = "thumbnail_failed_with_retries:{$asset->id}:{$asset->thumbnail_retry_count}";

            $incident = $reliabilityEngine->report([
                'source_type' => 'asset',
                'source_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'severity' => 'warning',
                'title' => 'Thumbnail generation failed (auto-retry eligible)',
                'message' => "Asset {$asset->id} has thumbnail_status=failed with {$asset->thumbnail_retry_count}/{$maxRetries} retries used.",
                'retryable' => true,
                'metadata' => [
                    'thumbnail_error' => $asset->thumbnail_error,
                    'thumbnail_retry_count' => $asset->thumbnail_retry_count,
                    'mime_type' => $asset->mime_type,
                    'unique_signature' => $uniqueSignature,
                ],
                'unique_signature' => $uniqueSignature,
            ]);

            if ($incident) {
                $recorded++;
            }
        }

        if ($recorded > 0) {
            $this->info("Recorded {$recorded} incident(s) for stuck assets.");
        }

        return self::SUCCESS;
    }

    /**
     * Thumbnails are present/complete: watchdog noise would blame "stalled" generation when the
     * issue is actually pipeline completion ({@see FinalizeAssetJob}, {@see AssetStateReconciliationService} rules).
     */
    protected function thumbnailsLookComplete(Asset $asset): bool
    {
        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        if (! empty($meta['thumbnails_generated'])) {
            return true;
        }
        if (in_array($asset->thumbnail_status, [ThumbnailStatus::COMPLETED, ThumbnailStatus::SKIPPED], true)) {
            return true;
        }
        if (ThumbnailMetadata::hasThumb($meta)) {
            return true;
        }
        $version = $asset->currentVersion;
        if ($version && is_array($version->metadata) && ThumbnailMetadata::hasThumb($version->metadata)) {
            return true;
        }

        return false;
    }
}
