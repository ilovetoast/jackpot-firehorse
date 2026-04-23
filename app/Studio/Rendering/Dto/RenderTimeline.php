<?php

namespace App\Studio\Rendering\Dto;

final readonly class RenderTimeline
{
    public function __construct(
        public int $width,
        public int $height,
        public int $fps,
        public int $durationMs,
        /** FFmpeg pad color e.g. black or 0xRRGGBB */
        public string $padColorFfmpeg,
    ) {}

    public function outputDurationSeconds(): float
    {
        return max(0.04, $this->durationMs / 1000.0);
    }
}
