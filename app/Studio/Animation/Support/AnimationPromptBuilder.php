<?php

namespace App\Studio\Animation\Support;

final class AnimationPromptBuilder
{
    public static function compose(?string $userPrompt, ?string $motionPresetKey): string
    {
        $presetLine = '';
        if ($motionPresetKey !== null && $motionPresetKey !== '') {
            $presets = MotionPresetCatalog::presets();
            $meta = $presets[$motionPresetKey] ?? null;
            if (is_array($meta) && isset($meta['description'])) {
                $presetLine = trim((string) $meta['description']);
            }
        }

        $user = trim((string) ($userPrompt ?? ''));

        if ($presetLine !== '' && $user !== '') {
            return $presetLine."\n\n".$user;
        }

        return $presetLine !== '' ? $presetLine : $user;
    }
}
