<?php

namespace App\Services;

/**
 * Color Bucket Service
 *
 * Converts dominant_color_bucket strings (e.g. "L50_A10_B20") to representative hex colors
 * for swatch display in the filter UI. Uses existing bucket values only; no color analysis.
 *
 * Bucket format: "L{L}_A{A}_B{B}" where L in [0,100], A and B in [-128,127] (CIE LAB).
 * Also supports macro names from ColorAnalysisService (black, white, red, etc.) via fallback map.
 *
 * NOTE: Bucket swatch colors are visual representatives only.
 * Buckets are semantic groupings, not literal colors.
 */
class ColorBucketService
{
    /**
     * Macro bucket names from ColorAnalysisService::labToBucket() -> hex for swatch display.
     * Only used when bucket string is not in L*_A*_B* format.
     */
    private const MACRO_BUCKET_HEX = [
        'black' => '#1a1a1a',
        'white' => '#f5f5f5',
        'gray' => '#808080',
        'red' => '#e53935',
        'green' => '#43a047',
        'blue' => '#1e88e5',
        'yellow' => '#fdd835',
        'orange' => '#fb8c00',
        'purple' => '#8e24aa',
        'pink' => '#ec407a',
        'brown' => '#6d4c41',
    ];

    /**
     * Convert a bucket string to a representative hex color.
     *
     * @param string $bucket e.g. "L50_A10_B20" or "red"
     * @return string Hex color in format #RRGGBB, or fallback #808080 if conversion fails
     */
    public function bucketToHex(string $bucket): string
    {
        $bucket = trim($bucket);
        if ($bucket === '') {
            return '#808080';
        }

        // Macro name from ColorAnalysisService - keep explicit mappings
        $lower = strtolower($bucket);
        if (isset(self::MACRO_BUCKET_HEX[$lower])) {
            return self::MACRO_BUCKET_HEX[$lower];
        }

        // LAB bucket strings: use stable hash-based representative colors
        // NOTE: Bucket swatch colors are visual representatives only.
        // Buckets are semantic groupings, not literal colors.
        $hash = crc32($bucket);
        $h = $hash % 360;
        $s = 45;
        $l = 55;

        return self::hslToHex($h, $s, $l);
    }

    /**
     * Convert HSL to hex color.
     *
     * @param int|float $h Hue in degrees [0-360]
     * @param int|float $s Saturation in percent [0-100]
     * @param int|float $l Lightness in percent [0-100]
     * @return string Hex color in format #RRGGBB
     */
    private static function hslToHex($h, $s, $l): string
    {
        // Normalize HSL values
        $h = fmod($h, 360);
        if ($h < 0) {
            $h += 360;
        }
        $s = max(0, min(100, $s)) / 100;
        $l = max(0, min(100, $l)) / 100;

        // Convert HSL to RGB
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        $r = 0;
        $g = 0;
        $b = 0;

        if ($h < 60) {
            $r = $c;
            $g = $x;
            $b = 0;
        } elseif ($h < 120) {
            $r = $x;
            $g = $c;
            $b = 0;
        } elseif ($h < 180) {
            $r = 0;
            $g = $c;
            $b = $x;
        } elseif ($h < 240) {
            $r = 0;
            $g = $x;
            $b = $c;
        } elseif ($h < 300) {
            $r = $x;
            $g = 0;
            $b = $c;
        } else {
            $r = $c;
            $g = 0;
            $b = $x;
        }

        $r = (int) round(($r + $m) * 255);
        $g = (int) round(($g + $m) * 255);
        $b = (int) round(($b + $m) * 255);

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}
