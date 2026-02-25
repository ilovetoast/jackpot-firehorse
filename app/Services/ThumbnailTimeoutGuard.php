<?php

namespace App\Services;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Thumbnail Timeout Guard Service
 * 
 * Enforces hard terminal states for thumbnail generation by detecting
 * and automatically failing assets that have been stuck in processing
 * for longer than the timeout threshold.
 * 
 * This prevents infinite processing states where assets remain in
 * thumbnail_status = processing forever.
 * 
 * 🔒 THUMBNAIL SYSTEM LOCK:
 * This service is part of the locked thumbnail pipeline. It ensures
 * every asset reaches a terminal state (COMPLETED, FAILED, SKIPPED).
 * 
 * The thumbnail system is intentionally NON-REALTIME:
 * - Grid thumbnails do NOT auto-update
 * - Users must refresh to see final thumbnails
 * - This prevents UI flicker and re-render thrash
 * 
 * See THUMBNAIL_PIPELINE.md for full system documentation.
 */
class ThumbnailTimeoutGuard
{
    /**
     * Default buffer minutes added on top of worker timeout (guard must fire after worker would kill job).
     */
    protected const STUCK_BUFFER_MINUTES = 5;

    /**
     * Get stuck threshold in minutes. Derived from worker_timeout_seconds so the guard
     * does not mark assets failed before the queue worker would kill the job.
     */
    protected function getTimeoutMinutes(?Asset $asset = null): int
    {
        $workerSeconds = (int) config('assets.thumbnail.worker_timeout_seconds', 900);
        return (int) ceil($workerSeconds / 60) + self::STUCK_BUFFER_MINUTES;
    }

    /**
     * Check and repair stuck assets.
     * 
     * Finds assets that are stuck in processing state and marks them as FAILED
     * if they've been processing longer than the timeout threshold.
     * 
     * @param Asset|null $asset Optional single asset to check (for targeted checks)
     * @return int Number of assets repaired
     */
    public function checkAndRepair(?Asset $asset = null): int
    {
        $repairedCount = 0;
        
        if ($asset) {
            // Check single asset
            if ($this->isStuck($asset)) {
                $timeout = $this->getTimeoutMinutes($asset);
                $this->markAsFailed($asset, "Thumbnail generation timed out after {$timeout} minutes", $timeout);
                $repairedCount = 1;
            }
        } else {
            // Check all stuck assets - use shortest timeout (5 min) so we catch all candidates
            $queryTimeout = $this->getTimeoutMinutes(null);
            $stuckAssets = Asset::where('thumbnail_status', ThumbnailStatus::PROCESSING)
                ->where(function ($query) use ($queryTimeout) {
                    $query->whereNotNull('thumbnail_started_at')
                        ->where('thumbnail_started_at', '<', now()->subMinutes($queryTimeout))
                        ->orWhere(function ($q) use ($queryTimeout) {
                            $q->whereNull('thumbnail_started_at')
                                ->where('created_at', '<', now()->subMinutes($queryTimeout));
                        });
                })
                ->get();
            
            foreach ($stuckAssets as $stuckAsset) {
                if ($this->isStuck($stuckAsset)) {
                    $timeout = $this->getTimeoutMinutes($stuckAsset);
                    $this->markAsFailed($stuckAsset, "Thumbnail generation timed out after {$timeout} minutes", $timeout);
                    $repairedCount++;
                }
            }
        }
        
        return $repairedCount;
    }

    /**
     * Check if an asset is stuck in processing.
     * 
     * @param Asset $asset
     * @param int|null $timeoutMinutes Override timeout (uses asset-specific default if null)
     * @return bool True if asset is stuck (processing longer than timeout)
     */
    public function isStuck(Asset $asset, ?int $timeoutMinutes = null): bool
    {
        if ($asset->thumbnail_status !== ThumbnailStatus::PROCESSING) {
            return false;
        }
        
        $timeout = $timeoutMinutes ?? $this->getTimeoutMinutes($asset);
        
        if (!$asset->thumbnail_started_at) {
            $startTime = $asset->created_at;
        } else {
            $startTime = $asset->thumbnail_started_at;
        }
        
        if (!$startTime) {
            return false;
        }
        
        if (is_string($startTime)) {
            $startTime = \Carbon\Carbon::parse($startTime);
        }
        
        return $startTime->lt(now()->subMinutes($timeout));
    }

    /**
     * Mark an asset as failed due to timeout.
     * 
     * @param Asset $asset
     * @param string $errorMessage
     * @param int $timeoutMinutes
     */
    protected function markAsFailed(Asset $asset, string $errorMessage, int $timeoutMinutes = 5): void
    {
        Log::warning('[ThumbnailTimeoutGuard] Marking stuck asset as FAILED', [
            'asset_id' => $asset->id,
            'thumbnail_started_at' => $asset->thumbnail_started_at?->toIso8601String(),
            'timeout_minutes' => $timeoutMinutes,
            'error' => $errorMessage,
        ]);
        
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::FAILED,
            'thumbnail_error' => $errorMessage,
        ]);
        
        // Update metadata to record timeout
        $metadata = $asset->metadata ?? [];
        $metadata['thumbnail_timeout'] = true;
        $metadata['thumbnail_timeout_at'] = now()->toIso8601String();
        $metadata['thumbnail_timeout_reason'] = $errorMessage;
        $asset->update(['metadata' => $metadata]);
        
        // Log timeout event
        try {
            \App\Services\ActivityRecorder::logAsset(
                $asset,
                \App\Enums\EventType::ASSET_THUMBNAIL_FAILED,
                [
                    'error' => $errorMessage,
                    'reason' => 'timeout',
                    'timeout_minutes' => $timeoutMinutes,
                ]
            );
        } catch (\Exception $e) {
            Log::error('[ThumbnailTimeoutGuard] Failed to log timeout event', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
