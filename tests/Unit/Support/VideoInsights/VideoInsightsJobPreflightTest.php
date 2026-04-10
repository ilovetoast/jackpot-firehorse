<?php

namespace Tests\Unit\Support\VideoInsights;

use App\Support\VideoInsights\VideoInsightsJobPreflight;
use App\Support\VideoInsights\VideoInsightsPreflightOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Pure preflight logic — no Laravel container, DB, or filesystem.
 *
 * @group pure-unit
 */
final class VideoInsightsJobPreflightTest extends TestCase
{
    public static function evaluateCases(): array
    {
        return [
            'not_video' => [
                'image',
                true,
                [],
                VideoInsightsPreflightOutcome::NotVideoClearQueue,
            ],
            'feature_disabled' => [
                'video',
                false,
                ['ai_video_status' => 'queued'],
                VideoInsightsPreflightOutcome::FeatureDisabled,
            ],
            'upload_opt_out' => [
                'video',
                true,
                ['_skip_ai_video_insights' => true],
                VideoInsightsPreflightOutcome::UploadOptOut,
            ],
            'already_complete' => [
                'video',
                true,
                ['ai_video_insights_completed_at' => '2020-01-01T00:00:00Z'],
                VideoInsightsPreflightOutcome::InsightsAlreadyComplete,
            ],
            'proceed' => [
                'video',
                true,
                ['ai_video_status' => 'queued'],
                VideoInsightsPreflightOutcome::Proceed,
            ],
        ];
    }

    /** @dataProvider evaluateCases */
    public function test_evaluate(
        string $fileType,
        bool $videoAiEnabled,
        array $metadata,
        VideoInsightsPreflightOutcome $expected,
    ): void {
        $actual = VideoInsightsJobPreflight::evaluate($fileType, $videoAiEnabled, $metadata);
        $this->assertSame($expected, $actual);
    }

    public function test_should_patch_status_when_not_completed(): void
    {
        $this->assertTrue(VideoInsightsJobPreflight::shouldPatchStatusToCompleted([
            'ai_video_insights_completed_at' => '2020-01-01T00:00:00Z',
            'ai_video_status' => 'processing',
        ]));
    }

    public function test_should_not_patch_when_already_completed(): void
    {
        $this->assertFalse(VideoInsightsJobPreflight::shouldPatchStatusToCompleted([
            'ai_video_insights_completed_at' => '2020-01-01T00:00:00Z',
            'ai_video_status' => 'completed',
        ]));
    }
}
