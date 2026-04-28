<?php

namespace App\Studio\LayerExtraction\Providers;

use App\Models\Asset;
use App\Models\StudioLayerExtractionSession;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionInpaintBackgroundInterface;
use LogicException;

/**
 * No-op: background fill unavailable (checkbox stays disabled in UI).
 */
final class NullInpaintBackgroundProvider implements StudioLayerExtractionInpaintBackgroundInterface
{
    public function supportsBackgroundFill(): bool
    {
        return false;
    }

    public function buildFilledBackground(
        Asset $sourceAsset,
        string $sourceBinary,
        string $combinedForegroundMaskPng,
        StudioLayerExtractionSession $session,
    ): string {
        throw new LogicException('Inpainting is not enabled for this workspace configuration.');
    }
}
