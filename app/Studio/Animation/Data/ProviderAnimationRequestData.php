<?php

namespace App\Studio\Animation\Data;

final readonly class ProviderAnimationRequestData
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $providerKey,
        public string $providerModelKey,
        public string $startImageDisk,
        public string $startImageStoragePath,
        public string $startImageMimeType,
        public ?string $prompt,
        public ?string $negativePrompt,
        public int $durationSeconds,
        public string $aspectRatio,
        public bool $generateAudio,
        public ?string $motionPresetKey,
        public array $settings = [],
    ) {}
}
