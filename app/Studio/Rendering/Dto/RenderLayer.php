<?php

namespace App\Studio\Rendering\Dto;

/**
 * Normalized layer for FFmpeg composition (renderer-agnostic fields).
 */
final readonly class RenderLayer
{
    /**
     * @param  'video'|'image'|'text'  $type
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $id,
        public string $type,
        public int $zIndex,
        public float $startSeconds,
        public float $endSeconds,
        public bool $visible,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
        public float $opacity,
        public float $rotationDegrees,
        public string $fit,
        public bool $isPrimaryVideo,
        /** Absolute path to staged media (video/image) or rasterized PNG (text) */
        public ?string $mediaPath,
        public int $trimInMs,
        public int $trimOutMs,
        public bool $muted,
        public int $fadeInMs,
        public int $fadeOutMs,
        public array $extra = [],
    ) {}
}
