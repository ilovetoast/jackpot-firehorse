<?php

namespace Tests\Unit;

use App\Models\StudioAnimationJob;
use App\Studio\Animation\Support\StudioAnimationObservability;
use Tests\TestCase;

final class StudioAnimationObservabilityTest extends TestCase
{
    public function test_compact_drift_decision_serializes_known_keys(): void
    {
        $s = StudioAnimationObservability::compactDriftDecision([
            'drift_checked' => true,
            'drift_warned' => false,
            'drift_blocked' => true,
            'gate_enabled' => true,
            'gate_mode' => 'block_high',
            'blocked_reason' => 'drift_level_high',
        ]);
        $this->assertStringContainsString('drift_blocked=1', (string) $s);
        $this->assertStringContainsString('gate_mode=block_high', (string) $s);
    }

    public function test_enabled_reflects_config(): void
    {
        config(['studio_animation.observability.enabled' => true]);
        $this->assertTrue(StudioAnimationObservability::enabled());

        config(['studio_animation.observability.enabled' => false]);
        $this->assertFalse(StudioAnimationObservability::enabled());
    }

    public function test_rollout_dimensions_are_compact_and_stable_keys(): void
    {
        $job = new StudioAnimationJob([
            'id' => 42,
            'status' => 'processing',
            'provider' => 'kling',
            'settings_json' => [
                'canonical_frame' => [
                    'render_engine' => 'browser_headless_official',
                    'renderer_version' => '2.1.0',
                    'drift_level' => 'low',
                    'provider_submit_start_image_origin' => 'server_locked_state',
                ],
                'drift_decision' => [
                    'drift_checked' => true,
                    'drift_blocked' => false,
                    'gate_mode' => 'warn_only',
                ],
                'last_webhook_verified' => true,
                'finalize_reuse_mode' => 'fingerprint',
            ],
        ]);

        $dims = StudioAnimationObservability::rolloutDimensions($job);
        $this->assertSame(42, $dims['job_id']);
        $this->assertSame('processing', $dims['status']);
        $this->assertSame('kling', $dims['provider']);
        $this->assertSame('browser_headless_official', $dims['render_engine']);
        $this->assertSame('2.1.0', $dims['renderer_version']);
        $this->assertSame('low', $dims['drift_level']);
        $this->assertStringContainsString('drift_checked=1', (string) $dims['drift_decision']);
        $this->assertTrue($dims['verified_webhook']);
        $this->assertSame('fingerprint', $dims['finalize_reuse_mode']);
        $this->assertSame('server_locked_state', $dims['provider_submission_used_frame']);
    }

    public function test_emit_metric_line_reflects_config(): void
    {
        config(['studio_animation.observability.emit_metric_line' => true]);
        $this->assertTrue(StudioAnimationObservability::emitMetricLineEnabled());

        config(['studio_animation.observability.emit_metric_line' => false]);
        $this->assertFalse(StudioAnimationObservability::emitMetricLineEnabled());
    }

    public function test_extras_allowed_on_metric_line_filters_keys(): void
    {
        $x = StudioAnimationObservability::extrasAllowedOnMetricLine([
            'drift_decision' => 'drift_checked=1',
            'exc' => 'RuntimeException',
            'noise' => 'drop-me',
        ]);
        $this->assertSame(['drift_decision' => 'drift_checked=1', 'exc' => 'RuntimeException'], $x);
    }
}
