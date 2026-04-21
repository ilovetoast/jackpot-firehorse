<?php

namespace App\Studio\Animation\Support;

final class MotionPresetCatalog
{
    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function presets(): array
    {
        $fromConfig = config('studio_animation.motion_presets', []);

        return is_array($fromConfig) && $fromConfig !== [] ? $fromConfig : [
            'cinematic_pan' => [
                'label' => 'Cinematic pan',
                'description' => 'Slow camera drift with gentle parallax; premium ad feel.',
            ],
            'subtle_alive' => [
                'label' => 'Subtle alive',
                'description' => 'Light ambient motion — fabric, light, particles — without reshaping layout.',
            ],
            'hero_reveal' => [
                'label' => 'Hero reveal',
                'description' => 'Dramatic push-in toward the focal hero with controlled depth.',
            ],
            'product_orbit' => [
                'label' => 'Product orbit',
                'description' => 'Soft 3D-style orbit around the main product or pack shot.',
            ],
        ];
    }

    public static function isValid(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        return isset(self::presets()[$key]);
    }

    public static function defaultKey(): string
    {
        return (string) config('studio_animation.default_motion_preset', 'cinematic_pan');
    }
}
