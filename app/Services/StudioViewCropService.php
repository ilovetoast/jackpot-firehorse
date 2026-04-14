<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Applies a user-defined normalized crop (0–1) to a local raster using GD.
 */
final class StudioViewCropService
{
    /**
     * @param  array<string, mixed>  $norm  keys: x, y, width, height (floats 0–1)
     * @return array{x:float,y:float,width:float,height:float}|null
     */
    public function normalizeCropRect(array $norm): ?array
    {
        $x = (float) ($norm['x'] ?? 0);
        $y = (float) ($norm['y'] ?? 0);
        $w = (float) ($norm['width'] ?? 0);
        $h = (float) ($norm['height'] ?? 0);
        $x = max(0, min(1, $x));
        $y = max(0, min(1, $y));
        $w = max(0, min(1, $w));
        $h = max(0, min(1, $h));
        if ($w <= 0.0001 || $h <= 0.0001) {
            return null;
        }
        if ($x + $w > 1.00001) {
            $w = max(0.0001, 1 - $x);
        }
        if ($y + $h > 1.00001) {
            $h = max(0.0001, 1 - $y);
        }

        return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
    }

    /**
     * @param  array{x:float,y:float,width:float,height:float}  $norm
     * @return string|null Absolute path to temp PNG/JPEG
     */
    public function cropNormalizedToTemp(string $localRasterPath, array $norm): ?string
    {
        if (! is_file($localRasterPath) || filesize($localRasterPath) === 0) {
            return null;
        }

        $blob = @file_get_contents($localRasterPath);
        if ($blob === false || $blob === '') {
            return null;
        }

        $src = @imagecreatefromstring($blob);
        if ($src === false) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 2 || $sh < 2) {
            imagedestroy($src);

            return null;
        }

        $rect = $this->normalizeCropRect($norm);
        if ($rect === null) {
            imagedestroy($src);

            return null;
        }

        $cx = (int) round($rect['x'] * $sw);
        $cy = (int) round($rect['y'] * $sh);
        $cw = (int) round($rect['width'] * $sw);
        $ch = (int) round($rect['height'] * $sh);
        $cx = max(0, min($sw - 1, $cx));
        $cy = max(0, min($sh - 1, $cy));
        $cw = max(1, min($sw - $cx, $cw));
        $ch = max(1, min($sh - $cy, $ch));

        $cropRect = ['x' => $cx, 'y' => $cy, 'width' => $cw, 'height' => $ch];
        $cropped = @imagecrop($src, $cropRect);
        imagedestroy($src);
        if ($cropped === false) {
            Log::warning('[StudioViewCropService] imagecrop failed', $cropRect);

            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'studio_crop_');
        if ($tmp === false) {
            imagedestroy($cropped);

            return null;
        }
        $outPath = $tmp.'.png';
        @unlink($tmp);

        $ok = @imagepng($cropped, $outPath, 6);
        imagedestroy($cropped);
        if (! $ok || ! is_file($outPath)) {
            return null;
        }

        return $outPath;
    }

    /**
     * @param  array<string, mixed>|null  $poi  x, y floats 0–1 relative to full source dimensions
     * @return array{x:float,y:float}|null
     */
    public function normalizePoi(?array $poi): ?array
    {
        if ($poi === null || ! is_array($poi)) {
            return null;
        }
        if (! isset($poi['x'], $poi['y'])) {
            return null;
        }
        $x = max(0, min(1, (float) $poi['x']));
        $y = max(0, min(1, (float) $poi['y']));

        return ['x' => $x, 'y' => $y];
    }
}
