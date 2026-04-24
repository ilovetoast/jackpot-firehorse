<?php

namespace App\Studio\Rendering;

use Illuminate\Support\Facades\Config;

/**
 * Resolves bundled / default font paths from {@see config('studio_rendering.fonts')}.
 */
final class StudioRenderingFontPaths
{
    /**
     * Effective default TTF/OTF path: explicit env/config path when readable, else bundled default_key, else DejaVu path string.
     */
    public static function effectiveDefaultFontPath(): string
    {
        $raw = config('studio_rendering.default_font_path');
        $trimmed = is_string($raw) ? trim($raw) : '';
        if ($trimmed !== '' && is_file($trimmed) && is_readable($trimmed)) {
            return $trimmed;
        }
        $fromKey = self::pathForFullFontKey((string) config('studio_rendering.fonts.default_key', 'bundled:inter-regular'));
        if ($fromKey !== null) {
            return $fromKey;
        }

        return '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    }

    /**
     * @param  non-empty-string  $fullKey  bundled:inter-regular | google:pacifico-regular | tenant:{id}
     */
    public static function pathForFullFontKey(string $fullKey): ?string
    {
        $fullKey = trim($fullKey);
        if ($fullKey === '') {
            return null;
        }
        if (str_starts_with($fullKey, 'bundled:')) {
            $slug = substr($fullKey, strlen('bundled:'));

            return self::bundledPathForSlug($slug);
        }

        return null;
    }

    public static function bundledPathForSlug(string $slug): ?string
    {
        $slug = trim($slug);
        /** @var array<string, array<string, mixed>> $bundled */
        $bundled = is_array(config('studio_rendering.fonts.bundled', []))
            ? config('studio_rendering.fonts.bundled', [])
            : [];
        if (! isset($bundled[$slug]['path'])) {
            return null;
        }
        $p = trim((string) $bundled[$slug]['path']);

        return $p !== '' && is_file($p) ? $p : null;
    }

    /**
     * Sync {@see config('studio_rendering.default_font_path')} at boot when unset/invalid.
     */
    public static function syncDefaultFontConfig(): void
    {
        $candidate = config('studio_rendering.default_font_path');
        $trimmed = is_string($candidate) ? trim($candidate) : '';
        if ($trimmed !== '' && is_file($trimmed) && is_readable($trimmed)) {
            return;
        }
        $resolved = self::effectiveDefaultFontPath();
        if (is_file($resolved) && is_readable($resolved)) {
            Config::set('studio_rendering.default_font_path', $resolved);
        }
    }
}
