<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Models\BrandPipelineRun;
use App\Services\BrandDNA\PipelineDurationEstimateService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineDurationEstimateServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function compute_active_run_timing_returns_null_for_terminal_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));

        $service = new PipelineDurationEstimateService;

        $completed = new BrandPipelineRun;
        $completed->forceFill([
            'status' => BrandPipelineRun::STATUS_COMPLETED,
            'created_at' => now()->subHour(),
        ]);
        $this->assertNull($service->computeActiveRunTiming($completed, [], BrandPipelineRun::EXTRACTION_MODE_TEXT));

        $failed = new BrandPipelineRun;
        $failed->forceFill([
            'status' => BrandPipelineRun::STATUS_FAILED,
            'created_at' => now()->subHour(),
        ]);
        $this->assertNull($service->computeActiveRunTiming($failed, [], BrandPipelineRun::EXTRACTION_MODE_TEXT));
    }

    #[Test]
    public function compute_active_run_timing_uses_default_expectation_when_no_median(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));

        $service = new PipelineDurationEstimateService;

        $run = new BrandPipelineRun;
        $run->forceFill([
            'status' => BrandPipelineRun::STATUS_PENDING,
            'created_at' => now()->subMinutes(2),
        ]);

        $estimate = [
            'median_seconds' => null,
            'sample_count' => 0,
            'match' => 'none',
        ];

        $timing = $service->computeActiveRunTiming($run, $estimate, BrandPipelineRun::EXTRACTION_MODE_TEXT);

        $this->assertNotNull($timing);
        $this->assertSame('default', $timing['expectation_source']);
        $this->assertSame(PipelineDurationEstimateService::DEFAULT_EXPECTED_SECONDS_TEXT, $timing['expected_seconds']);
        $this->assertSame(120, $timing['elapsed_seconds']);
        $this->assertFalse($timing['slower_than_expected']);
    }

    #[Test]
    public function compute_active_run_timing_warns_when_elapsed_exceeds_threshold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));

        $service = new PipelineDurationEstimateService;

        $run = new BrandPipelineRun;
        $run->forceFill([
            'status' => BrandPipelineRun::STATUS_PROCESSING,
            'created_at' => now()->subMinutes(8),
        ]);

        $estimate = [
            'median_seconds' => null,
            'sample_count' => 0,
            'match' => 'none',
        ];

        $timing = $service->computeActiveRunTiming($run, $estimate, BrandPipelineRun::EXTRACTION_MODE_TEXT);

        $this->assertNotNull($timing);
        $this->assertTrue($timing['slower_than_expected']);
        $this->assertSame(480, $timing['elapsed_seconds']);
    }

    #[Test]
    public function compute_active_run_timing_uses_median_when_enough_samples(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));

        $service = new PipelineDurationEstimateService;

        $run = new BrandPipelineRun;
        $run->forceFill([
            'status' => BrandPipelineRun::STATUS_PROCESSING,
            'created_at' => now()->subMinutes(4),
        ]);

        $estimate = [
            'median_seconds' => 120,
            'sample_count' => 5,
            'match' => 'similar_size',
        ];

        $timing = $service->computeActiveRunTiming($run, $estimate, BrandPipelineRun::EXTRACTION_MODE_TEXT);

        $this->assertNotNull($timing);
        $this->assertSame('median', $timing['expectation_source']);
        $this->assertSame(120, $timing['expected_seconds']);
        $this->assertSame(240, $timing['elapsed_seconds']);
        $threshold = (int) max(90, (int) ceil(120 * 1.45));
        $this->assertSame(174, $threshold);
        $this->assertTrue($timing['slower_than_expected']);
    }
}
