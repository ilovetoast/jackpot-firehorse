<?php

namespace App\Studio\LayerExtraction\Sam;

use InvalidArgumentException;

/**
 * Size limits, MIME allow-list, and optional long-edge downscale for SAM / inpaint HTTP clients.
 * Does not log file paths or image contents.
 */
final class SamLayerExtractionImage
{
    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public static function assertSourceConstraints(string $binary, int $maxSourceMb): array
    {
        $n = strlen($binary);
        $maxBytes = max(1, $maxSourceMb * 1_000_000);
        if ($n > $maxBytes) {
            throw new InvalidArgumentException('Image exceeds the maximum allowed size for this operation.');
        }
        $info = @getimagesizefromstring($binary);
        if (! is_array($info) || ($info[0] ?? 0) < 2 || ($info[1] ?? 0) < 2) {
            throw new InvalidArgumentException('Unsupported or unreadable image format.');
        }
        $mime = (string) ($info['mime'] ?? '');
        if ($mime === '' || ! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('Image type is not allowed for segmentation.');
        }
        $w = (int) $info[0];
        $h = (int) $info[1];

        return ['w' => $w, 'h' => $h, 'mime' => $mime];
    }

    /**
     * @return array{0: string, w: int, h: int, orig_w: int, orig_h: int, scale: float}
     */
    public static function downscaleToMaxLongEdge(string $binary, int $maxLongEdge): array
    {
        $info = @getimagesizefromstring($binary);
        if (! is_array($info)) {
            throw new InvalidArgumentException('Could not read image dimensions.');
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        $ow = $w;
        $oh = $h;
        $le = max($w, $h);
        if ($le <= 0 || $maxLongEdge <= 0) {
            return ['binary' => $binary, 'w' => $w, 'h' => $h, 'orig_w' => $ow, 'orig_h' => $oh, 'scale' => 1.0];
        }
        if ($le <= $maxLongEdge) {
            return ['binary' => $binary, 'w' => $w, 'h' => $h, 'orig_w' => $ow, 'orig_h' => $oh, 'scale' => 1.0];
        }
        $scale = $maxLongEdge / $le;
        $nw = max(2, (int) round($w * $scale));
        $nh = max(2, (int) round($h * $scale));
        $im = @imagecreatefromstring($binary);
        if ($im === false) {
            throw new InvalidArgumentException('Could not decode image for resizing.');
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $scaled = imagescale($im, $nw, $nh, 5);
        imagedestroy($im);
        if ($scaled === false) {
            throw new InvalidArgumentException('Could not resize image for the segmentation provider.');
        }
        ob_start();
        imagepng($scaled);
        $out = (string) ob_get_clean();
        imagedestroy($scaled);
        if ($out === '') {
            throw new InvalidArgumentException('Failed to encode resized image.');
        }
        $scaleEff = $nw / max(1, $w);

        return ['binary' => $out, 'w' => $nw, 'h' => $nh, 'orig_w' => $ow, 'orig_h' => $oh, 'scale' => $scaleEff];
    }

    /**
     * @param  array{x_min: int, y_min: int, x_max: int, y_max: int}  $box
     * @return array{x_min: int, y_min: int, x_max: int, y_max: int}
     */
    public static function mapNormBoxToPixelBox(array $box, int $w, int $h): array
    {
        $x0 = (int) max(0, min($w - 1, (int) floor($box['x'] * $w)));
        $y0 = (int) max(0, min($h - 1, (int) floor($box['y'] * $h)));
        $x1 = (int) max(0, min($w - 1, (int) ceil(($box['x'] + $box['width']) * $w) - 1));
        $y1 = (int) max(0, min($h - 1, (int) ceil(($box['y'] + $box['height']) * $h) - 1));
        if ($x1 < $x0) {
            [$x0, $x1] = [$x1, $x0];
        }
        if ($y1 < $y0) {
            [$y0, $y1] = [$y1, $y0];
        }

        return [
            'x_min' => $x0,
            'y_min' => $y0,
            'x_max' => max($x0, $x1),
            'y_max' => max($y0, $y1),
        ];
    }

    /**
     * @param  array{x: float, y: float}  $n
     * @return array{x: int, y: int}
     */
    public static function normToPixel(array $n, int $w, int $h): array
    {
        $x = (int) max(0, min($w - 1, (int) round($n['x'] * max(1, $w - 1))));
        $y = (int) max(0, min($h - 1, (int) round($n['y'] * max(1, $h - 1))));

        return ['x' => $x, 'y' => $y];
    }

    public static function dataUriFromBinary(string $binary, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    /**
     * Nearest-neighbor upscale of a PNG mask to target size.
     */
    /**
     * @return ?array{x: int, y: int, width: int, height: int}
     */
    public static function bboxFromForegroundMaskPng(string $maskPng): ?array
    {
        $im = @imagecreatefromstring($maskPng);
        if ($im === false) {
            return null;
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $minX = $w;
        $minY = $h;
        $maxX = 0;
        $maxY = 0;
        $any = false;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = ($c >> 24) & 127;
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                $lum = ($r + $g + $b) / 3.0;
                $wgt = (1.0 - $a / 127.0) * ($lum / 255.0);
                if ($wgt > 0.1) {
                    $any = true;
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }
        imagedestroy($im);
        if (! $any) {
            return null;
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $maxX - $minX + 1,
            'height' => $maxY - $minY + 1,
        ];
    }

    public static function scaleMaskPngToSize(string $maskPng, int $targetW, int $targetH): string
    {
        if ($targetW < 2 || $targetH < 2) {
            return $maskPng;
        }
        $im = @imagecreatefromstring($maskPng);
        if ($im === false) {
            return $maskPng;
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $sw = imagesx($im);
        $sh = imagesy($im);
        if ($sw === $targetW && $sh === $targetH) {
            imagedestroy($im);

            return $maskPng;
        }
        $out = imagecreatetruecolor($targetW, $targetH);
        if ($out === false) {
            imagedestroy($im);

            return $maskPng;
        }
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresized($out, $im, 0, 0, 0, 0, $targetW, $targetH, $sw, $sh);
        imagedestroy($im);
        ob_start();
        imagepng($out);
        $b = (string) ob_get_clean();
        imagedestroy($out);

        return $b !== '' ? $b : $maskPng;
    }
}
