<?php

namespace App\Studio\LayerExtraction\Contracts;

use App\Studio\LayerExtraction\Sam\SamSegmentationResult;

/**
 * HTTP driver for remote SAM / SAM2 segmentation (Fal, Replicate, etc.).
 * Implementations must not log signed URLs, raw image URLs, or request bodies with secrets.
 */
interface SamSegmentationClientInterface
{
    public function isAvailable(): bool;

    /**
     * @param  array{max_bytes?: int, max_long_edge?: int, timeout_seconds?: int}  $options
     */
    public function autoSegment(string $imageBinary, array $options = []): SamSegmentationResult;

    /**
     * @param  list<array{x: float, y: float}>  $positivePoints  Normalized 0..1 in source image space
     * @param  list<array{x: float, y: float}>  $negativePoints
     * @param  array{orig_width?: int, orig_height?: int, max_bytes?: int, max_long_edge?: int, timeout_seconds?: int, image_mime?: string}  $options
     */
    public function segmentWithPoints(
        string $imageBinary,
        array $positivePoints,
        array $negativePoints = [],
        array $options = []
    ): SamSegmentationResult;

    /**
     * @param  array{x_min: int, y_min: int, x_max: int, y_max: int}  $boxPixels
     * @param  array{max_bytes?: int, max_long_edge?: int, timeout_seconds?: int}  $options
     */
    public function segmentWithBox(string $imageBinary, array $boxPixels, array $options = []): SamSegmentationResult;
}
