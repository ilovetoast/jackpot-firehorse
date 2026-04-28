<?php

namespace App\Studio\LayerExtraction\Sam;

use App\Studio\LayerExtraction\Contracts\SamSegmentationClientInterface;
use RuntimeException;

/**
 * Placeholder: wire Replicate SAM when product requirements and model IDs are finalized.
 */
final class ReplicateSamSegmentationClient implements SamSegmentationClientInterface
{
    public function __construct(
        private readonly ?string $apiToken = null,
    ) {}

    public function isAvailable(): bool
    {
        return false;
    }

    public function autoSegment(string $imageBinary, array $options = []): SamSegmentationResult
    {
        throw new RuntimeException('Replicate SAM is not implemented yet. Use STUDIO_LAYER_EXTRACTION_SAM_PROVIDER=fal and FAL_KEY.');
    }

    public function segmentWithPoints(
        string $imageBinary,
        array $positivePoints,
        array $negativePoints = [],
        array $options = []
    ): SamSegmentationResult {
        throw new RuntimeException('Replicate SAM is not implemented yet. Use STUDIO_LAYER_EXTRACTION_SAM_PROVIDER=fal and FAL_KEY.');
    }

    public function segmentWithBox(string $imageBinary, array $box, array $options = []): SamSegmentationResult
    {
        throw new RuntimeException('Replicate SAM is not implemented yet. Use STUDIO_LAYER_EXTRACTION_SAM_PROVIDER=fal and FAL_KEY.');
    }
}
