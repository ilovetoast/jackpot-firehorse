<?php

namespace App\Studio\LayerExtraction\Contracts;

use App\Models\Asset;
use App\Studio\LayerExtraction\Dto\LayerExtractionResult;

interface StudioLayerExtractionProviderInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function extractMasks(Asset $asset, array $options = []): LayerExtractionResult;

    public function supportsMultipleMasks(): bool;

    public function supportsBackgroundFill(): bool;

    public function supportsLabels(): bool;

    public function supportsConfidence(): bool;
}
