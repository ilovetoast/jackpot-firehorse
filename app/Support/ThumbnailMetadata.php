<?php

namespace App\Support;

/**
 * Helpers for versioned thumbnail metadata: {@see ThumbnailMode} buckets under metadata.thumbnails.
 *
 * Legacy flat shape (metadata.thumbnails.thumb) remains supported for reads.
 */
final class ThumbnailMetadata
{
    public const DEFAULT_MODE = 'original';

    /**
     * @param  array<string, mixed>  $metadata  Asset or version metadata
     * @return array<string, mixed>|null Style row (path, width, …)
     */
    public static function styleData(array $metadata, string $style, ?string $mode = null): ?array
    {
        $mode ??= self::DEFAULT_MODE;
        $thumbs = $metadata['thumbnails'] ?? [];
        if (! is_array($thumbs)) {
            return null;
        }
        if (isset($thumbs[$mode][$style]) && is_array($thumbs[$mode][$style])) {
            return $thumbs[$mode][$style];
        }
        if (isset($thumbs[$style]) && is_array($thumbs[$style]) && isset($thumbs[$style]['path'])) {
            return $thumbs[$style];
        }

        return null;
    }

    public static function stylePath(array $metadata, string $style, ?string $mode = null): ?string
    {
        $data = self::styleData($metadata, $style, $mode);
        if (! is_array($data)) {
            return null;
        }

        return isset($data['path']) && is_string($data['path']) ? $data['path'] : null;
    }

    public static function hasThumb(array $metadata): bool
    {
        return self::stylePath($metadata, 'thumb') !== null;
    }

    public static function hasMediumOrThumb(array $metadata): bool
    {
        return self::stylePath($metadata, 'medium') !== null
            || self::stylePath($metadata, 'thumb') !== null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function previewStyleData(array $metadata, ?string $mode = null): ?array
    {
        $mode ??= self::DEFAULT_MODE;
        $thumbs = $metadata['thumbnails'] ?? [];
        if (is_array($thumbs) && isset($thumbs[$mode]['preview']) && is_array($thumbs[$mode]['preview'])) {
            return $thumbs[$mode]['preview'];
        }
        $pt = $metadata['preview_thumbnails'] ?? [];
        if (is_array($pt) && isset($pt[$mode]['preview']) && is_array($pt[$mode]['preview'])) {
            return $pt[$mode]['preview'];
        }
        if (is_array($pt) && isset($pt['preview']) && is_array($pt['preview'])) {
            return $pt['preview'];
        }
        if (is_array($thumbs) && isset($thumbs['preview']) && is_array($thumbs['preview'])) {
            return $thumbs['preview'];
        }

        return null;
    }

    public static function previewPath(array $metadata, ?string $mode = null): ?string
    {
        $data = self::previewStyleData($metadata, $mode);
        if (! is_array($data)) {
            return null;
        }

        return isset($data['path']) && is_string($data['path']) ? $data['path'] : null;
    }

    /**
     * @return array{width?: int, height?: int}|null
     */
    public static function dimensionsForStyle(array $metadata, string $style, ?string $mode = null): ?array
    {
        $mode ??= self::DEFAULT_MODE;
        $dims = $metadata['thumbnail_dimensions'] ?? [];
        if (! is_array($dims)) {
            return null;
        }
        if (isset($dims[$mode][$style]) && is_array($dims[$mode][$style])) {
            return $dims[$mode][$style];
        }
        if (isset($dims[$style]) && is_array($dims[$style])) {
            return $dims[$style];
        }

        return null;
    }

    public static function isModeKey(string $key): bool
    {
        return ThumbnailMode::tryFromLoose($key) !== null;
    }

    /**
     * Thumbnail buckets keyed by mode (same as {@see $metadata['thumbnails']}).
     * Alias for API/docs: "thumbnails_by_mode" in product language.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function thumbnailsByMode(array $metadata): array
    {
        $root = $metadata['thumbnails'] ?? null;

        return is_array($root) ? $root : [];
    }

    /**
     * Invoke callback ($mode, $style, $info) for each thumbnail style row.
     * Legacy flat entries (thumbnails.thumb) are reported as mode {@see DEFAULT_MODE}.
     *
     * @param  array<string, mixed>  $thumbnailsRoot  metadata.thumbnails
     * @param  callable(string, string, array): void  $callback
     */
    public static function walkThumbnailStyles(array $thumbnailsRoot, callable $callback): void
    {
        foreach ($thumbnailsRoot as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if (isset($value['path'])) {
                $callback(self::DEFAULT_MODE, $key, $value);

                continue;
            }
            if (self::isModeKey($key)) {
                foreach ($value as $style => $info) {
                    if (is_array($info) && isset($info['path'])) {
                        $callback($key, (string) $style, $info);
                    }
                }
            }
        }
    }

    /**
     * Collect thumbnail object paths from metadata (thumbnails + nested preview_thumbnails).
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    public static function allThumbnailObjectPaths(array $metadata): array
    {
        $paths = [];
        $thumbs = $metadata['thumbnails'] ?? [];
        if (is_array($thumbs)) {
            self::walkThumbnailStyles($thumbs, function (string $mode, string $style, array $info) use (&$paths): void {
                if (! empty($info['path']) && is_string($info['path'])) {
                    $paths[] = $info['path'];
                }
            });
        }
        $pt = $metadata['preview_thumbnails'] ?? [];
        if (is_array($pt)) {
            foreach ($pt as $entry) {
                if (is_array($entry) && isset($entry['path']) && is_string($entry['path'])) {
                    $paths[] = $entry['path'];
                } elseif (is_array($entry)) {
                    foreach ($entry as $sub) {
                        if (is_array($sub) && ! empty($sub['path']) && is_string($sub['path'])) {
                            $paths[] = $sub['path'];
                        }
                    }
                }
            }
        }

        return array_values(array_filter(array_unique($paths)));
    }
}
