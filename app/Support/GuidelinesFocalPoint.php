<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Optional focal point (0–1) for brand guidelines imagery — drives CSS object-position with object-fit: cover.
 * Guidelines-specific override wins; otherwise uses library/AI {@see Asset} `metadata.focal_point`.
 */
final class GuidelinesFocalPoint
{
    /**
     * For published guidelines: guidelines-only override, else general focal point (AI or library).
     *
     * @return array{x: float, y: float}|null
     */
    public static function fromAsset(Asset $asset): ?array
    {
        $meta = $asset->metadata ?? [];
        $fp = $meta['guidelines_focal_point'] ?? null;
        if (is_array($fp)) {
            $n = self::normalizePoint($fp);
            if ($n !== null) {
                return $n;
            }
        }

        return self::generalFocalPointFromAsset($asset);
    }

    /**
     * General focal point from metadata (AI or manual in asset drawer).
     *
     * @return array{x: float, y: float}|null
     */
    public static function generalFocalPointFromAsset(Asset $asset): ?array
    {
        $meta = $asset->metadata ?? [];
        $fp = $meta['focal_point'] ?? null;
        if (! is_array($fp)) {
            return null;
        }

        return self::normalizePoint($fp);
    }

    /**
     * @param  array<string, mixed>  $fp
     * @return array{x: float, y: float}|null
     */
    private static function normalizePoint(array $fp): ?array
    {
        $x = isset($fp['x']) ? (float) $fp['x'] : null;
        $y = isset($fp['y']) ? (float) $fp['y'] : null;
        if ($x === null || $y === null) {
            return null;
        }

        return [
            'x' => max(0.0, min(1.0, $x)),
            'y' => max(0.0, min(1.0, $y)),
        ];
    }
}
