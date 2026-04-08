<?php

namespace App\Services;

use App\Support\ImagickPixelExport;
use Imagick;
use ImagickException;
use Throwable;

/**
 * Auxiliary heuristics for print-ready PDFs / exports (crop marks, color bars, registration).
 * Used for optional metadata only; preferred thumbnail cropping uses dominant ink bbox ({@see PrintLayoutCropService}).
 *
 * @phpstan-type PrintLayoutSignals array{
 *   edge_lines: bool,
 *   corner_marks: bool,
 *   color_bars: bool,
 *   header_strip: bool
 * }
 * @phpstan-type PrintLayoutDetectResult array{
 *   is_print_layout: bool,
 *   confidence: float,
 *   signals: PrintLayoutSignals
 * }
 */
final class PrintLayoutDetectionService
{
    /**
     * @return PrintLayoutDetectResult
     */
    public function detectPrintLayout(string $imagePath): array
    {
        $emptySignals = [
            'edge_lines' => false,
            'corner_marks' => false,
            'color_bars' => false,
            'header_strip' => false,
        ];

        if (! extension_loaded('imagick')) {
            return [
                'is_print_layout' => false,
                'confidence' => 0.0,
                'signals' => $emptySignals,
            ];
        }

        if (! is_readable($imagePath) || filesize($imagePath) === 0) {
            return [
                'is_print_layout' => false,
                'confidence' => 0.0,
                'signals' => $emptySignals,
            ];
        }

        $edgeT = (float) config('assets.print_layout.edge_threshold', 0.15);
        $cornerT = (float) config('assets.print_layout.corner_threshold', 0.2);
        $maxSide = max(128, (int) config('assets.print_layout.analysis_max_side', 512));
        $stripFrac = (float) config('assets.print_layout.edge_strip_fraction', 0.075);
        $stripFrac = max(0.05, min(0.1, $stripFrac));

        try {
            $im = new Imagick($imagePath);
        } catch (ImagickException|Throwable) {
            return [
                'is_print_layout' => false,
                'confidence' => 0.0,
                'signals' => $emptySignals,
            ];
        }

        try {
            $im->setIteratorIndex(0);
            if ($im->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_UNDEFINED) {
                $im->setImageBackgroundColor('#ffffff');
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w < 64 || $h < 64) {
                return [
                    'is_print_layout' => false,
                    'confidence' => 0.0,
                    'signals' => $emptySignals,
                ];
            }

            $scale = min(1.0, $maxSide / max($w, $h));
            if ($scale < 1.0) {
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $im->resizeImage($nw, $nh, Imagick::FILTER_BOX, 1, true);
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
            }

            $edgeLines = $this->detectEdgeLines($im, $stripFrac, $edgeT);
            $cornerMarks = $this->detectCornerMarks($im, $cornerT);
            $colorBars = $this->detectColorBars($im);
            $headerStrip = $this->detectHeaderStrip($im);

            $signals = [
                'edge_lines' => $edgeLines,
                'corner_marks' => $cornerMarks,
                'color_bars' => $colorBars,
                'header_strip' => $headerStrip,
            ];

            $count = (int) $edgeLines + (int) $cornerMarks + (int) $colorBars + (int) $headerStrip;
            $confidence = $count / 4.0;
            $isPrint = $count >= 2;

            return [
                'is_print_layout' => $isPrint,
                'confidence' => round($confidence, 4),
                'signals' => $signals,
            ];
        } catch (Throwable) {
            return [
                'is_print_layout' => false,
                'confidence' => 0.0,
                'signals' => $emptySignals,
            ];
        } finally {
            try {
                $im->clear();
                $im->destroy();
            } catch (Throwable) {
            }
        }
    }

    private function detectEdgeLines(Imagick $source, float $stripFrac, float $threshold): bool
    {
        $w = $source->getImageWidth();
        $h = $source->getImageHeight();
        $t = max(2, (int) round(min($w, $h) * $stripFrac));

        $densities = [];
        foreach (['top' => [0, 0, $w, $t], 'bottom' => [0, $h - $t, $w, $t], 'left' => [0, 0, $t, $h], 'right' => [$w - $t, 0, $t, $h]] as $region) {
            [$x, $y, $cw, $ch] = $region;
            if ($cw < 1 || $ch < 1) {
                continue;
            }
            try {
                $strip = clone $source;
                $strip->cropImage($cw, $ch, $x, $y);
                $strip->setImagePage(0, 0, 0, 0);
                // Ink density only: edgeImage() on strips is dominated by the seam to the image interior (false positives on flat fields).
                $densities[] = $this->stripInkDensity($strip, 96);
                $strip->clear();
                $strip->destroy();
            } catch (Throwable) {
            }
        }

        if ($densities === []) {
            return false;
        }

        $avg = array_sum($densities) / count($densities);

        return $avg >= $threshold;
    }

