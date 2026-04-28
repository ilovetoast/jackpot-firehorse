<?php

namespace App\Studio\LayerExtraction\Sam;

/**
 * Splits a full-frame binary (alpha / white) mask into connected components for multi-candidate auto mode.
 */
final class SamMaskComponentSplitter
{
    /**
     * @return list<SamMaskSegment>
     */
    public static function splitFromRgbaOrGrayscaleMask(string $maskPng, int $minAreaPx = 80): array
    {
        $im = @imagecreatefromstring($maskPng);
        if ($im === false) {
            return [];
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 2 || $h < 2) {
            imagedestroy($im);

            return [];
        }
        $fg = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = ($c >> 24) & 127;
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                $lum = ($r + $g + $b) / 3.0;
                $fgW = (1.0 - $a / 127.0) * ($lum / 255.0);
                $fg[$y * $w + $x] = $fgW > 0.12;
            }
        }
        imagedestroy($im);

        $labels = self::labelComponents($fg, $w, $h);
        $ids = array_values(array_unique(array_filter($labels, fn ($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($ids as $labelId) {
            $count = 0;
            for ($i = 0, $n = $w * $h; $i < $n; $i++) {
                if (($labels[$i] ?? 0) === $labelId) {
                    $count++;
                }
            }
            if ($count < $minAreaPx) {
                continue;
            }
            $maskBin = self::buildMaskPngForLabel($labels, $w, $h, $labelId);
            $bbox = self::bboxForLabel($labels, $w, $h, $labelId);
            if ($bbox === null) {
                continue;
            }
            $out[] = new SamMaskSegment(
                $maskBin,
                $bbox,
                null,
                'Region '.(count($out) + 1),
            );
        }

        return $out;
    }

    /**
     * @param  list<bool>  $fg
     * @return list<int>
     */
    private static function labelComponents(array $fg, int $w, int $h): array
    {
        $n = $w * $h;
        $lab = array_fill(0, $n, 0);
        $next = 1;
        for ($i = 0; $i < $n; $i++) {
            if (! $fg[$i] || $lab[$i] !== 0) {
                continue;
            }
            if ($next > 1_000_000) {
                break;
            }
            $q = [$i];
            $lab[$i] = $next;
            for ($qi = 0; $qi < count($q); $qi++) {
                $c = $q[$qi];
                $x = $c % $w;
                $y = (int) ($c / $w);
                $nbs = [];
                if ($x > 0) {
                    $nbs[] = $c - 1;
                }
                if ($x < $w - 1) {
                    $nbs[] = $c + 1;
                }
                if ($y > 0) {
                    $nbs[] = $c - $w;
                }
                if ($y < $h - 1) {
                    $nbs[] = $c + $w;
                }
                foreach ($nbs as $nb) {
                    if ($fg[$nb] && $lab[$nb] === 0) {
                        $lab[$nb] = $next;
                        $q[] = $nb;
                    }
                }
            }
            $next++;
        }

        return $lab;
    }

    /**
     * @param  list<int>  $labels
     */
    private static function buildMaskPngForLabel(array $labels, int $w, int $h, int $labelId): string
    {
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            return '';
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $t = imagecolorallocatealpha($im, 0, 0, 0, 127);
        $white = imagecolorallocatealpha($im, 255, 255, 255, 0);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $t);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (($labels[$y * $w + $x] ?? 0) === $labelId) {
                    imagesetpixel($im, $x, $y, $white);
                }
            }
        }
        ob_start();
        imagepng($im);
        $b = (string) ob_get_clean();
        imagedestroy($im);

        return $b;
    }

    /**
     * @param  list<int>  $labels
     * @return ?array{x: int, y: int, width: int, height: int}
     */
    private static function bboxForLabel(array $labels, int $w, int $h, int $labelId): ?array
    {
        $minX = $w;
        $minY = $h;
        $maxX = 0;
        $maxY = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (($labels[$y * $w + $x] ?? 0) === $labelId) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }
        if ($minX > $maxX) {
            return null;
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $maxX - $minX + 1,
            'height' => $maxY - $minY + 1,
        ];
    }
}
