<?php

namespace App\Support\VideoInsights;

/**
 * Pure preflight for {@see \App\Jobs\GenerateVideoInsightsJob}: no DB, config resolved by caller.
 */
final class VideoInsightsJobPreflight
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function evaluate(string $fileType, bool $videoAiEnabled, array $metadata): VideoInsightsPreflightOutcome
    {
        if ($fileType !== 'video') {
            return VideoInsightsPreflightOutcome::NotVideoClearQueue;
        }

        if (! $videoAiEnabled) {
            return VideoInsightsPreflightOutcome::FeatureDisabled;
        }

        if (! empty($metadata['_skip_ai_video_insights'])) {
            return VideoInsightsPreflightOutcome::UploadOptOut;
        }

        if (! empty($metadata['ai_video_insights_completed_at'])) {
            return VideoInsightsPreflightOutcome::InsightsAlreadyComplete;
        }

        return VideoInsightsPreflightOutcome::Proceed;
    }

    /**
     * When insights are already complete, patch metadata only if status is inconsistent.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function shouldPatchStatusToCompleted(array $metadata): bool
    {
        return ($metadata['ai_video_status'] ?? null) !== 'completed';
    }
}
