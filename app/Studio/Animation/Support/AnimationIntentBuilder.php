<?php

namespace App\Studio\Animation\Support;

use App\Studio\Animation\Enums\StudioAnimationSourceStrategy;

final class AnimationIntentBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(
        StudioAnimationSourceStrategy $sourceStrategy,
        string $motionPresetKey,
        int $durationSeconds,
        string $aspectRatio,
        bool $audioRequested,
        string $providerKey,
        string $providerModelKey,
    ): array {
        $presetCfg = self::presetConfig($motionPresetKey);

        return [
            'schema_version' => 2,
            'intent_version' => '1.4.0',
            'mode' => 'animate_composition',
            'source_kind' => match ($sourceStrategy) {
                StudioAnimationSourceStrategy::CompositionSnapshot => 'composition_snapshot',
                default => $sourceStrategy->value,
            },
            'motion_style' => $motionPresetKey,
            'camera_behavior' => (string) ($presetCfg['camera_behavior'] ?? 'balanced'),
            'subject_priority' => (string) ($presetCfg['subject_priority'] ?? 'balanced'),
            'text_safety' => (string) ($presetCfg['text_safety'] ?? 'standard'),
            'duration_seconds' => $durationSeconds,
            'aspect_ratio' => $aspectRatio,
            'audio_requested' => $audioRequested,
            'provider_key' => $providerKey,
            'provider_model_key' => $providerModelKey,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presetConfig(string $motionPresetKey): array
    {
        $presets = config('studio_animation.motion_presets', []);
        if (! is_array($presets)) {
            return [];
        }
        $one = $presets[$motionPresetKey] ?? [];

        return is_array($one) ? $one : [];
    }
}
