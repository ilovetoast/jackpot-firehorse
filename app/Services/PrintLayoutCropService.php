<?php

namespace App\Services;

use App\Support\ImagickPixelExport;
use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use Throwable;

/**
 * Print-ready crop: grayscale → downscale → optional outer margin strip, then
 * projection-based content bounds (column/row ink density, normalized, thresholded).
 * Cropping does not use {@see PrintLayoutDetectionService}.
 *
 * @phpstan-type PrintCropResult array{
 *   path: string,
 *   applied: bool,
 *   confidence: float,
 *   skip_reason?: string,
 *   bbox?: array{x:int,y:int,w:int,h:int}
 * }
 * @phpstan-type DominantBbox array{
 *   minX:int,
 *   minY:int,
 *   maxX:int,
 *   maxY:int,
 *   innerW:int,
 *   innerH:int,
 *   margin:int,
 *   workW:int,
 *   workH:int,
 *   origW:int,
 *   origH:int
 * }
 */
final class PrintLayoutCropService
{
    /**
     * Downscale (max side {@see config assets.print_layout.analysis_max_side}), strip outer margin for analysis,
     * grayscale, then {@see findContentBoundsFromProjection} (normalized projections, 0.15×peak threshold, expand).
     *
     * @param  bool  $forOverlayVisualization  Reserved for future overlay-specific tuning.
     * @return DominantBbox|null
     */
    public function findDominantContentBoundingBox(Imagick $image, bool $forOverlayVisualization = false): ?array
    {
        $work = null;
        $inner = null;

        try {
            $work = clone $image;
            $work->setIteratorIndex(0);
            if ($work->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_UNDEFINED) {
                $work->setImageBackgroundColor('#ffffff');
                $work->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $work->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $origW = $work->getImageWidth();
            $origH = $work->getImageHeight();
            if ($origW < 32 || $origH < 32) {
                $work->clear();
                $work->destroy();
                $work = null;

                return null;
            }

            $analysisMax = max(200, (int) config('assets.print_layout.analysis_max_side', 512));
            $scale = min(1.0, $analysisMax / max($origW, $origH));
            if ($scale < 1.0) {
                $work->resizeImage(
                    max(1, (int) round($origW * $scale)),
                    max(1, (int) round($origH * $scale)),
                    Imagick::FILTER_BOX,
                    1,
                    true
                );
            }

            $workW = $work->getImageWidth();
            $workH = $work->getImageHeight();

            $marginPct = (float) config('assets.print_layout.margin_ignore_percent', 0.20);
            $marginPct = max(0.10, min(0.30, $marginPct));
            $m = (int) round(min($workW, $workH) * $marginPct);
            $m = max(1, min($m, (int) floor(min($workW, $workH) / 2) - 1));

            $iw = $workW - 2 * $m;
            $ih = $workH - 2 * $m;
            if ($iw < 8 || $ih < 8) {
                $work->clear();
                $work->destroy();
                $work = null;

                return null;
            }

            $inner = clone $work;
            $inner->cropImage($iw, $ih, $m, $m);
            $inner->setImagePage(0, 0, 0, 0);
            $work->clear();
            $work->destroy();
            $work = null;

            $inner->transformImageColorspace(Imagick::COLORSPACE_GRAY);

            $innerW = $inner->getImageWidth();
            $innerH = $inner->getImageHeight();
            $pixels = ImagickPixelExport::exportChar($inner, 0, 0, $innerW, $innerH, 'I');
            $inner->clear();
            $inner->destroy();
            $inner = null;

            if ($pixels === '' || strlen($pixels) < $innerW * $innerH) {
                return null;
            }

            $bbox = $this->findContentBoundsFromProjection($pixels, $innerW, $innerH);
            if ($bbox === null) {
                return null;
            }

            return [
                'minX' => $bbox['minX'],
                'minY' => $bbox['minY'],
                'maxX' => $bbox['maxX'],
                'maxY' => $bbox['maxY'],
                'innerW' => $innerW,
                'innerH' => $innerH,
                'margin' => $m,
                'workW' => $workW,
                'workH' => $workH,
                'origW' => $origW,
                'origH' => $origH,
            ];
        } catch (Throwable) {
            return null;
        } finally {
            if ($inner !== null) {
                try {
                    $inner->clear();
                    $inner->destroy();
                } catch (Throwable) {
                }
            }
            if ($work !== null) {
                try {
                    $work->clear();
                    $work->destroy();
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * Column/row sums of content signal (255 − luminance), normalize by each axis max,
     * threshold = k × max (k default 0.15), expand bounds by a fraction of width/height.
     *
     * Raw luminance alone makes white margins dominate sums; ink signal matches print / soft artwork.
     *
     * @return array{minX:int,minY:int,maxX:int,maxY:int}|null
     */
    private function findContentBoundsFromProjection(string $pixels, int $width, int $height): ?array
    {
        $columns = array_fill(0, $width, 0.0);
        $rows = array_fill(0, $height, 0.0);
        $stride = $width;

        for ($y = 0; $y < $height; $y++) {
            $o = $y * $stride;
            for ($x = 0; $x < $width; $x++) {
                $luminance = (float) ord($pixels[$o + $x]);
                $value = 255.0 - $luminance;
                $columns[$x] += $value;
                $rows[$y] += $value;
            }
        }

        $maxCol = 0.0;
        for ($x = 0; $x < $width; $x++) {
            if ($columns[$x] > $maxCol) {
                $maxCol = $columns[$x];
            }
        }
        $maxRow = 0.0;
        for ($y = 0; $y < $height; $y++) {
            if ($rows[$y] > $maxRow) {
                $maxRow = $rows[$y];
            }
        }

        if ($maxCol <= 1e-6 || $maxRow <= 1e-6) {
            return null;
        }

        for ($x = 0; $x < $width; $x++) {
            $columns[$x] /= $maxCol;
        }
        for ($y = 0; $y < $height; $y++) {
            $rows[$y] /= $maxRow;
        }

        $threshFrac = (float) config('assets.print_layout.projection_density_threshold_fraction', 0.15);
        $threshFrac = max(0.05, min(0.50, $threshFrac));
        $tCol = $threshFrac;
        $tRow = $threshFrac;

        $left = null;
        for ($x = 0; $x < $width; $x++) {
            if ($columns[$x] > $tCol) {
                $left = $x;
                break;
            }
        }
        $right = null;
        for ($x = $width - 1; $x >= 0; $x--) {
            if ($columns[$x] > $tCol) {
                $right = $x;
                break;
            }
        }

        $top = null;
        for ($y = 0; $y < $height; $y++) {
            if ($rows[$y] > $tRow) {
                $top = $y;
                break;
            }
        }
        $bottom = null;
        for ($y = $height - 1; $y >= 0; $y--) {
            if ($rows[$y] > $tRow) {
                $bottom = $y;
                break;
            }
        }

        if ($left === null || $right === null || $top === null || $bottom === null) {
            return null;
        }
        if ($left > $right || $top > $bottom) {
            return null;
        }

        $expandFrac = (float) config('assets.print_layout.projection_expand_fraction', 0.04);
        $expandFrac = max(0.03, min(0.05, $expandFrac));

        $padX = max(1, (int) round($expandFrac * $width));
        $padY = max(1, (int) round($expandFrac * $height));

        $left = max(0, $left - $padX);
        $right = min($width - 1, $right + $padX);
        $top = max(0, $top - $padY);
        $bottom = min($height - 1, $bottom + $padY);

        return [
            'minX' => $left,
            'minY' => $top,
            'maxX' => $right,
            'maxY' => $bottom,
        ];
    }

    /**
     * Map dominant-bbox (analysis space) to full-image pixel rect inclusive.
     *
     * @param  DominantBbox  $bbox
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function mapDominantBboxToFullImageRect(Imagick $full, array $bbox): array
    {
        $W = $full->getImageWidth();
        $H = $full->getImageHeight();
        $w = $bbox['workW'];
        $h = $bbox['workH'];
        $m = $bbox['margin'];
        $sx = $W / $w;
        $sy = $H / $h;
        $bx0 = $bbox['minX'];
        $by0 = $bbox['minY'];
        $bx1 = $bbox['maxX'];
        $by1 = $bbox['maxY'];
        $fx0 = (int) floor(($m + $bx0) * $sx);
        $fy0 = (int) floor(($m + $by0) * $sy);
        $fx1 = (int) ceil(($m + $bx1 + 1) * $sx) - 1;
        $fy1 = (int) ceil(($m + $by1 + 1) * $sy) - 1;

        return [$fx0, $fy0, $fx1, $fy1];
    }

    /**
     * Full-resolution copy of the raster with a red bbox drawn (for UI-visible enhanced-preview debugging).
     *
     * @return string|null Path to temp PNG; null if Imagick missing, unreadable, or no bbox
     */
    public function renderFullImageWithBboxOverlayPng(string $imagePath): ?string
    {
        if (! extension_loaded('imagick') || ! is_readable($imagePath) || filesize($imagePath) === 0) {
            return null;
        }

        try {
            $full = new Imagick($imagePath);
        } catch (ImagickException|Throwable) {
            return null;
        }

        try {
            $full->setIteratorIndex(0);
            if ($full->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_UNDEFINED) {
                $full->setImageBackgroundColor('#ffffff');
                $full->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $full->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $bbox = $this->findDominantContentBoundingBox($full, true);
            if ($bbox === null) {
                $full->clear();
                $full->destroy();

                return null;
            }

            [$fx0, $fy0, $fx1, $fy1] = $this->mapDominantBboxToFullImageRect($full, $bbox);

            $draw = new ImagickDraw;
            $strokeW = max(3, (int) round(min($full->getImageWidth(), $full->getImageHeight()) * 0.005));
            $draw->setStrokeWidth($strokeW);
            $draw->setStrokeColor(new ImagickPixel('red'));
            $draw->setFillColor(new ImagickPixel('transparent'));
            $draw->setStrokeOpacity(1);
            $draw->setFillOpacity(0);
            $draw->rectangle((float) $fx0, (float) $fy0, (float) $fx1, (float) $fy1);
            $full->drawImage($draw);
            $full->setImageFormat('png');
            $out = tempnam(sys_get_temp_dir(), 'enh_bbox_dbg_').'.png';
            if (! $full->writeImage($out)) {
                $full->clear();
                $full->destroy();

                return null;
            }
            $full->clear();
            $full->destroy();

            return $out;
        } catch (Throwable) {
            try {
                $full->clear();
                $full->destroy();
            } catch (Throwable) {
            }

            return null;
        }
    }

    /**
     * @param  string|null  $debugFileSuffix  Safe id fragment for /tmp/debug-crop-{suffix}.png when overlay env is on
     * @return PrintCropResult
     */
    public function cropPrintLayout(string $imagePath, ?string $debugFileSuffix = null): array
    {
        if (! extension_loaded('imagick')) {
            return $this->skip($imagePath, 'no_imagick');
        }

        if (! is_readable($imagePath) || filesize($imagePath) === 0) {
            return $this->skip($imagePath, 'unreadable');
        }

        $padFrac = (float) config('assets.print_layout.bbox_padding_fraction', 0.05);
        $padFrac = max(0.02, min(0.10, $padFrac));
        $minBboxRatio = (float) config('assets.print_layout.min_content_bbox_dimension_ratio', 0.4);
        $minBboxRatio = max(0.2, min(0.95, $minBboxRatio));
        $strictMaxDim = (float) config('assets.print_layout.bbox_strict_max_full_dimension_ratio', 0.85);
        $strictMaxDim = max(0.70, min(0.98, $strictMaxDim));
        $minRatio = (float) config('assets.print_layout.min_cropped_dimension_ratio', 0.5);
        $minRatio = max(0.2, min(0.95, $minRatio));
        $maxAspect = (float) config('assets.print_layout.max_aspect_ratio', 6.0);

        try {
            $full = new Imagick($imagePath);
        } catch (ImagickException|Throwable) {
            return $this->skip($imagePath, 'load_failed');
        }

        try {
            $full->setIteratorIndex(0);
            if ($full->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_UNDEFINED) {
                $full->setImageBackgroundColor('#ffffff');
                $full->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $full->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $W = $full->getImageWidth();
            $H = $full->getImageHeight();
            if ($W < 32 || $H < 32) {
                return $this->releaseSkip($full, $imagePath, 'small_dimensions');
            }

            $bbox = $this->findDominantContentBoundingBox($full);
            if ($bbox === null) {
                return $this->releaseSkip($full, $imagePath, 'no_ink_region');
            }

            [$fx0, $fy0, $fx1, $fy1] = $this->mapDominantBboxToFullImageRect($full, $bbox);

            $bboxW = $fx1 - $fx0 + 1;
            $bboxH = $fy1 - $fy0 + 1;

            if ($this->debugPrintCropOverlay()) {
                $this->writeDebugCropOverlay($full, $fx0, $fy0, $fx1, $fy1, $debugFileSuffix);
            }

            $force = $this->debugPrintCropForce();

            if (! $force && ($bboxW > $strictMaxDim * $W || $bboxH > $strictMaxDim * $H)) {
                return $this->releaseSkip($full, $imagePath, 'content_bbox_covers_too_much');
            }

            if (! $force && ($bboxW < (int) ceil($minBboxRatio * $W) || $bboxH < (int) ceil($minBboxRatio * $H))) {
                return $this->releaseSkip($full, $imagePath, 'content_bbox_too_small');
            }

            $pad = (int) max(1, round($padFrac * min($W, $H)));
            $fx0 -= $pad;
            $fy0 -= $pad;
            $fx1 += $pad;
            $fy1 += $pad;

            $fx0 = max(0, min($fx0, $W - 1));
            $fy0 = max(0, min($fy0, $H - 1));
            $fx1 = max($fx0, min($fx1, $W - 1));
            $fy1 = max($fy0, min($fy1, $H - 1));

            $cw = $fx1 - $fx0 + 1;
            $ch = $fy1 - $fy0 + 1;

            if (! $force && ($cw < (int) ceil($W * $minRatio) || $ch < (int) ceil($H * $minRatio))) {
                return $this->releaseSkip($full, $imagePath, 'crop_too_aggressive');
            }

            $ar = $cw / max(1, $ch);
            if (! $force && ($ar > $maxAspect || $ar < (1 / $maxAspect))) {
                return $this->releaseSkip($full, $imagePath, 'extreme_aspect');
            }

            $full->cropImage($cw, $ch, $fx0, $fy0);
            $full->setImagePage(0, 0, 0, 0);

            $out = tempnam(sys_get_temp_dir(), 'printcrop_').'.png';
            $full->setImageFormat('png');
            if (! $full->writeImage($out)) {
                return $this->releaseSkip($full, $imagePath, 'write_failed');
            }

            $full->clear();
            $full->destroy();

            $innerArea = max(1, $bbox['innerW'] * $bbox['innerH']);
            $boxArea = ($bbox['maxX'] - $bbox['minX'] + 1) * ($bbox['maxY'] - $bbox['minY'] + 1);
            $confidence = min(1.0, 0.5 + 0.45 * (1.0 - min(1.0, $boxArea / $innerArea)));

            return [
                'path' => $out,
                'applied' => true,
                'confidence' => round($confidence, 4),
                'bbox' => ['x' => $fx0, 'y' => $fy0, 'w' => $cw, 'h' => $ch],
            ];
        } catch (Throwable) {
            try {
                $full->clear();
                $full->destroy();
            } catch (Throwable) {
            }

            return $this->skip($imagePath, 'exception');
        }
    }

    /**
     * @return PrintCropResult
     */
    private function releaseSkip(Imagick $im, string $path, string $reason): array
    {
        try {
            $im->clear();
            $im->destroy();
        } catch (Throwable) {
        }

        return $this->skip($path, $reason);
    }

    /**
     * @return PrintCropResult
     */
    private function skip(string $path, string $reason): array
    {
        return [
            'path' => $path,
            'applied' => false,
            'confidence' => 0.0,
            'skip_reason' => $reason,
        ];
    }

    private function debugPrintCropOverlay(): bool
    {
        return (bool) config('assets.print_layout.debug_print_crop_overlay', false);
    }

    private function debugPrintCropForce(): bool
    {
        return (bool) config('assets.print_layout.debug_print_crop_force', false);
    }

    /**
     * Server-side only (queue worker / php-fpm container). Not composited into the app UI.
     * PRINT_LAYOUT_DEBUG_CROP=true → /tmp/debug-crop.png or /tmp/debug-crop-{suffix}.png
     */
    private function writeDebugCropOverlay(Imagick $original, int $x1, int $y1, int $x2, int $y2, ?string $fileSuffix = null): void
    {
        $safe = $fileSuffix !== null && $fileSuffix !== ''
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', $fileSuffix)
            : '';
        $path = $safe !== '' ? '/tmp/debug-crop-'.$safe.'.png' : '/tmp/debug-crop.png';

        try {
            $debug = clone $original;
            $draw = new ImagickDraw;
            $strokeW = max(2, (int) round(min($original->getImageWidth(), $original->getImageHeight()) * 0.004));
            $draw->setStrokeWidth($strokeW);
            $draw->setStrokeColor(new ImagickPixel('red'));
            $draw->setFillColor(new ImagickPixel('transparent'));
            $draw->setStrokeOpacity(1);
            $draw->setFillOpacity(0);
            $draw->rectangle((float) $x1, (float) $y1, (float) $x2, (float) $y2);
            $debug->drawImage($draw);
            $debug->setImageFormat('png');
            $debug->writeImage($path);
            $debug->clear();
            $debug->destroy();
            Log::info('[PrintLayoutCrop] Debug bbox overlay written (not shown in browser UI)', [
                'path' => $path,
                'bbox' => ['x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2],
            ]);
        } catch (Throwable $e) {
            Log::warning('[PrintLayoutCrop] Debug bbox overlay failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
