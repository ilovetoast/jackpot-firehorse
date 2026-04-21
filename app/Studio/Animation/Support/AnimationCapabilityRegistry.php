<?php

namespace App\Studio\Animation\Support;

final class AnimationCapabilityRegistry
{
    /**
     * @return array<string, bool>
     */
    public static function forProvider(string $providerKey): array
    {
        $providers = config('studio_animation.providers', []);

        return is_array($providers[$providerKey]['capabilities'] ?? null)
            ? $providers[$providerKey]['capabilities']
            : self::defaults();
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            'supports_end_frame' => false,
            'supports_elements' => false,
            'supports_multi_shot' => false,
            'supports_audio' => true,
            'supports_layer_source' => false,
        ];
    }
}
