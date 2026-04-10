<?php

namespace App\Support\VideoInsights;

/**
 * Result of cheap, I/O-free checks in {@see VideoInsightsJobPreflight} before policy, storage, and AI work.
 */
enum VideoInsightsPreflightOutcome
{
    /** Continue to policy / storage / analyze. */
    case Proceed;
    /** Not a video file — clear orphan queued/processing flags only. */
    case NotVideoClearQueue;
    case FeatureDisabled;
    case UploadOptOut;
    /** Insights already written — optionally normalize ai_video_status only. */
    case InsightsAlreadyComplete;
}
