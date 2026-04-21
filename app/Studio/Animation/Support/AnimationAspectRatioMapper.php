<?php

namespace App\Studio\Animation\Support;

final class AnimationAspectRatioMapper
{
    /**
     * @return list<string>
     */
    public static function supportedKeys(): array
    {
        $keys = config('studio_animation.supported_aspect_ratios', []);

        return is_array($keys) ? array_values(array_filter(array_map('strval', $keys))) : ['16:9', '9:16', '1:1', '4:5'];
    }

    public static function isSupported(string $key): bool
    {
        return in_array($key, self::supportedKeys(), true);
    }
}
