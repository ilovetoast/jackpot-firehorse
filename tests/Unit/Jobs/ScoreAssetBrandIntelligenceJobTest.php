<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ScoreAssetBrandIntelligenceJob;
use Tests\TestCase;

class ScoreAssetBrandIntelligenceJobTest extends TestCase
{
    public function test_defaults_to_standard_scan_mode_string(): void
    {
        $job = new ScoreAssetBrandIntelligenceJob('asset-uuid-1', false);
        $this->assertSame('standard', $job->pdfScanMode);
    }

    public function test_accepts_deep_scan_mode(): void
    {
        $job = new ScoreAssetBrandIntelligenceJob('asset-uuid-1', true, 'deep');
        $this->assertSame('deep', $job->pdfScanMode);
    }
}
