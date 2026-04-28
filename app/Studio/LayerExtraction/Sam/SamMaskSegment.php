<?php

namespace App\Studio\LayerExtraction\Sam;

/**
 * One full-frame mask + bbox in source pixel space.
 */
final class SamMaskSegment
{
    /**
     * @param  array{x: int, y: int, width: int, height: int}  $bbox
     */
    public function __construct(
        public string $maskPngBinary,
        public array $bbox,
        public ?float $confidence,
        public string $label,
    ) {}
}
