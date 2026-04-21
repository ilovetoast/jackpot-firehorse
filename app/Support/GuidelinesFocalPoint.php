<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Optional focal point (0–1) for brand guidelines imagery — drives CSS object-position with object-fit: cover.
 */
final class GuidelinesFocalPoint
{
    /**
     * @return array{x: float, y: float}|null
     */
    public static function fromAsset(Asset $asset): ?array
    {
        $meta = $asset->metadata ?? [];
        $fp = $meta['guidelines_focal_point'] ?? null;
        if (! is_array($fp)) {
            return null;
        }
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
