<?php

namespace App\Support;

use Imagick;
use Throwable;

/**
 * Normalizes {@see Imagick::exportImagePixels()} across IM/imagick versions (string vs array of ints).
 */
final class ImagickPixelExport
{
    public static function exportChar(Imagick $im, int $x, int $y, int $w, int $h, string $map): string
    {
        try {
            $raw = $im->exportImagePixels($x, $y, $w, $h, $map, Imagick::PIXEL_CHAR);
        } catch (Throwable) {
            return '';
        }

        if (is_string($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            $buf = '';
            foreach ($raw as $v) {
                $buf .= chr(max(0, min(255, (int) $v)));
            }

            return $buf;
        }

        return '';
    }
}
