<?php

namespace App\Services;

use App\Enums\ZipStatus;
use App\Models\Download;

/**
 * Phase D-UX: Estimates ZIP build time based on historical data.
 *
 * Messaging only. No behavior changes to ZIP generation.
 */
class DownloadZipEstimateService
{
    /**
     * Size buckets (bytes) for grouping historical builds.
     * [min, max) - max exclusive; last bucket uses PHP_INT_MAX.
     */
    protected const BUCKETS = [
        [0, 10 * 1024 * 1024],                    // 0 - 10 MB
        [10 * 1024 * 1024, 50 * 1024 * 1024],     // 10 - 50 MB
        [50 * 1024 * 1024, 250 * 1024 * 1024],    // 50 - 250 MB
        [250 * 1024 * 1024, 1024 * 1024 * 1024],  // 250 MB - 1 GB
        [1024 * 1024 * 1024, PHP_INT_MAX],        // 1 GB+
    ];

    protected const MIN_SAMPLES = 3;

    /**
     * Estimate ZIP build time range based on historical data for similar-sized downloads.
     *
     * @param int $totalBytes Estimated total bytes (from download_options['estimated_bytes'] or zip_size_bytes)
     * @return array{min_minutes: int, max_minutes: int, confidence: string}|null
     */
    public function estimateZipBuildTimeRange(int $totalBytes): ?array
    {
        $bucket = $this->getBucketForBytes($totalBytes);
        $minBytes = $bucket[0];
        $maxBytes = $bucket[1] === PHP_INT_MAX ? 999999999999 : $bucket[1];

        $durations = Download::query()
            ->where('zip_status', ZipStatus::READY)
            ->whereNotNull('zip_build_duration_seconds')
            ->where('zip_size_bytes', '>=', $minBytes)
            ->where('zip_size_bytes', '<', $maxBytes)
            ->pluck('zip_build_duration_seconds')
            ->filter()
            ->values()
            ->all();

        if (count($durations) < self::MIN_SAMPLES) {
            return null;
        }

        sort($durations);
        $n = count($durations);
        $p50Index = (int) floor($n * 0.5);
        $p75Index = (int) floor($n * 0.75);
        $p50Seconds = $durations[$p50Index] ?? $durations[0];
        $p75Seconds = $durations[$p75Index] ?? $durations[$n - 1];

        $minMinutes = max(1, (int) floor($p50Seconds / 60));
        $maxMinutes = max($minMinutes, (int) ceil($p75Seconds / 60));

        $confidence = $n >= 10 ? 'high' : 'low';

        return [
            'min_minutes' => $minMinutes,
            'max_minutes' => $maxMinutes,
            'confidence' => $confidence,
        ];
    }

    protected function getBucketForBytes(int $bytes): array
    {
        foreach (self::BUCKETS as $bucket) {
            if ($bytes >= $bucket[0] && ($bucket[1] === PHP_INT_MAX || $bytes < $bucket[1])) {
                return $bucket;
            }
        }

        return self::BUCKETS[count(self::BUCKETS) - 1];
    }
}
