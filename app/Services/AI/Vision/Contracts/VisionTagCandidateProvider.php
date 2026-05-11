<?php

namespace App\Services\AI\Vision\Contracts;

use App\Models\Asset;
use App\Models\Category;
use App\Services\AI\Vision\VisionTagCandidateResult;

/**
 * Vision tag candidate provider — image tags only.
 *
 * Implementations must:
 *   - Read directly from S3 via IAM-authenticated SDK calls (S3Object for Rekognition).
 *   - Never expose presigned or CloudFront URLs to the upstream AI provider.
 *   - Convert provider confidences to a 0–1 scale.
 *   - Return raw provider response for admin diagnostics.
 *   - Throw on transport/AWS errors so the caller can decide retry vs fallback.
 */
interface VisionTagCandidateProvider
{
    /**
     * Detect tag candidates for the asset.
     *
     * Caller is responsible for running candidates through the shared
     * sanitizer/category-ban/blocklist pipeline before persisting.
     *
     * @throws \Throwable On transport/SDK errors. Recoverable AWS errors (throttling)
     *                    propagate so the queue worker can retry; permanent errors
     *                    (invalid format/missing object) are also thrown but the
     *                    caller catches and degrades safely.
     */
    public function detectTagsForAsset(Asset $asset, ?Category $category = null): VisionTagCandidateResult;

    /**
     * Stable provider key for AIAgentRun / ai_usage / config (e.g. 'aws_rekognition').
     */
    public function getProviderName(): string;
}
