<?php

namespace Tests\Unit\Support;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Support\ProcessingMetrics;
use Carbon\Carbon;
use Tests\TestCase;

class ProcessingMetricsTest extends TestCase
{
    public function test_pipeline_duration_uses_version_processing_started_at(): void
    {
        $asset = new Asset;
        $asset->created_at = Carbon::parse('2025-01-01 12:00:00', 'UTC');
        $version = new AssetVersion;
        $t0 = Carbon::parse('2025-01-01 12:00:05', 'UTC');
        $version->metadata = ['processing_started_at' => $t0->toIso8601String()];

        Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:10', 'UTC'));

        $ms = ProcessingMetrics::pipelineDurationMs($asset, $version);
        $this->assertSame(5000, $ms);

        Carbon::setTestNow();
    }

    public function test_ms_between_is_non_negative(): void
    {
        $a = Carbon::parse('2025-01-01 12:00:00');
        $b = Carbon::parse('2025-01-01 12:00:02');
        $this->assertSame(2000, ProcessingMetrics::msBetween($a, $b));
        $this->assertSame(2000, ProcessingMetrics::msBetween($b, $a));
    }
}
