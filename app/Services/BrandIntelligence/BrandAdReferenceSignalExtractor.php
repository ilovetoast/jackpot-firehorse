<?php

namespace App\Services\BrandIntelligence;

use App\Models\Asset;
use App\Models\BrandAdReference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Extract low-level visual signals from an ad-reference image.
 *
 * The signals are deliberately cheap and coarse — they're nudges for the
 * recipe engine, not a computer-vision pipeline. The goal is to tell the
 * difference between "this brand leans dark + vibrant + photographic" and
 * "this brand leans light + muted + graphic", well enough to bias a few
 * ad-style tokens (backgroundPreference, voiceTone, etc.).
 *
 * Algorithm:
 *   1. Download the original bytes from S3.
 *   2. Hand them to Imagick and rescale to a small working size (360px
 *      longest edge). Shrinking first is the biggest perf win — a 4K
 *      reference image is pointless for palette/brightness estimation.
 *   3. Sample a uniform grid of pixels (32×32 = 1024 samples).
 *   4. From those samples, compute:
 *      - avg_brightness: luminance average (0..1, Rec. 709)
 *      - avg_saturation: HSL saturation average (0..1)
 *      - top_colors: 5 most-frequent 3-bit-per-channel color buckets
 *      - palette_kind: derived from top_colors cumulative weight
 *      - dominant_hue_bucket: warm/cool/neutral from the weighted hue mean
 *
 * If Imagick isn't available or the asset is unreadable, we return `null`
 * and the caller records a non-fatal failure on the reference row. The
 * recipe engine still works (it just falls back to pure-inference hints)
 * — references are additive, never required.
 */
class BrandAdReferenceSignalExtractor
{
    public const SIGNALS_VERSION = 1;

    /**
     * Longest-edge target for the working copy before sampling. Small
     * enough to be fast, large enough that 32×32 sampling still captures
     * the visual character (≈11px per sample step).
     */
    private const WORK_SIZE_PX = 360;

    private const GRID_STEPS = 32;

