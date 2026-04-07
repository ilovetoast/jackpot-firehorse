<?php

namespace App\Support;

/**
 * Tightens enhanced-preview source rasters by trimming low-detail margins typical of
 * press-ready proofs (crop marks, color bars, outer white bleed).
 *
 * Uses column/row luminance variance on a downscaled analysis image — no ML.
 */
final class EnhancedPreviewContentCrop
{
    /**
     * @param  \GdImage  $src  Full-resolution source (truecolor)
     * @return array{x:int,y:int,width:int,height:int}|null Crop rect in $src pixel space, or null to keep full image
     */
    public static function computeCropRect(\GdImage $src): ?array
    {
        if (! config('enhanced_preview.content_crop.enabled', true)) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 64 || $sh < 64) {
            return null;
        }

        $maxW = max(120, (int) config('enhanced_preview.content_crop.analysis_max_width', 420));
        $analysisW = min($maxW, $sw);
        $analysisH = max(1, (int) round($sh * ($analysisW / $sw)));

        $small = imagescale($src, $analysisW, $analysisH);
        if ($small === false) {
            return null;
        }

        try {
            $nw = imagesx($small);
            $nh = imagesy($small);
            if ($nw < 16 || $nh < 16) {
                return null;
            }

            $edgeIgnore = (float) config('enhanced_preview.content_crop.edge_ignore_ratio', 0.02);
            $edgeIgnore = max(0.0, min(0.12, $edgeIgnore));
            $minSide = (float) config('enhanced_preview.content_crop.min_side_ratio', 0.38);
            $minSide = max(0.22, min(0.92, $minSide));
            $varRatio = (float) config('enhanced_preview.content_crop.variance_threshold_ratio', 0.12);
            $varRatio = max(0.04, min(0.45, $varRatio));

            $colVar = self::columnVariances($small, $nw, $nh);
            $rowVar = self::rowVariances($small, $nw, $nh);
            if ($colVar === [] || $rowVar === []) {
                return null;
            }

            $maxCol = max($colVar);
            $maxRow = max($rowVar);
            if ($maxCol < 1e-6 || $maxRow < 1e-6) {
                return null;
            }

            $tCol = max(25.0, $varRatio * $maxCol);
            $tRow = max(25.0, $varRatio * $maxRow);

            $xMin = (int) floor($nw * $edgeIgnore);
            $xMax = (int) ceil($nw * (1 - $edgeIgnore)) - 1;
            $yMin = (int) floor($nh * $edgeIgnore);
            $yMax = (int) ceil($nh * (1 - $edgeIgnore)) - 1;

            $left = self::firstIndexAboveThreshold($colVar, $tCol, $xMin, $xMax, 1);
            $right = self::firstIndexAboveThreshold($colVar, $tCol, $xMax, $xMin, -1);
            $top = self::firstIndexAboveThreshold($rowVar, $tRow, $yMin, $yMax, 1);
            $bottom = self::firstIndexAboveThreshold($rowVar, $tRow, $yMax, $yMin, -1);

            if ($left === null || $right === null || $top === null || $bottom === null) {
                return null;
            }
            if ($right < $left || $bottom < $top) {
                return null;
            }

            $cw = $right - $left + 1;
            $ch = $bottom - $top + 1;
            if ($cw < $minSide * $nw || $ch < $minSide * $nh) {
                return null;
            }

            // Map analysis rect → full-size source pixels (inclusive bounds)
            $scaleX = $sw / $nw;
            $scaleY = $sh / $nh;
            $padFrac = (float) config('enhanced_preview.content_crop.content_pad_ratio', 0.015);
            $padFrac = max(0.0, min(0.06, $padFrac));
            $padX = (int) round($cw * $padFrac);
            $padY = (int) round($ch * $padFrac);

            $fx1 = (int) floor($left * $scaleX) - $padX;
            $fy1 = (int) floor($top * $scaleY) - $padY;
            $fx2 = (int) ceil(($right + 1) * $scaleX) + $padX;
            $fy2 = (int) ceil(($bottom + 1) * $scaleY) + $padY;

            $fx1 = max(0, $fx1);
            $fy1 = max(0, $fy1);
            $fx2 = min($sw, $fx2);
            $fy2 = min($sh, $fy2);

            $fw = $fx2 - $fx1;
            $fh = $fy2 - $fy1;
            if ($fw < (int) round($sw * $minSide) || $fh < (int) round($sh * $minSide)) {
                return null;
            }

            // If crop removes almost nothing, skip (avoid noise)
            if ($fw >= $sw * 0.97 && $fh >= $sh * 0.97) {
                return null;
            }

            return [
                'x' => $fx1,
                'y' => $fy1,
                'width' => $fw,
                'height' => $fh,
            ];
        } finally {
            imagedestroy($small);
        }
    }

    /**
     * @return list<float>
     */
    private static function columnVariances(\GdImage $im, int $nw, int $nh): array
    {
        $out = [];
        for ($x = 0; $x < $nw; $x++) {
            $sum = 0.0;
            $sumSq = 0.0;
            $n = 0;
            for ($y = 0; $y < $nh; $y++) {
                $g = self::grayAt($im, $x, $y);
                $sum += $g;
                $sumSq += $g * $g;
                $n++;
            }
            if ($n < 1) {
                $out[] = 0.0;

                continue;
            }
            $mean = $sum / $n;
            $out[] = max(0.0, ($sumSq / $n) - ($mean * $mean));
        }

        return $out;
    }

    /**
     * @return list<float>
     */
    private static function rowVariances(\GdImage $im, int $nw, int $nh): array
    {
        $out = [];
        for ($y = 0; $y < $nh; $y++) {
            $sum = 0.0;
            $sumSq = 0.0;
            $n = 0;
            for ($x = 0; $x < $nw; $x++) {
                $g = self::grayAt($im, $x, $y);
                $sum += $g;
                $sumSq += $g * $g;
                $n++;
            }
            if ($n < 1) {
                $out[] = 0.0;

                continue;
            }
            $mean = $sum / $n;
            $out[] = max(0.0, ($sumSq / $n) - ($mean * $mean));
        }

        return $out;
    }

    private static function grayAt(\GdImage $im, int $x, int $y): float
    {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    /**
     * First index moving from $from toward $to (inclusive) where value >= threshold.
     *
     * @param  list<float>  $series
     */
    private static function firstIndexAboveThreshold(array $series, float $threshold, int $from, int $to, int $step): ?int
    {
        if ($step === 0) {
            return null;
        }
        for ($i = $from; ($step > 0 ? $i <= $to : $i >= $to); $i += $step) {
            if (! isset($series[$i])) {
                continue;
            }
            if ($series[$i] >= $threshold) {
                return $i;
            }
        }

        return null;
    }
}
