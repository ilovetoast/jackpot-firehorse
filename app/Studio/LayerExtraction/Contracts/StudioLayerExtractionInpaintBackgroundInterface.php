<?php

namespace App\Studio\LayerExtraction\Contracts;

use App\Models\Asset;
use App\Models\StudioLayerExtractionSession;

/**
 * Optional contract for providers that can synthesize a full-frame “cleaned” background
 * from the source image and a combined subject mask (not used by the local flood-fill driver).
 */
interface StudioLayerExtractionInpaintBackgroundInterface
{
    public function supportsBackgroundFill(): bool;

    /**
     * @return non-empty-string Image bytes (PNG or JPEG) at the same pixel dimensions as the source.
     */
    public function buildFilledBackground(
        Asset $sourceAsset,
        string $sourceBinary,
        string $combinedForegroundMaskPng,
        StudioLayerExtractionSession $session,
    ): string;
}
