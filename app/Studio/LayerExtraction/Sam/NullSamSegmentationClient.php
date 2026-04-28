<?php

namespace App\Studio\LayerExtraction\Sam;

use App\Studio\LayerExtraction\Contracts\SamSegmentationClientInterface;
use RuntimeException;

/**
 * Used when no remote driver is configured (Fal key missing, or provider=floodfill, etc.).
 */
final class NullSamSegmentationClient implements SamSegmentationClientInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function autoSegment(string $imageBinary, array $options = []): SamSegmentationResult
    {
        throw new RuntimeException('Remote SAM segmentation is not configured.');
    }

    public function segmentWithPoints(
        string $imageBinary,
        array $positivePoints,
        array $negativePoints = [],
        array $options = []
    ): SamSegmentationResult {
        throw new RuntimeException('Remote SAM segmentation is not configured.');
    }

    public function segmentWithBox(string $imageBinary, array $box, array $options = []): SamSegmentationResult
    {
        throw new RuntimeException('Remote SAM segmentation is not configured.');
    }
}
