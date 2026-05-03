<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Central EXIF / TIFF orientation handling for raster derivatives.
 *
 * Thumbnails must not depend on {@see \App\Jobs\ExtractMetadataJob} — orientation is applied when
 * decoding sources for {@see ThumbnailGenerationService} and related Imagick paths.
 */
final class ImageOrientationNormalizer
{
    /**
     * Read standard EXIF Orientation (1–8) from a file when the exif extension is available.
     */
    public static function readExifOrientationTag(string $path): ?int
    {
        if (! is_readable($path) || ! function_exists('exif_read_data')) {
            return null;
        }
        try {
            $exif = @exif_read_data($path, 'IFD0', true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($exif)) {
            return null;
        }
        $o = $exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? null;
        if ($o === null) {
            return null;
        }
        $n = (int) $o;

        return ($n >= 1 && $n <= 8) ? $n : null;
    }

    /**
     * Apply Imagick auto-orientation, then reset orientation metadata to top-left so downstream
     * encoders and browsers do not double-rotate.
     *
     * @return array{
     *   applied: bool,
     *   imagick_orientation_before: ?int,
     *   width_before: int,
     *   height_before: int,
     *   width_after: int,
     *   height_after: int,
     *   reset_to_topleft: bool,
     * }
     */
    public static function imagickAutoOrientAndResetOrientation(\Imagick $im): array
    {
        $wb = (int) $im->getImageWidth();
        $hb = (int) $im->getImageHeight();
        $orientBefore = null;
        if (method_exists($im, 'getImageOrientation')) {
            try {
                $orientBefore = (int) $im->getImageOrientation();
            } catch (\Throwable) {
                $orientBefore = null;
            }
        }

        $applied = false;
        try {
            if (method_exists($im, 'autoOrientImage')) {
                $im->autoOrientImage();
                $applied = true;
            }
        } catch (\Throwable) {
        }

        $reset = false;
        try {
            if (defined('Imagick::ORIENTATION_TOPLEFT') && method_exists($im, 'setImageOrientation')) {
                $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                $reset = true;
            }
        } catch (\Throwable) {
        }

        try {
            if (method_exists($im, 'stripImage')) {
                $im->stripImage();
            }
        } catch (\Throwable) {
        }

        return [
            'applied' => $applied,
            'imagick_orientation_before' => $orientBefore,
            'width_before' => $wb,
            'height_before' => $hb,
            'width_after' => (int) $im->getImageWidth(),
            'height_after' => (int) $im->getImageHeight(),
            'reset_to_topleft' => $reset,
        ];
    }

    /**
     * Decode a raster file to a flat, upright PNG suitable for GD thumbnail sizing.
     *
     * When Imagick is available, uses autoOrient + TOPLEFT + strip (preferred). On failure or
     * missing Imagick, returns the original path and type for legacy GD decode (JPEG EXIF may be ignored).
     *
     * @return array{
     *   path: string,
     *   cleanup: bool,
     *   imagetype: int,
     *   profile: array<string, mixed>,
     * }
     */
    public static function prepareFlatRasterForGdThumbnail(string $sourcePath): array
    {
        $exifTag = self::readExifOrientationTag($sourcePath);
        $info = @getimagesize($sourcePath);
        $baseProfile = [
            'pipeline' => 'gd_decode',
            'exif_orientation_tag' => $exifTag,
            'method' => 'gd_raw_path',
            'auto_orient_applied' => false,
        ];
        if (is_array($info)) {
            $baseProfile['width_before'] = (int) $info[0];
            $baseProfile['height_before'] = (int) $info[1];
            $baseProfile['width_after'] = (int) $info[0];
            $baseProfile['height_after'] = (int) $info[1];
        }

        if (! extension_loaded('imagick') || ! is_readable($sourcePath)) {
            $type = is_array($info) ? (int) $info[2] : IMAGETYPE_JPEG;

            return [
                'path' => $sourcePath,
                'cleanup' => false,
                'imagetype' => $type,
                'profile' => $baseProfile,
            ];
        }

        $m = null;
        try {
            $m = new \Imagick;
            $m->readImage($sourcePath);
            if ($m->getNumberImages() > 1) {
                $m->setIteratorIndex(0);
                $first = $m->getImage();
                $m->clear();
                $m->destroy();
                $m = $first;
            }

            $diag = self::imagickAutoOrientAndResetOrientation($m);
            $m->setImageFormat('png');
            $m->setImageCompressionQuality(100);
            $tmp = tempnam(sys_get_temp_dir(), 'jp_orient_').'.png';
            if (! $m->writeImage($tmp)) {
                $m->clear();
                $m->destroy();
                $m = null;

                throw new \RuntimeException('writeImage failed');
            }
            $m->clear();
            $m->destroy();
            $m = null;

            $profile = array_merge($baseProfile, $diag, [
                'pipeline' => 'imagick_flat_png_for_gd',
                'method' => 'imagick_flat_png',
                'auto_orient_applied' => $diag['applied'] || ($diag['width_before'] !== $diag['width_after'] || $diag['height_before'] !== $diag['height_after']),
            ]);

            return [
                'path' => $tmp,
                'cleanup' => true,
                'imagetype' => IMAGETYPE_PNG,
                'profile' => $profile,
            ];
        } catch (\Throwable) {
            if ($m instanceof \Imagick) {
                try {
                    $m->clear();
                    $m->destroy();
                } catch (\Throwable) {
                }
            }
            $type = is_array($info) ? (int) $info[2] : IMAGETYPE_JPEG;

            return [
                'path' => $sourcePath,
                'cleanup' => false,
                'imagetype' => $type,
                'profile' => array_merge($baseProfile, [
                    'method' => 'gd_raw_path_imagick_failed',
                ]),
            ];
        }
    }
}
