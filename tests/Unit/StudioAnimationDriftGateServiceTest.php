<?php

namespace Tests\Unit;

use App\Studio\Animation\Services\StudioAnimationDriftGateService;
use Tests\TestCase;

final class StudioAnimationDriftGateServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        config([
            'studio_animation.drift_gate.enabled' => false,
            'studio_animation.drift_gate.mode' => 'warn_only',
            'studio_animation.drift_gate.score_threshold' => 0.15,
            'studio_animation.drift_gate.strict_drift_block' => false,
        ]);
    }

    public function test_warn_only_never_blocks(): void
    {
        config([
            'studio_animation.drift_gate.enabled' => true,
            'studio_animation.drift_gate.mode' => 'warn_only',
        ]);
        $svc = new StudioAnimationDriftGateService;
        $d = $svc->evaluate([
            'frame_drift_status' => 'mismatch',
            'frame_drift_score' => 99.0,
        ], []);
        $this->assertTrue($d['drift_checked']);
        $this->assertTrue($d['drift_warned']);
        $this->assertFalse($d['drift_blocked']);
        $this->assertFalse($svc->shouldAbortSubmission($d));
    }

    public function test_block_any_blocks_on_mismatch(): void
    {
        config([
            'studio_animation.drift_gate.enabled' => true,
            'studio_animation.drift_gate.mode' => 'block_any',
        ]);
        $svc = new StudioAnimationDriftGateService;
        $d = $svc->evaluate(['frame_drift_status' => 'mismatch'], []);
        $this->assertTrue($d['drift_blocked']);
        $this->assertTrue($svc->shouldAbortSubmission($d));
    }

    public function test_block_high_blocks_on_preflight_high(): void
    {
        config([
            'studio_animation.drift_gate.enabled' => true,
            'studio_animation.drift_gate.mode' => 'block_high',
        ]);
        $svc = new StudioAnimationDriftGateService;
        $d = $svc->evaluate(
            ['frame_drift_status' => 'mismatch', 'frame_drift_score' => 0.01],
            ['preflight_risk' => ['risk_level' => 'high']],
        );
        $this->assertTrue($d['drift_blocked']);
    }

    public function test_block_high_blocks_on_score_threshold(): void
    {
        config([
            'studio_animation.drift_gate.enabled' => true,
            'studio_animation.drift_gate.mode' => 'block_high',
            'studio_animation.drift_gate.score_threshold' => 0.1,
        ]);
        $svc = new StudioAnimationDriftGateService;
        $d = $svc->evaluate(
            ['frame_drift_status' => 'mismatch', 'frame_drift_score' => 0.5],
            ['preflight_risk' => ['risk_level' => 'low']],
        );
        $this->assertTrue($d['drift_blocked']);
        $this->assertSame('drift_mismatch_score_threshold', $d['blocked_reason']);
    }

    public function test_block_high_does_not_block_low_risk_mismatch_with_low_score(): void
    {
        config([
            'studio_animation.drift_gate.enabled' => true,
            'studio_animation.drift_gate.mode' => 'block_high',
            'studio_animation.drift_gate.score_threshold' => 0.9,
        ]);
        $svc = new StudioAnimationDriftGateService;
        $d = $svc->evaluate(
            ['frame_drift_status' => 'mismatch', 'frame_drift_score' => 0.01],
            ['preflight_risk' => ['risk_level' => 'low']],
        );
        $this->assertFalse($d['drift_blocked']);
        $this->assertTrue($d['drift_warned']);
    }

    public function test_block_high_blocks_when_drift_level_high(): void
    {
        config([
            'studio_animation.drift_gate.enabled' => true,
            'studio_animation.drift_gate.mode' => 'block_high',
            'studio_animation.drift_gate.score_threshold' => 0.9,
        ]);
        $svc = new StudioAnimationDriftGateService;
        $d = $svc->evaluate(
            [
                'frame_drift_status' => 'mismatch',
                'frame_drift_score' => 0.01,
                'drift_level' => 'high',
            ],
            ['preflight_risk' => ['risk_level' => 'low']],
        );
        $this->assertTrue($d['drift_blocked']);
        $this->assertSame('drift_level_high', $d['blocked_reason']);
    }

    public function test_strict_block_high_blocks_medium_drift(): void
    {
        config([
            'studio_animation.drift_gate.enabled' => true,
            'studio_animation.drift_gate.mode' => 'block_high',
            'studio_animation.drift_gate.strict_drift_block' => true,
            'studio_animation.drift_gate.score_threshold' => 0.9,
        ]);
        $svc = new StudioAnimationDriftGateService;
        $d = $svc->evaluate(
            [
                'frame_drift_status' => 'mismatch',
                'drift_level' => 'medium',
            ],
            ['preflight_risk' => ['risk_level' => 'low']],
        );
        $this->assertTrue($d['drift_blocked']);
        $this->assertSame('drift_level_medium_or_high_strict', $d['blocked_reason']);
    }
}
