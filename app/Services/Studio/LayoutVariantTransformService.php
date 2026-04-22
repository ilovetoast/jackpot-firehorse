<?php

namespace App\Services\Studio;

/**
 * Structured size/layout reflow (composition JSON) — the default for layout variant families; does not use raster image generation.
 */
final class LayoutVariantTransformService
{
    public function __construct(
        protected StudioCompositionFormatReflowService $reflow,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function reflowToTargetCanvasSize(array $document, int $targetWidth, int $targetHeight): array
    {
        return $this->reflow->reflowToCanvasSize($document, $targetWidth, $targetHeight);
    }
}
