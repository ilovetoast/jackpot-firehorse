<?php

namespace App\Services\Studio;

/**
 * Resolves {@see config('studio_rendering.driver')} with optional {@see config('studio_rendering.force_driver')}.
 */
final class StudioRenderingDriverResolver
{
    public const FFMPEG_NATIVE = 'ffmpeg_native';

    public const BROWSER_CANVAS = 'browser_canvas';

    public static function effectiveDriver(): string
    {
        $forced = trim((string) config('studio_rendering.force_driver', ''));
        if ($forced !== '' && in_array($forced, [self::FFMPEG_NATIVE, self::BROWSER_CANVAS], true)) {
            return $forced;
        }
        $d = trim((string) config('studio_rendering.driver', self::FFMPEG_NATIVE));

        return in_array($d, [self::FFMPEG_NATIVE, self::BROWSER_CANVAS], true) ? $d : self::FFMPEG_NATIVE;
    }
}
