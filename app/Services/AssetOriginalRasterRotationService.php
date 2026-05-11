<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateThumbnailsJob;
use App\Models\Asset;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\PipelineQueueResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Imagick;

/**
 * Rotates the current asset version raster in storage (pixels + normalized orientation),
 * then queues thumbnail regeneration.
 */
final class AssetOriginalRasterRotationService
{
    /** @var list<int> */
    public const ALLOWED_DEGREES_CLOCKWISE = [90, 180, 270];

    /**
     * @return array{width: int, height: int, size_bytes: int}
     */
    public function rotateCurrentVersionClockwise(Asset $asset, int $degreesClockwise): array
    {
        $degreesClockwise = ((int) $degreesClockwise % 360 + 360) % 360;
        if ($degreesClockwise === 0) {
            throw new \InvalidArgumentException('Rotation must be non-zero.');
        }
        if (! in_array($degreesClockwise, self::ALLOWED_DEGREES_CLOCKWISE, true)) {
            throw new \InvalidArgumentException('Rotation must be 90, 180, or 270 degrees clockwise.');
        }

        if (! extension_loaded('imagick') || ! class_exists(Imagick::class)) {
            throw new \RuntimeException('Imagick extension is required to rotate originals in place.');
        }

        $asset->loadMissing('currentVersion', 'storageBucket', 'tenant');
        $version = $asset->currentVersion;
        if (! $version || ! is_string($version->file_path) || $version->file_path === '') {
            throw new \InvalidArgumentException('Asset has no current file version.');
        }

        $mime = strtolower((string) ($version->mime_type ?: $asset->mime_type ?: ''));
        if (! str_starts_with($mime, 'image/')) {
            throw new \InvalidArgumentException('Only image assets can be rotated.');
        }
        if (str_contains($mime, 'gif')) {
            throw new \InvalidArgumentException('GIF originals cannot be rotated in place.');
        }
        if (str_contains($mime, 'svg') || str_contains($mime, 'image/svg')) {
            throw new \InvalidArgumentException('Vector images cannot be rotated with this tool.');
        }

        $objectKey = $version->file_path;
        $originalBytes = EditorAssetOriginalBytesLoader::loadFromStorage($asset, $objectKey);

        $newBytes = $this->transformBytes($originalBytes, $mime, $degreesClockwise);
        $newSize = strlen($newBytes);

        $contentType = match (true) {
            str_contains($mime, 'png') => 'image/png',
            str_contains($mime, 'webp') => 'image/webp',
            default => 'image/jpeg',
        };

        $putOptions = [
            'visibility' => 'private',
            'ContentType' => $contentType,
        ];

        try {
            EditorAssetOriginalBytesLoader::put($asset, $objectKey, $newBytes, $putOptions);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to save rotated image: '.$e->getMessage(), 0, $e);
        }

        $imProbe = new Imagick;
        $imProbe->readImageBlob($newBytes);
        $width = (int) $imProbe->getImageWidth();
        $height = (int) $imProbe->getImageHeight();
        $imProbe->clear();
        $imProbe->destroy();

        $checksum = hash('sha256', $newBytes);

        DB::transaction(function () use ($asset, $version, $width, $height, $newSize, $checksum): void {
            $version->width = $width;
            $version->height = $height;
            $version->file_size = $newSize;
            $version->checksum = $checksum;
            $version->save();

            $asset->width = $width;
            $asset->height = $height;
            $asset->size_bytes = $newSize;
            $asset->save();
        });

        $payloadId = (string) $version->id;
        GenerateThumbnailsJob::dispatch($payloadId, true)->onQueue(PipelineQueueResolver::imagesQueueForAsset($asset));

        Log::info('[AssetOriginalRasterRotationService] Rotated original and queued thumbnails', [
            'asset_id' => $asset->id,
            'version_id' => $version->id,
            'degrees_cw' => $degreesClockwise,
        ]);

        return [
            'width' => $width,
            'height' => $height,
            'size_bytes' => $newSize,
        ];
    }

    private function transformBytes(string $bytes, string $mime, int $degreesClockwise): string
    {
        $im = new Imagick;
        $im->readImageBlob($bytes);
        $im->setFirstIterator();

        // CRITICAL: this MUST upright the pixel data before we apply the
        // user's rotation. If EXIF normalization silently no-ops (some
        // Imagick builds / specific EXIF layouts), we'd rotate the raw
        // sensor raster instead of the upright display image, producing a
        // visible 180° flip for portraits with orientation tag 6/8 — the
        // exact bug surfaced on staging with older Canon JPEGs. The
        // normalizer now has a manual rotate/flop fallback; the assertion
        // here is a hard guard so a future regression in the normalizer
        // can't silently corrupt user rotations.
        $diag = ImageOrientationNormalizer::imagickAutoOrientAndResetOrientation($im);
        $tagBefore = $diag['imagick_orientation_before'] ?? null;
        if (is_int($tagBefore) && $tagBefore > 1 && ! ($diag['applied'] ?? false)) {
            throw new \RuntimeException(
                "Refusing to rotate: EXIF orientation tag {$tagBefore} could not be normalized. ".
                'Aborting to avoid 180° flip from rotating raw raster.',
            );
        }

        // Imagick::rotateImage: positive angle = CLOCKWISE (verified empirically
        // against Imagick 3.8.1 / ImageMagick 6.9.12). The Imagick PHP docstring
        // claims CCW which is wrong — MagickRotateImage rotates CW for positive
        // degrees per the ImageMagick C API. The previous `-1.0 *` multiplier
        // here was based on that misleading docstring and silently produced
        // CCW output for every "rotate clockwise" click, which combined with
        // the EXIF normalization no-op below produced a 180° flip on portraits
        // with orientation tag 6/8 (e.g. older Canon JPEGs).
        $im->rotateImage(new \ImagickPixel('rgba(0,0,0,0)'), (float) $degreesClockwise);

        if (defined('Imagick::ORIENTATION_TOPLEFT') && method_exists($im, 'setImageOrientation')) {
            $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        }

        if (str_contains($mime, 'png')) {
            $im->setImageFormat('png');
        } elseif (str_contains($mime, 'webp')) {
            $im->setImageFormat('webp');
        } else {
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(92);
        }

        $out = $im->getImageBlob();
        $im->clear();
        $im->destroy();

        if (! is_string($out) || $out === '') {
            throw new \RuntimeException('Rotated image encoding produced empty output.');
        }

        return $out;
    }
}
