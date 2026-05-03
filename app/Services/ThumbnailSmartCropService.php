<?php

namespace App\Services;

use Imagick;
use ImagickException;
use ImagickPixel;
use Throwable;

/**
 * Smart crop for "preferred" thumbnails: trim near-white / transparent margins with guardrails.
 *
 * @phpstan-type SmartCropSignals array{trim_ratio: float|null, edge_density: float|null, padding_applied: bool}
 * @phpstan-type SmartCropResult array{path: string, applied: bool, confidence: float, skip_reason?: string, signals: SmartCropSignals}
 */
final class ThumbnailSmartCropService
{
    /**
     * @return SmartCropResult
     */
    public function smartCrop(string $imagePath): array
    {
        if (! extension_loaded('imagick')) {
            return $this->skip($imagePath, 'no_imagick');
        }

        if (! is_readable($imagePath) || filesize($imagePath) === 0) {
            return $this->skip($imagePath, 'unreadable');
        }

        $minDim = (int) config('assets.thumbnail.preferred.smart_crop.min_dimension', 400);
        $tightRatio = (float) config('assets.thumbnail.preferred.smart_crop.tight_area_ratio', 0.95);
        $maxContentRatio = (float) config('assets.thumbnail.preferred.smart_crop.max_content_area_ratio', 0.80);
        $paddingFraction = (float) config('assets.thumbnail.preferred.smart_crop.padding_fraction', 0.07);
        $fuzzFrac = (float) config('assets.thumbnail.preferred.smart_crop.fuzz_quantum_fraction', 0.08);
        $minContentRatio = (float) config('assets.thumbnail.preferred.smart_crop.min_content_area_ratio', 0.05);

        try {
            $im = new Imagick($imagePath);
        } catch (ImagickException|Throwable $e) {
            return $this->skip($imagePath, 'unsupported_format');
        }

        try {
            if ($im->getNumberImages() > 1) {
                $im->setIteratorIndex(0);
            }
            ImageOrientationNormalizer::imagickAutoOrientAndResetOrientation($im);

            $w0 = $im->getImageWidth();
            $h0 = $im->getImageHeight();
            if ($w0 < 1 || $h0 < 1) {
                return $this->releaseAndSkip($im, $imagePath, 'invalid_dimensions');
            }

            $area0 = $w0 * $h0;
            $edgeDensity = $this->sampleEdgeDensity($im);

            if (min($w0, $h0) < $minDim) {
                return $this->releaseAndSkip($im, $imagePath, 'small_dimensions', null, $edgeDensity);
            }

            $q = $im->getQuantumRange();
            $fuzz = $fuzzFrac * (float) ($q['quantumRangeLong'] ?? 65535);

            $probe = clone $im;
            try {
                if ($probe->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_UNDEFINED) {
                    $probe->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                }
                $probe->trimImage($fuzz);
                $probe->setImagePage(0, 0, 0, 0);
                $w1 = $probe->getImageWidth();
                $h1 = $probe->getImageHeight();
            } finally {
                $probe->clear();
                $probe->destroy();
            }

            $area1 = max(1, $w1 * $h1);
            $ratio = $area1 / max(1, $area0);
            $trimRatio = max(0.0, min(1.0, 1.0 - $ratio));

            if ($ratio >= $tightRatio) {
                return $this->releaseAndSkip($im, $imagePath, 'already_tight', $trimRatio, $edgeDensity);
            }

            if ($ratio > $maxContentRatio) {
                return $this->releaseAndSkip($im, $imagePath, 'marginal_whitespace', $trimRatio, $edgeDensity);
            }

            if ($ratio < $minContentRatio) {
                return $this->releaseAndSkip($im, $imagePath, 'trim_too_aggressive', $trimRatio, $edgeDensity);
            }

            if ($im->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_UNDEFINED) {
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            }
            $im->trimImage($fuzz);
            $im->setImagePage(0, 0, 0, 0);

            $tw = $im->getImageWidth();
            $th = $im->getImageHeight();
            $pad = (int) max(1, round($paddingFraction * max($tw, $th)));

            // White border keeps downstream GD thumbnail generation predictable across IM builds.
            $im->borderImage(new ImagickPixel('#ffffff'), $pad, $pad);

            $out = tempnam(sys_get_temp_dir(), 'smartcrop_').'.png';
            $im->setImageFormat('png');
            if (! $im->writeImage($out)) {
                return $this->releaseAndSkip($im, $imagePath, 'write_failed', $trimRatio, $edgeDensity);
            }

            $confidence = $this->blendConfidence($trimRatio, $edgeDensity, true);

            $im->clear();
            $im->destroy();

            return [
                'path' => $out,
                'applied' => true,
                'confidence' => $confidence,
                'signals' => [
                    'trim_ratio' => round($trimRatio, 4),
                    'edge_density' => round($edgeDensity, 4),
                    'padding_applied' => true,
                ],
            ];
        } catch (Throwable $e) {
            try {
                $im->clear();
                $im->destroy();
            } catch (Throwable) {
            }

            return $this->skip($imagePath, 'unsupported_format');
        }
    }