    /**
     * Extract + cache signals for a single reference. Returns the signal
     * array on success or null on any failure (writing diagnostics to
     * the reference row so the UI can surface "couldn't analyze this one").
     */
    public function extractForReference(BrandAdReference $reference): ?array
    {
        $reference->forceFill([
            'signals_extraction_attempted_at' => now(),
        ])->saveQuietly();

        $asset = $reference->asset()->first();
        if (! $asset) {
            $this->recordFailure($reference, 'Asset missing');
            return null;
        }

        try {
            $signals = $this->extractForAsset($asset);
        } catch (\Throwable $e) {
            $this->recordFailure($reference, $e->getMessage());
            Log::channel('pipeline')->warning('[BrandAdReferenceSignal] extraction failed', [
                'reference_id' => $reference->id,
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($signals === null) {
            $this->recordFailure($reference, 'Extraction returned no signals');
            return null;
        }

        $reference->forceFill([
            'signals' => $signals,
            'signals_extracted_at' => now(),
            'signals_extraction_error' => null,
        ])->saveQuietly();

        return $signals;
    }

    /**
     * Compute signals for an asset without touching the DB. Public so
     * callers that want to preview signals before persisting (e.g. an
     * asset-inspector UI) can reuse the same pipeline.
     */
    public function extractForAsset(Asset $asset): ?array
    {
        if (! extension_loaded('imagick')) {
            // We *could* fall back to GD, but GD has historically-bad JPEG
            // color handling and chokes on CMYK. Better to surface an
            // explicit "install Imagick" error than ship shaky signals.
            throw new \RuntimeException('Imagick extension is required for signal extraction.');
        }

        $bytes = $this->downloadBytes($asset);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $imagick = new \Imagick();
        try {
            $imagick->readImageBlob($bytes);
            // Force sRGB so our HSV math is meaningful even when the source
            // is CMYK or a tagged wide-gamut space.
            if (method_exists($imagick, 'transformImageColorspace')) {
                @$imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            // Flatten transparency against white so alpha-pixel luminance
            // doesn't pull the average toward 0.
            $imagick->setImageBackgroundColor('white');
            if (method_exists($imagick, 'mergeImageLayers')) {
                $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            }
            $imagick->setImageFormat('png');

            $w = $imagick->getImageWidth();
            $h = $imagick->getImageHeight();
            if ($w <= 0 || $h <= 0) {
                return null;
            }

            $scale = self::WORK_SIZE_PX / max($w, $h);
            if ($scale < 1.0) {
                $imagick->resizeImage(
                    (int) max(1, round($w * $scale)),
                    (int) max(1, round($h * $scale)),
                    \Imagick::FILTER_LANCZOS,
                    1,
                );
            }

            return $this->sampleSignals($imagick);
        } finally {
            $imagick->clear();
        }
    }

    private function downloadBytes(Asset $asset): ?string
    {
        $path = $asset->storage_root_path ?: $asset->currentVersion?->file_path;
        if (! $path) {
            return null;
        }
        try {
            return Storage::disk('s3')->get($path);
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandAdReferenceSignal] S3 get failed', [
                'asset_id' => $asset->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Sample the already-scaled Imagick image on a grid and compute the
     * aggregate stats. Public for tests; not expected to be called from
     * application code.
     */
    private function sampleSignals(\Imagick $imagick): ?array
    {
        $w = $imagick->getImageWidth();
        $h = $imagick->getImageHeight();
        if ($w <= 0 || $h <= 0) return null;

        $steps = self::GRID_STEPS;
        $stepX = max(1, (int) floor($w / $steps));
        $stepY = max(1, (int) floor($h / $steps));

        $totalBrightness = 0.0;
        $totalSaturation = 0.0;
        $samples = 0;
        $buckets = []; // key = 3-bit-per-channel bucket (0..511), value = count
        $hueSin = 0.0; // weighted circular mean accumulators
        $hueCos = 0.0;
        $hueWeight = 0.0;

        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $pixel = $imagick->getImagePixelColor($x, $y);
                // Imagick returns 0..1 floats when normalized=true.
                $c = $pixel->getColor(1);
                $r = (float) $c['r'];
                $g = (float) $c['g'];
                $b = (float) $c['b'];

                // Rec. 709 luminance — better perceptual match than plain avg.
                $luma = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

                // HSL saturation via max/min-of-channels.
                $cmax = max($r, $g, $b);
                $cmin = min($r, $g, $b);
                $delta = $cmax - $cmin;
                $lightness = ($cmax + $cmin) / 2.0;
                $sat = 0.0;
                if ($delta > 1e-6) {
                    $sat = $lightness > 0.5
                        ? $delta / (2.0 - $cmax - $cmin)
                        : $delta / ($cmax + $cmin + 1e-9);
                }

                $hue = 0.0;
                if ($delta > 1e-6) {
                    if ($cmax === $r) {
                        $hue = fmod((($g - $b) / $delta), 6.0);
                    } elseif ($cmax === $g) {
                        $hue = (($b - $r) / $delta) + 2.0;
                    } else {
                        $hue = (($r - $g) / $delta) + 4.0;
                    }
                    $hue *= 60.0;
                    if ($hue < 0) $hue += 360.0;
                }

                $totalBrightness += $luma;
                $totalSaturation += $sat;

                // Only weigh hue by saturation — grayscale pixels carry no
                // directional info and would muddy the warm/cool average.
                if ($sat > 0.1) {
                    $rad = deg2rad($hue);
                    $w_ = $sat;
                    $hueSin += sin($rad) * $w_;
                    $hueCos += cos($rad) * $w_;
                    $hueWeight += $w_;
                }

                // 3 bits per channel → 8×8×8 = 512 buckets. Coarse enough
                // that near-identical photos cluster together but fine
                // enough to distinguish "black + red" from "black + blue".
                $rb = (int) (floor($r * 7.999));
                $gb = (int) (floor($g * 7.999));
                $bb = (int) (floor($b * 7.999));
                $bucketKey = ($rb << 6) | ($gb << 3) | $bb;
                $buckets[$bucketKey] = ($buckets[$bucketKey] ?? 0) + 1;

                $samples++;
            }
        }

        if ($samples === 0) return null;

        $avgBrightness = $totalBrightness / $samples;
        $avgSaturation = $totalSaturation / $samples;

        // Dominant hue (circular mean) — only meaningful when we saw
        // enough saturated pixels to have a direction.
        $dominantHueBucket = 'neutral';
        if ($hueWeight > 0) {
            $meanHue = fmod(rad2deg(atan2($hueSin, $hueCos)) + 360.0, 360.0);
            $dominantHueBucket = $this->hueBucket($meanHue, $avgSaturation);
        }

        // Top colors — sort buckets desc, take 5, convert each bucket id
        // back to a representative hex (bucket center, not per-pixel mean).
        arsort($buckets);
        $topBuckets = array_slice($buckets, 0, 5, true);
        $topColors = [];
        $topWeight = 0;
        foreach ($topBuckets as $key => $count) {
            $topColors[] = [
                'hex' => $this->bucketToHex($key),
                'weight' => round($count / $samples, 4),
            ];
            $topWeight += $count;
        }

        // palette_kind: how concentrated is the image in its top buckets?
        //   monochrome: top bucket >= 65% of samples (tonally uniform)
        //   duochrome:  top 2 buckets >= 65% total (graphic, two-tone)
        //   polychrome: everything else (photographic / rich palette)
        $paletteKind = 'polychrome';
        if (! empty($topColors)) {
            $firstShare = $topColors[0]['weight'] ?? 0;
            $secondShare = $topColors[1]['weight'] ?? 0;
            if ($firstShare >= 0.65) {
                $paletteKind = 'monochrome';
            } elseif (($firstShare + $secondShare) >= 0.65) {
                $paletteKind = 'duochrome';
            }
        }

        return [
            'version' => self::SIGNALS_VERSION,
            'avg_brightness' => round($avgBrightness, 4),
            'avg_saturation' => round($avgSaturation, 4),
            'palette_kind' => $paletteKind,
            'dominant_hue_bucket' => $dominantHueBucket,
            'top_colors' => $topColors,
            'extracted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Map a degrees-hue + saturation signal to a human-ish family.
     *   warm:    red/orange/yellow (0..60, 330..360)
     *   cool:    cyan/blue/purple  (180..300)
     *   neutral: greens / low saturation (everything else, or sat < 0.15)
     *
     * Neutral is the default when saturation is low across the image —
     * grayscale or near-grayscale references shouldn't claim a direction.
     */
    private function hueBucket(float $hue, float $avgSaturation): string
    {
        if ($avgSaturation < 0.15) return 'neutral';
        if ($hue < 60 || $hue >= 330) return 'warm';
        if ($hue >= 60 && $hue < 180) return 'neutral'; // yellow-green → green span
        if ($hue >= 180 && $hue < 300) return 'cool';
        return 'warm'; // 300..330 pink/magenta leans warm
    }

    private function bucketToHex(int $bucket): string
    {
        $rb = ($bucket >> 6) & 0x7;
        $gb = ($bucket >> 3) & 0x7;
        $bb = $bucket & 0x7;
        // Center of the bucket: (b + 0.5) / 8 * 255
        $r = (int) round((($rb + 0.5) / 8.0) * 255);
        $g = (int) round((($gb + 0.5) / 8.0) * 255);
        $b = (int) round((($bb + 0.5) / 8.0) * 255);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function recordFailure(BrandAdReference $reference, string $message): void
    {
        $reference->forceFill([
            'signals_extraction_error' => \Illuminate\Support\Str::limit($message, 495),
        ])->saveQuietly();
    }
}
