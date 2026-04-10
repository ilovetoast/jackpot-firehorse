<?php

namespace App\Services;

use App\Models\Asset;

/**
 * Estimates billable video minutes (capped timeline) for plan enforcement before expensive AI calls.
 */
class VideoAiMinuteEstimator
{
    public function __construct(
        protected VideoPreviewGenerationService $videoDownload,
        protected VideoFrameExtractor $frameExtractor,
    ) {}

    public function estimateBillableMinutesForAsset(Asset $asset): float
    {
        $tmp = $this->videoDownload->downloadSourceToTemp($asset);
        try {
            return $this->frameExtractor->estimateBillableMinutes($tmp);
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