    /**
     * Fraction of sampled border pixels that are not near-white / not fully transparent (0–1).
     */
    private function sampleEdgeDensity(Imagick $source): float
    {
        try {
            $im = $source->cloneImage();
        } catch (Throwable) {
            return 0.0;
        }

        try {
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w < 2 || $h < 2) {
                return 0.0;
            }

            $maxSide = 256;
            if ($w > $maxSide || $h > $maxSide) {
                $im->thumbnailImage($maxSide, $maxSide, true);
            }

            $cw = $im->getImageWidth();
            $ch = $im->getImageHeight();
            $hits = 0;
            $count = 0;

            for ($x = 0; $x < $cw; $x++) {
                foreach ([0, $ch - 1] as $y) {
                    $count++;
                    if ($this->pixelIsNonBackground($im, $x, $y)) {
                        $hits++;
                    }
                }
            }
            for ($y = 1; $y < $ch - 1; $y++) {
                foreach ([0, $cw - 1] as $x) {
                    $count++;
                    if ($this->pixelIsNonBackground($im, $x, $y)) {
                        $hits++;
                    }
                }
            }

            return $count > 0 ? min(1.0, $hits / $count) : 0.0;
        } catch (Throwable) {
            return 0.0;
        } finally {
            try {
                $im->clear();
                $im->destroy();
            } catch (Throwable) {
            }
        }
    }

    private function pixelIsNonBackground(Imagick $im, int $x, int $y): bool
    {
        try {
            $pixel = $im->getImagePixelColor($x, $y);
            $c = $pixel->getColor(true);
            $r = (float) ($c['r'] ?? 0);
            $g = (float) ($c['g'] ?? 0);
            $b = (float) ($c['b'] ?? 0);
            $a = (float) ($c['a'] ?? 1.0);
            $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;

            return $a > 0.08 && $lum < 0.94;
        } catch (Throwable) {
            return false;
        }
    }

    private function blendConfidence(float $trimRatio, float $edgeDensity, bool $paddingApplied): float
    {
        $trimNorm = min(1.0, $trimRatio / 0.45);
        $edgeNorm = min(1.0, $edgeDensity);
        $padBump = $paddingApplied ? 0.12 : 0.0;

        return min(1.0, max(0.0, $trimNorm * 0.5 + $edgeNorm * 0.38 + $padBump));
    }

    /**
     * @return SmartCropResult
     */
    private function releaseAndSkip(
        Imagick $im,
        string $path,
        string $reason,
        ?float $trimRatio = null,
        ?float $edgeDensity = null
    ): array {
        try {
            $im->clear();
            $im->destroy();
        } catch (Throwable) {
        }

        return $this->skip($path, $reason, $trimRatio, $edgeDensity);
    }

    /**
     * @return SmartCropResult
     */
    private function skip(string $path, string $reason, ?float $trimRatio = null, ?float $edgeDensity = null): array
    {
        return [
            'path' => $path,
            'applied' => false,
            'confidence' => 0.0,
            'skip_reason' => $reason,
            'signals' => [
                'trim_ratio' => $trimRatio !== null ? round($trimRatio, 4) : null,
                'edge_density' => $edgeDensity !== null ? round($edgeDensity, 4) : null,
                'padding_applied' => false,
            ],
        ];
    }
}
