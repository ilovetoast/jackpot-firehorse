<?php

namespace App\Studio\Animation\Data;

use App\Studio\Animation\Enums\StudioAnimationRenderRole;

final readonly class StudioAnimationRenderData
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $jobSettingsPatch
     */
    public function __construct(
        public StudioAnimationRenderRole $role,
        public string $disk,
        public string $path,
        public string $mimeType,
        public ?int $width,
        public ?int $height,
        public ?string $sha256,
        public ?string $assetId,
        public array $metadata = [],
        public ?array $jobSettingsPatch = null,
    ) {}
}
