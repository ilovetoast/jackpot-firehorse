<?php

namespace App\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandPipelineRun;
use Illuminate\Support\Collection;

/**
 * Estimates pipeline duration from historical completed runs, keyed by file size (PDF) and extraction mode.
 */
class PipelineDurationEstimateService
{
    /** When no historical median is available, use these baselines (seconds). */
    public const DEFAULT_EXPECTED_SECONDS_TEXT = 180;

    public const DEFAULT_EXPECTED_SECONDS_VISION = 420;

    /** Warn when elapsed exceeds expected × this factor (and min elapsed). */
    private const SLOWER_THAN_EXPECTED_MULTIPLIER = 1.45;

    private const MIN_ELAPSED_BEFORE_WARNING_SECONDS = 90;

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $brandIds
     */
    private function baseQuery(Collection $brandIds, string $extractionMode)
    {
        return BrandPipelineRun::query()
            ->whereIn('brand_id', $brandIds)
            ->where('status', BrandPipelineRun::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->where('extraction_mode', $extractionMode);
    }

    /** Ignore implausible durations (seconds) */
    private const MIN_DURATION = 5;

    private const MAX_DURATION = 7200; // 2 hours

    /**
     * @return array{
     *   avg_seconds: int|null,
     *   median_seconds: int|null,
     *   sample_count: int,
     *   match: 'similar_size'|'mode_only'|'none',
     *   size_range_label: string|null
     * }
     */
    public function estimate(int $tenantId, ?int $sourceSizeBytes, string $extractionMode): array
    {
        $brandIds = Brand::where('tenant_id', $tenantId)->pluck('id');
        if ($brandIds->isEmpty()) {
            return $this->emptyEstimate();
        }

        $similar = null;
        if ($sourceSizeBytes !== null && $sourceSizeBytes > 0) {
            $min = (int) max(1, floor($sourceSizeBytes * 0.45));
            $max = (int) ceil($sourceSizeBytes * 2.25);
            $similar = $this->baseQuery($brandIds, $extractionMode)
                ->whereNotNull('source_size_bytes')
                ->whereBetween('source_size_bytes', [$min, $max])
                ->orderByDesc('completed_at')
                ->limit(40)
                ->get(['created_at', 'completed_at', 'source_size_bytes']);
        }

        $tookSimilar = $similar !== null && $similar->count() >= 2;
        $runs = $tookSimilar
            ? $similar
            : $this->baseQuery($brandIds, $extractionMode)
                ->whereNotNull('source_size_bytes')
                ->where('source_size_bytes', '>', 0)
                ->orderByDesc('completed_at')
                ->limit(50)
                ->get(['created_at', 'completed_at', 'source_size_bytes']);

        $match = $tookSimilar ? 'similar_size' : 'mode_only';
        if ($runs->count() < 2) {
            return array_merge($this->emptyEstimate(), [
                'match' => 'none',
            ]);
        }

        $durations = $this->durationsInRange($runs);
        if ($durations->count() < 2) {
            return array_merge($this->emptyEstimate(), [
                'match' => 'none',
            ]);
        }

        $median = $this->medianSeconds($durations);
        $avg = (int) round($durations->avg());

        $sizeLabel = null;
        if ($sourceSizeBytes !== null && $sourceSizeBytes > 0) {
            $sizeLabel = $this->formatBytes($sourceSizeBytes).' PDFs';
        }

        return [
            'avg_seconds' => $avg,
            'median_seconds' => $median,
            'sample_count' => $durations->count(),
            'match' => $match,
            'size_range_label' => $sizeLabel,
        ];
    }

    /**
     * @return Collection<int, float|int>
     */
    private function durationsInRange(Collection $runs): Collection
    {
        return $runs->map(function ($r) {
            if (! $r->created_at || ! $r->completed_at) {
                return null;
            }
            $sec = $r->created_at->diffInSeconds($r->completed_at);
            if ($sec < self::MIN_DURATION || $sec > self::MAX_DURATION) {
                return null;
            }

            return $sec;
        })->filter(fn ($v) => $v !== null)->values();
    }

    private function emptyEstimate(): array
    {
        return [
            'avg_seconds' => null,
            'median_seconds' => null,
            'sample_count' => 0,
            'match' => 'none',
            'size_range_label' => null,
        ];
    }

    public function defaultExpectedSeconds(string $extractionMode): int
    {
        return $extractionMode === BrandPipelineRun::EXTRACTION_MODE_VISION
            ? self::DEFAULT_EXPECTED_SECONDS_VISION
            : self::DEFAULT_EXPECTED_SECONDS_TEXT;
    }

    /**
     * Compare elapsed time on an active pipeline run to a median (from {@see estimate}) or a default.
     *
     * @param  array{
     *   avg_seconds: int|null,
     *   median_seconds: int|null,
     *   sample_count: int,
     *   match: string,
     *   size_range_label: string|null
     * }  $estimate
     * @return array{
     *   elapsed_seconds: int,
     *   expected_seconds: int,
     *   expectation_source: 'median'|'default',
     *   slower_than_expected: bool
     * }|null
     */
    public function computeActiveRunTiming(?BrandPipelineRun $run, array $estimate, string $extractionMode): ?array
    {
        if ($run === null) {
            return null;
        }
        if (in_array($run->status, [BrandPipelineRun::STATUS_COMPLETED, BrandPipelineRun::STATUS_FAILED], true)) {
            return null;
        }
        $started = $run->created_at;
        if (! $started) {
            return null;
        }

        $elapsed = (int) $started->diffInSeconds(now());

        $hasMedian = ($estimate['median_seconds'] ?? null) !== null
            && (int) ($estimate['sample_count'] ?? 0) >= 2
            && (int) $estimate['median_seconds'] > 0;

        $expected = $hasMedian
            ? (int) $estimate['median_seconds']
            : $this->defaultExpectedSeconds($extractionMode);
        $expectationSource = $hasMedian ? 'median' : 'default';

        $threshold = (int) max(
            self::MIN_ELAPSED_BEFORE_WARNING_SECONDS,
            (int) ceil($expected * self::SLOWER_THAN_EXPECTED_MULTIPLIER)
        );

        $slowerThanExpected = $elapsed >= $threshold;

        return [
            'elapsed_seconds' => $elapsed,
            'expected_seconds' => $expected,
            'expectation_source' => $expectationSource,
            'slower_than_expected' => $slowerThanExpected,
        ];
    }

    /**
     * @param  Collection<int, float|int>  $seconds
     */
    private function medianSeconds(Collection $seconds): int
    {
        $s = $seconds->sort()->values();
        $n = $s->count();
        if ($n === 0) {
            return 0;
        }
        if ($n % 2 === 1) {
            return (int) round($s[intdiv($n - 1, 2)]);
        }

        return (int) round(($s[$n / 2 - 1] + $s[$n / 2]) / 2);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1024 / 1024, 1).' MB';
    }
}
