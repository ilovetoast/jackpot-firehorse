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
 * for longer than the timeout threshold (5 minutes).
 * 
 * This prevents infinite processing states where assets remain in
 * thumbnail_status = processing forever.
 */
class ThumbnailTimeoutGuard
{
    /**
     * Timeout threshold in minutes.
     * Assets processing longer than this will be marked as FAILED.
     */
    protected const TIMEOUT_MINUTES = 5;

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
                $this->markAsFailed($asset, 'Thumbnail generation timed out after ' . self::TIMEOUT_MINUTES . ' minutes');
                $repairedCount = 1;
            }
        } else {
            // Check all stuck assets
            // Include assets with thumbnail_started_at OR assets without it (fallback to created_at)
            $stuckAssets = Asset::where('thumbnail_status', ThumbnailStatus::PROCESSING)
                ->where(function ($query) {
                    // Assets with thumbnail_started_at older than timeout
                    $query->whereNotNull('thumbnail_started_at')
                        ->where('thumbnail_started_at', '<', now()->subMinutes(self::TIMEOUT_MINUTES))
                        // OR assets without thumbnail_started_at but created more than timeout ago
                        // (fallback for assets that started processing before thumbnail_started_at was added)
                        ->orWhere(function ($q) {
                            $q->whereNull('thumbnail_started_at')
                                ->where('created_at', '<', now()->subMinutes(self::TIMEOUT_MINUTES));
                        });
                })
                ->get();
            
            foreach ($stuckAssets as $stuckAsset) {
                $this->markAsFailed($stuckAsset, 'Thumbnail generation timed out after ' . self::TIMEOUT_MINUTES . ' minutes');
                $repairedCount++;
            }
        }
        
        return $repairedCount;
    }

    /**
     * Check if an asset is stuck in processing.
     * 
     * @param Asset $asset
     * @return bool True if asset is stuck (processing longer than timeout)
     */
    public function isStuck(Asset $asset): bool
    {
        if ($asset->thumbnail_status !== ThumbnailStatus::PROCESSING) {
            return false;
        }
        
        if (!$asset->thumbnail_started_at) {
            // No start time recorded - consider it stuck if it's been processing for a while
            // Use created_at as fallback (asset was created when processing started)
            $startTime = $asset->created_at;
        } else {
            $startTime = $asset->thumbnail_started_at;
        }
        
        return $startTime && $startTime->lt(now()->subMinutes(self::TIMEOUT_MINUTES));
    }

    /**
     * Mark an asset as failed due to timeout.
     * 
     * @param Asset $asset
     * @param string $errorMessage
     */
    protected function markAsFailed(Asset $asset, string $errorMessage): void
    {
        Log::warning('[ThumbnailTimeoutGuard] Marking stuck asset as FAILED', [
            'asset_id' => $asset->id,
            'thumbnail_started_at' => $asset->thumbnail_started_at?->toIso8601String(),
            'timeout_minutes' => self::TIMEOUT_MINUTES,
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
                    'timeout_minutes' => self::TIMEOUT_MINUTES,
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
