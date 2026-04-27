<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\AssetVersion;
use Carbon\Carbon;

/**
 * Ops/debug helpers for time-to-ready metrics stored on {@see Asset} (not user-facing by default).
 */
final class ProcessingMetrics
{
    /**
     * Milliseconds from {@see processPipelineStart()} to now (or 0 if no usable start time).
     */
    public static function pipelineDurationMs(Asset $asset, ?AssetVersion $version = null): int
    {
        $start = self::processPipelineStart($asset, $version);
        if ($start === null) {
            return 0;
        }

        return self::msBetween($start, now());
    }

    public static function processPipelineStart(Asset $asset, ?AssetVersion $version = null): ?Carbon
    {
        $version ??= $asset->currentVersion;
        $startedAt = $version
            ? ($version->metadata['processing_started_at'] ?? null)
            : ($asset->metadata['processing_started_at'] ?? null);
        if (is_string($startedAt) && $startedAt !== '') {
            return Carbon::parse($startedAt);
        }
        if ($asset->created_at) {
            return $asset->created_at;
        }

        return null;
    }

    public static function thumbnailReadyDurationMs(Asset $asset): ?int
    {
        $start = $asset->thumbnail_started_at;
        if ($start === null) {
            return null;
        }

        return self::msBetween($start, now());
    }

    public static function msBetween(Carbon $from, Carbon $to): int
    {
        return max(0, (int) round($from->diffInRealSeconds($to, true) * 1000));
    }
}
