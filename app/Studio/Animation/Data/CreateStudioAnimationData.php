<?php

namespace App\Studio\Animation\Data;

use App\Studio\Animation\Enums\StudioAnimationSourceStrategy;

final readonly class CreateStudioAnimationData
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public int $tenantId,
        public int $brandId,
        public int $userId,
        public ?int $compositionId,
        public string $provider,
        public string $providerModel,
        public StudioAnimationSourceStrategy $sourceStrategy,
        public ?string $prompt,
        public ?string $negativePrompt,
        public ?string $motionPreset,
        public int $durationSeconds,
        public string $aspectRatio,
        public bool $generateAudio,
        public string $compositionSnapshotPngBase64,
        public int $snapshotWidth,
        public int $snapshotHeight,
        /** @var array<string, mixed>|null */
        public ?array $documentJson = null,
        public ?int $sourceCompositionVersionId = null,
        public bool $highFidelitySubmit = false,
        public array $settings = [],
    ) {}
}
