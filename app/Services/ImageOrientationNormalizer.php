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
     * EXIF orientations 5–8 transpose the stored raster vs display (width/height swap when corrected).
     */
    private static function exifOrientationSwapsDimensions(int $tag): bool
    {
        return $tag >= 5 && $tag <= 8;
    }

    /**
     * True when Imagick likely left pixels unrotated while EXIF says otherwise (browser blob looked
     * correct; GD raw decode + server thumb looked wrong).
     *
     * @param  array<int, mixed>|false  $srcSize  getimagesize() of original
     * @param  array<int, mixed>|false  $flatSize  getimagesize() of flat PNG from Imagick
     * @param  array<string, mixed>  $diag  {@see imagickAutoOrientAndResetOrientation()}
     */
    private static function imagickFlatLikelyWrong(?int $exifTag, array|false $srcSize, array|false $flatSize, array $diag): bool
    {
        if ($exifTag === null || $exifTag === 1) {
            return false;
        }
        if (! (bool) ($diag['applied'] ?? false)) {
            return true;
        }
        if (! self::exifOrientationSwapsDimensions($exifTag)) {
            return false;
        }
        if (! is_array($srcSize) || ! is_array($flatSize)) {
            return true;
        }

        return (int) $flatSize[0] === (int) $srcSize[0]
            && (int) $flatSize[1] === (int) $srcSize[1];
    }

    /**
     * Apply standard EXIF orientation (1–8) to a GD image (JPEG decode; ignores embedded EXIF for pixels).
     * Mapping matches WordPress {@see \WP_Image_Editor::maybe_exif_rotate()} (angle = degrees CCW).
     *
     * @return \GdImage|false  Upright image, or false on failure
     */
    private static function applyExifOrientationToGdImage(\GdImage $im, int $orientation): \GdImage|false
    {
        if ($orientation < 2 || $orientation > 8) {
            return $im;
        }
        if (! function_exists('imagerotate')) {
            return false;
        }

        $rotateCcw = function (\GdImage $g, float $angleCcw): \GdImage|false {
            $bg = imagecolorallocatealpha($g, 255, 255, 255, 127);
            if ($bg === false) {
                return false;
            }
            $out = imagerotate($g, $angleCcw, $bg);
            imagedestroy($g);

            return $out instanceof \GdImage ? $out : false;
        };

        $cur = $im;

        switch ($orientation) {
            case 2:
                imageflip($cur, IMG_FLIP_HORIZONTAL);

                return $cur;
            case 3:
                if (defined('IMG_FLIP_BOTH')) {
                    imageflip($cur, IMG_FLIP_BOTH);
                } else {
                    imageflip($cur, IMG_FLIP_HORIZONTAL);
                    imageflip($cur, IMG_FLIP_VERTICAL);
                }

                return $cur;
            case 4:
                imageflip($cur, IMG_FLIP_VERTICAL);

                return $cur;
            case 5:
                $cur = $rotateCcw($cur, 90.0);
                if ($cur === false) {
                    return false;
                }
                imageflip($cur, IMG_FLIP_VERTICAL);

                return $cur;
            case 6:
                return $rotateCcw($cur, 270.0);
            case 7:
                $cur = $rotateCcw($cur, 90.0);
                if ($cur === false) {
                    return false;
                }
                imageflip($cur, IMG_FLIP_HORIZONTAL);

                return $cur;
            case 8:
                return $rotateCcw($cur, 90.0);
        }

        return $cur;
    }

    /**
     * JPEG-only: bake EXIF orientation into pixels via GD, then PNG for the GD thumbnail pipeline.
     *
     * @param  array<string, mixed>  $baseProfile
     * @return array{path: string, cleanup: bool, imagetype: int, profile: array<string, mixed>}|null
     */
    private static function tryGdExifFlatPng(string $sourcePath, ?int $exifTag, array $baseProfile): ?array
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromjpeg') || ! function_exists('imagepng')) {
            return null;
        }
        if ($exifTag === null || $exifTag === 1) {
            return null;
        }
        if (! function_exists('imageflip')) {
            return null;
        }

        $info = @getimagesize($sourcePath);
        if (! is_array($info) || (int) $info[2] !== IMAGETYPE_JPEG) {
            return null;
        }

        $im = @imagecreatefromjpeg($sourcePath);
        if (! $im instanceof \GdImage) {
            return null;
        }

        $out = self::applyExifOrientationToGdImage($im, $exifTag);
        if ($out === false) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'jp_gd_orient_').'.png';
        if (! imagepng($out, $tmp, 6)) {
            imagedestroy($out);

            return null;
        }
        imagedestroy($out);

        $dims = @getimagesize($tmp);
        $profile = array_merge($baseProfile, [
            'pipeline' => 'gd_exif_flat_png',
            'method' => 'gd_exif_png',
            'auto_orient_applied' => true,
        ]);
        if (is_array($dims)) {
            $profile['width_after'] = (int) $dims[0];
            $profile['height_after'] = (int) $dims[1];
        }

        return [
            'path' => $tmp,
            'cleanup' => true,
            'imagetype' => IMAGETYPE_PNG,
            'profile' => $profile,
        ];
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
     * When Imagick is available, uses autoOrient + TOPLEFT + strip (preferred). If Imagick fails,
     * auto-orient did not run, or swap orientations (5-8) still match raw dimensions, JPEGs fall back to
     * a GD+EXIF bake into a flat PNG. Otherwise returns the original path for legacy GD decode.
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
            $gdFlat = self::tryGdExifFlatPng($sourcePath, $exifTag, $baseProfile);
            if ($gdFlat !== null) {
                return $gdFlat;
            }
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

            $flatInfo = @getimagesize($tmp);
            if (self::imagickFlatLikelyWrong($exifTag, $info, $flatInfo, $diag)) {
                $gdFlat = self::tryGdExifFlatPng($sourcePath, $exifTag, $baseProfile);
                if ($gdFlat !== null) {
                    @unlink($tmp);

                    return $gdFlat;
                }
            }

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
            $gdFlat = self::tryGdExifFlatPng($sourcePath, $exifTag, $baseProfile);
            if ($gdFlat !== null) {
                return $gdFlat;
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
