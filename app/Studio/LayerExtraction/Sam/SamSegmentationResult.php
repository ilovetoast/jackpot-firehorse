<?php

namespace App\Studio\LayerExtraction\Sam;

/**
 * Normalized result from a remote SAM driver (Fal, Replicate, etc.).
 */
final class SamSegmentationResult
{
    /**
     * @param  list<SamMaskSegment>  $segments
     */
    public function __construct(
        public array $segments,
        public string $model,
        public int $durationMs,
        public string $engine,
    ) {}
}
