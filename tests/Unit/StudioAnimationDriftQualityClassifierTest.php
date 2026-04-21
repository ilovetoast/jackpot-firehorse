<?php

namespace Tests\Unit;

use App\Studio\Animation\Analysis\StudioAnimationDriftQualityClassifier;
use Tests\TestCase;

final class StudioAnimationDriftQualityClassifierTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        config([
            'studio_animation.drift_quality.high_score_threshold' => 0.02,
            'studio_animation.drift_quality.medium_score_threshold' => 0.0,
        ]);
    }

    public function test_match_is_low(): void
    {
        $this->assertSame('low', StudioAnimationDriftQualityClassifier::classify([
            'frame_drift_status' => 'match',
        ]));
    }

    public function test_dimension_mismatch_is_high(): void
    {
        $this->assertSame('high', StudioAnimationDriftQualityClassifier::classify([
            'frame_drift_status' => 'mismatch',
            'mismatch_reasons' => ['dimension_mismatch server=2x2 client=1x1'],
        ]));
    }

    public function test_high_score_threshold(): void
    {
        config(['studio_animation.drift_quality.high_score_threshold' => 0.01]);
        $this->assertSame('high', StudioAnimationDriftQualityClassifier::classify([
            'frame_drift_status' => 'mismatch',
            'mismatch_reasons' => ['sha256_mismatch'],
            'frame_drift_score' => 0.05,
        ]));
    }
}
