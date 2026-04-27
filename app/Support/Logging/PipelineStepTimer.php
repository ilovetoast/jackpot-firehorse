<?php

namespace App\Support\Logging;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Monotonic + wall-clock context for opt-in asset pipeline step timing.
 *
 * Enable: ASSET_PIPELINE_LOG_STEP_TIMINGS=true
 * Grep:   [pipeline_timing]
 *
 * "ms_since_processing_marked" is wall time since {@see ProcessAssetJob} set
 * processing_started_at on the version (or asset), useful for delay before
 * {@see GenerateThumbnailsJob} (queue + earlier chain jobs + throttle).
 */
class PipelineStepTimer
{
    private string $runId;

    private string $phase;

    private string $assetId;

    private ?string $versionId;

    private float $hrtimeStart;

    private float $hrtimeLast;

    public function __construct(string $runId, string $phase, string $assetId, ?string $versionId = null)
    {
        $this->runId = $runId;
        $this->phase = $phase;
        $this->assetId = $assetId;
        $this->versionId = $versionId;
        $this->hrtimeStart = $this->hrtimeLast = hrtime(true);
    }

    public static function enabled(): bool
    {
        return (bool) config('assets.processing.log_step_timings', false);
    }

    public static function start(string $phase, string $assetId, ?string $versionId = null): self
    {
        return new self((string) Str::uuid(), $phase, $assetId, $versionId);
    }

    /**
     * @param  array<string, mixed>  $baseContext  merged into every lap (e.g. job_id, attempt)
     */
    public function lap(string $step, ?Asset $asset = null, ?AssetVersion $version = null, array $baseContext = []): void
    {
        if (! self::enabled()) {
            return;
        }

        $now = hrtime(true);
        $msSinceLast = ($now - $this->hrtimeLast) / 1000000.0;
        $msTotal = ($now - $this->hrtimeStart) / 1000000.0;
        $this->hrtimeLast = $now;

        $row = array_merge(
            $baseContext,
            self::wallClockSinceProcessingMarked($asset, $version),
            [
                'run_id' => $this->runId,
                'phase' => $this->phase,
                'step' => $step,
                'ms_step' => round($msSinceLast, 2),
                'ms_total' => round($msTotal, 2),
                'asset_id' => $this->assetId,
                'version_id' => $this->versionId,
            ],
        );

        Log::info('[pipeline_timing]', $row);
    }

    /**
     * @return array<string, int>
     */
    public static function wallClockSinceProcessingMarked(?Asset $asset, ?AssetVersion $version = null): array
    {
        if (! $asset && ! $version) {
            return [];
        }

        $ts = null;
        if ($version) {
            $vm = $version->metadata ?? [];
            $ts = is_array($vm) ? ($vm['processing_started_at'] ?? null) : null;
        }
        if (! $ts && $asset) {
            $am = $asset->metadata ?? [];
            $ts = is_array($am) ? ($am['processing_started_at'] ?? null) : null;
        }
        if (! is_string($ts) || $ts === '') {
            return [];
        }

        try {
            $started = \Illuminate\Support\Carbon::parse($ts);
            $ms = (int) round(abs($started->diffInMilliseconds(\Illuminate\Support\Carbon::now())));

            return ['ms_since_processing_marked' => $ms];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
