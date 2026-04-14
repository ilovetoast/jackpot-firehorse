<?php

namespace App\Support;

/**
 * Resolves the largest available pipeline raster for Studio View (manual crop canvas).
 * Prefers original mode (truthful source) then preferred, largest style first.
 */
final class StudioViewSourceResolver
{
    /**
     * @return array{0: string|null, 1: string, 2: string} [s3 path, thumbnail mode, style]
     */
    public static function resolveLargeRasterPath(array $versionMetadata): array
    {
        $styles = ['large', 'medium', 'thumb'];
        $modes = [
            ThumbnailMode::Original->value,
            ThumbnailMode::Preferred->value,
        ];

        foreach ($styles as $style) {
            foreach ($modes as $mode) {
                $p = ThumbnailMetadata::stylePath($versionMetadata, $style, $mode);
                if ($p !== null && $p !== '') {
                    return [$p, $mode, $style];
                }
            }
        }

        return [null, '', ''];
    }
}