    /**
     * Dark (ink-like) pixel fraction in a border strip — crop marks / keylines, not Sobel seams.
     */
    private function stripInkDensity(Imagick $strip, int $maxSide = 96): float
    {
        try {
            $gray = clone $strip;
            $gray->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            $d = $this->fractionDarkPixelsBelow($gray, $maxSide, 210);
            $gray->clear();
            $gray->destroy();

            return $d;
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * Fraction of pixels with luminance channel value below threshold (8-bit 0–255 scale).
     */
    private function fractionDarkPixelsBelow(Imagick $im, int $maxSide, int $lumaMax): float
    {
        $base = clone $im;
        try {
            $base->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        } catch (Throwable) {
            try {
                $base->clear();
                $base->destroy();
            } catch (Throwable) {
            }

            return 0.0;
        }

        $w = $base->getImageWidth();
        $h = $base->getImageHeight();
        if ($w < 1 || $h < 1) {
            try {
                $base->clear();
                $base->destroy();
            } catch (Throwable) {
            }

            return 0.0;
        }

        $scale = min(1.0, $maxSide / max($w, $h));
        if ($scale < 1.0) {
            $base->resizeImage(
                max(1, (int) round($w * $scale)),
                max(1, (int) round($h * $scale)),
                Imagick::FILTER_BOX,
                1,
                true
            );
            $w = $base->getImageWidth();
            $h = $base->getImageHeight();
        }

        $pixels = '';
        try {
            if ((int) $base->getImageColorspace() === Imagick::COLORSPACE_GRAY) {
                $pixels = ImagickPixelExport::exportChar($base, 0, 0, $w, $h, 'I');
            } else {
                $pixels = ImagickPixelExport::exportChar($base, 0, 0, $w, $h, 'RGB');
            }
        } finally {
            try {
                $base->clear();
                $base->destroy();
            } catch (Throwable) {
            }
        }

        $len = strlen($pixels);
        if ($len === 0) {
            return 0.0;
        }

        $dark = 0;
        $n = 0;
        if ($len % 3 === 0 && $len >= 3) {
            $n = (int) ($len / 3);
            for ($i = 0; $i < $len; $i += 3) {
                $r = ord($pixels[$i]);
                $g = ord($pixels[$i + 1]);
                $b = ord($pixels[$i + 2]);
                $y = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
                if ($y < $lumaMax) {
                    $dark++;
                }
            }
        } else {
            $n = $len;
            for ($i = 0; $i < $len; $i++) {
                if (ord($pixels[$i]) < $lumaMax) {
                    $dark++;
                }
            }
        }

        return $n > 0 ? $dark / $n : 0.0;
    }

    private function detectCornerMarks(Imagick $source, float $threshold): bool
    {
        $w = $source->getImageWidth();
        $h = $source->getImageHeight();
        $side = max(8, (int) round(min($w, $h) * 0.10));

        $corners = [
            [0, 0],
            [$w - $side, 0],
            [0, $h - $side],
            [$w - $side, $h - $side],
        ];

        $strong = 0;
        foreach ($corners as [$x, $y]) {
            if ($x < 0 || $y < 0 || $x + $side > $w || $y + $side > $h) {
                continue;
            }
            try {
                $c = clone $source;
                $c->cropImage($side, $side, $x, $y);
                $c->setImagePage(0, 0, 0, 0);
                $d = $this->fractionDarkPixelsBelow($c, 48, 200);
                $c->clear();
                $c->destroy();
                if ($d >= $threshold) {
                    $strong++;
                }
            } catch (Throwable) {
            }
        }

        return $strong >= 2;
    }

    private function detectColorBars(Imagick $source): bool
    {
        $w = $source->getImageWidth();
        $h = $source->getImageHeight();
        $stripW = max(4, (int) round($w * (float) config('assets.print_layout.color_bar_strip_fraction', 0.06)));
        $y0 = (int) round($h * 0.12);
        $ch = max(8, (int) round($h * 0.76));

        $hits = 0;
        foreach ([0, $w - $stripW] as $x) {
            if ($x < 0 || $x + $stripW > $w) {
                continue;
            }
            try {
                $strip = clone $source;
                $strip->cropImage($stripW, $ch, $x, $y0);
                $strip->setImagePage(0, 0, 0, 0);
                if ($this->verticalCmykBarHeuristic($strip)) {
                    $hits++;
                }
                $strip->clear();
                $strip->destroy();
            } catch (Throwable) {
            }
        }

        return $hits >= 1;
    }

    /**
     * Look for stacked blocks consistent with CMYK registration strips.
     */
    private function verticalCmykBarHeuristic(Imagick $strip): bool
    {
        $sw = $strip->getImageWidth();
        $sh = $strip->getImageHeight();
        if ($sw < 2 || $sh < 16) {
            return false;
        }

        $rows = 32;
        $step = max(1, (int) floor($sh / $rows));
        $labels = [];

        for ($y = 0; $y < $sh; $y += $step) {
            $r = $g = $b = $n = 0;
            for ($x = 0; $x < $sw; $x++) {
                try {
                    $c = $strip->getImagePixelColor($x, $y)->getColor();
                    $r += (int) ($c['r'] ?? 0);
                    $g += (int) ($c['g'] ?? 0);
                    $b += (int) ($c['b'] ?? 0);
                    $n++;
                } catch (Throwable) {
                }
            }
            if ($n === 0) {
                continue;
            }
            $r = (int) round($r / $n);
            $g = (int) round($g / $n);
            $b = (int) round($b / $n);
            $labels[] = $this->classifyInk($r, $g, $b);
        }

        if (count($labels) < 8) {
            return false;
        }

        $cmykLike = ['C', 'M', 'Y', 'K'];
        $countCmyk = 0;
        foreach ($labels as $lb) {
            if (in_array($lb, $cmykLike, true)) {
                $countCmyk++;
            }
        }

        $transitions = 0;
        for ($i = 1, $imax = count($labels); $i < $imax; $i++) {
            if ($labels[$i] !== $labels[$i - 1]) {
                $transitions++;
            }
        }

        return $countCmyk >= 6 && $transitions >= 4;
    }

    private function classifyInk(int $r, int $g, int $b): string
    {
        if ($r < 90 && $g < 90 && $b < 90) {
            return 'K';
        }
        if ($b > 200 && $g > 200 && $r < 120) {
            return 'C';
        }
        if ($r > 200 && $b > 200 && $g < 120) {
            return 'M';
        }
        if ($r > 200 && $g > 200 && $b < 120) {
            return 'Y';
        }
        if (($r + $g + $b) / 3 > 220) {
            return 'W';
        }

        return 'O';
    }

    private function detectHeaderStrip(Imagick $source): bool
    {
        $w = $source->getImageWidth();
        $h = $source->getImageHeight();
        $th = max(4, (int) round($h * 0.10));

        try {
            $top = clone $source;
            $top->cropImage($w, $th, 0, 0);
            $top->setImagePage(0, 0, 0, 0);
            $top->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            $q = $top->getQuantumRange();
            $qr = (float) ($q['quantumRangeLong'] ?? 65535);
            $top->thresholdImage(0.88 * $qr);
            $ink = 1.0 - $this->fractionLightPixels($top, 120);
            $top->clear();
            $top->destroy();

            $minInk = (float) config('assets.print_layout.header_ink_fraction', 0.08);

            return $ink >= $minInk;
        } catch (Throwable) {
            return false;
        }
    }

    private function fractionLightPixels(Imagick $im, int $maxSide): float
    {
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $work = $im;
        $cloned = false;
        $scale = min(1.0, $maxSide / max($w, $h));
        if ($scale < 1.0) {
            $work = clone $im;
            $cloned = true;
            $work->resizeImage(
                max(1, (int) round($w * $scale)),
                max(1, (int) round($h * $scale)),
                Imagick::FILTER_BOX,
                1,
                true
            );
            $w = $work->getImageWidth();
            $h = $work->getImageHeight();
        }

        try {
            $pixels = ImagickPixelExport::exportChar($work, 0, 0, $w, $h, 'I');
        } finally {
            if ($cloned) {
                try {
                    $work->clear();
                    $work->destroy();
                } catch (Throwable) {
                }
            }
        }

        $len = strlen($pixels);
        if ($len === 0) {
            return 0.0;
        }

        $light = 0;
        for ($i = 0; $i < $len; $i++) {
            if (ord($pixels[$i]) > 200) {
                $light++;
            }
        }

        return $light / $len;
    }
}
