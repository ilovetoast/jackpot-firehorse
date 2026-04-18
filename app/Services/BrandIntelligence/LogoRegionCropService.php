<?php

namespace App\Services\BrandIntelligence;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Crop a normalized logo region (from VLM logo_presence.region) out of the
 * asset's visual evaluation raster and return a local PNG file path.
 *
 * Inputs are normalized coordinates (0..1). The service fetches the source
 * raster blob from storage (honoring the VisualEvaluationSourceResolver's pick),
 * crops via GD, and writes a temp PNG suitable for {@see \App\Services\ImageEmbeddingService::embedLocalImage()}.
 *
 * Caller is responsible for unlinking the returned file when done.
 */
final class LogoRegionCropService
{
    public function __construct(
        private VisualEvaluationSourceResolver $visualResolver,
    ) {}

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $region  Normalized 0..1 coordinates
     * @return string|null Absolute temp file path, or null when no raster / crop failed
     */
    public function cropRegion(Asset $asset, array $region): ?string
    {
        $sourceBlob = $this->loadSourceRasterBlob($asset);
        if ($sourceBlob === null || $sourceBlob === '') {
            return null;
        }

        $src = @imagecreatefromstring($sourceBlob);
        if ($src === false) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 8 || $sh < 8) {
            imagedestroy($src);

            return null;
        }

        $x = max(0.0, min(1.0, (float) ($region['x'] ?? 0)));
        $y = max(0.0, min(1.0, (float) ($region['y'] ?? 0)));
        $w = max(0.0, min(1.0, (float) ($region['w'] ?? 0)));
        $h = max(0.0, min(1.0, (float) ($region['h'] ?? 0)));
        if ($w < 0.01 || $h < 0.01) {
            imagedestroy($src);

            return null;
        }

        // Pad the crop by 10% of its own size to give the model some context
        // around the logo, but clamp to the image bounds.
        $padX = min($x, 1.0 - ($x + $w), $w * 0.10);
        $padY = min($y, 1.0 - ($y + $h), $h * 0.10);
        $x = max(0.0, $x - $padX);
        $y = max(0.0, $y - $padY);
        $w = min(1.0 - $x, $w + 2 * $padX);
        $h = min(1.0 - $y, $h + 2 * $padY);

        $cx = (int) round($x * $sw);
        $cy = (int) round($y * $sh);
        $cw = max(8, (int) round($w * $sw));
        $ch = max(8, (int) round($h * $sh));
        $cw = min($sw - $cx, $cw);
        $ch = min($sh - $cy, $ch);

        $cropped = @imagecrop($src, ['x' => $cx, 'y' => $cy, 'width' => $cw, 'height' => $ch]);
        imagedestroy($src);
        if ($cropped === false) {
            Log::warning('[LogoRegionCropService] imagecrop failed', [
                'asset_id' => $asset->id,
                'rect' => compact('cx', 'cy', 'cw', 'ch'),
            ]);

            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'logo_crop_');
        if ($tmp === false) {
            imagedestroy($cropped);

            return null;
        }
        $outPath = $tmp . '.png';
        @unlink($tmp);

        $ok = @imagepng($cropped, $outPath, 6);
        imagedestroy($cropped);
        if (! $ok || ! is_file($outPath)) {
            return null;
        }

        return $outPath;
    }

    /**
     * Fetch the bytes of the asset's visual-evaluation raster.
     *
     * Prefers the S3 disk (matches the rest of the codebase); falls back to
     * the public disk for local dev data.
     */
    private function loadSourceRasterBlob(Asset $asset): ?string
    {
        $resolved = $this->visualResolver->resolve($asset);
        $path = $resolved['storage_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        foreach (['s3', 'public', 'local'] as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if (! $disk->exists($path)) {
                    continue;
                }
                $blob = $disk->get($path);
                if (is_string($blob) && $blob !== '') {
                    return $blob;
                }
            } catch (\Throwable $e) {
                Log::debug('[LogoRegionCropService] Disk read failed; trying next', [
                    'asset_id' => $asset->id,
                    'disk' => $diskName,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}
