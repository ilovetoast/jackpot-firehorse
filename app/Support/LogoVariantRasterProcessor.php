<?php

namespace App\Support;

/**
 * Server-side logo variant generation (mirrors client canvas logic in resources/js/utils/imageUtils.js).
 * Requires ext-gd with JPEG/PNG support.
 */
final class LogoVariantRasterProcessor
{
    /**
     * White silhouette: opaque pixels → white, alpha preserved (PNG output).
     */
    public static function whiteSilhouettePng(string $imageBinary): ?string
    {
        return self::transform($imageBinary, function (\GdImage $im): void {
            $cache = [];
            self::mapOpaquePixels($im, function (int $a) use ($im, &$cache): int {
                if (! isset($cache[$a])) {
                    $cache[$a] = imagecolorallocatealpha($im, 255, 255, 255, $a);
                }

                return $cache[$a];
            });
        });
    }

    /**
     * Primary color wash: opaque pixels → solid RGB, alpha preserved.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function primaryColorWashPng(string $imageBinary, array $rgb): ?string
    {
        [$r, $g, $b] = [
            max(0, min(255, $rgb[0])),
            max(0, min(255, $rgb[1])),
            max(0, min(255, $rgb[2])),
        ];

        return self::transform($imageBinary, function (\GdImage $im) use ($r, $g, $b): void {
            $cache = [];
            self::mapOpaquePixels($im, function (int $a) use ($im, &$cache, $r, $g, $b): int {
                if (! isset($cache[$a])) {
                    $cache[$a] = imagecolorallocatealpha($im, $r, $g, $b, $a);
                }

                return $cache[$a];
            });
        });
    }

    public static function parseHexRgb(string $hex): ?array
    {
        $h = trim($hex);
        if ($h === '') {
            return null;
        }
        if ($h[0] === '#') {
            $h = substr($h, 1);
        }
        if (strlen($h) === 3) {
            return [
                hexdec($h[0].$h[0]),
                hexdec($h[1].$h[1]),
                hexdec($h[2].$h[2]),
            ];
        }
        if (strlen($h) === 6) {
            return [
                hexdec(substr($h, 0, 2)),
                hexdec(substr($h, 2, 2)),
                hexdec(substr($h, 4, 2)),
            ];
        }

        return null;
    }

    /**
     * @param  callable(int $alphaGd): int  $allocateColor  returns color index
     */
    private static function mapOpaquePixels(\GdImage $im, callable $allocateColor): void
    {
        $w = imagesx($im);
        $h = imagesy($im);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = ($c >> 24) & 0x7F;
                if ($a >= 127) {
                    continue;
                }
                $idx = $allocateColor($a);
                imagesetpixel($im, $x, $y, $idx);
            }
        }
    }

    /**
     * @param  callable(\GdImage): void  $mutate
     */
    private static function transform(string $imageBinary, callable $mutate): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            return null;
        }
        $im = @imagecreatefromstring($imageBinary);
        if ($im === false) {
            return null;
        }
        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($im);
        }
        try {
            $mutate($im);
            ob_start();
            imagepng($im);
            $out = ob_get_clean();

            return $out !== false && $out !== '' ? $out : null;
        } finally {
            imagedestroy($im);
        }
    }
}
