<?php

namespace App\Studio\Rendering\Dto;

/**
 * Normalized overlay list plus FFmpeg-native export diagnostics from {@see CompositionRenderNormalizer}.
 */
final readonly class CompositionRenderPlan
{
    /**
     * @param  list<RenderLayer>  $overlayLayers
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public array $overlayLayers,
        public array $diagnostics,
    ) {}
}
