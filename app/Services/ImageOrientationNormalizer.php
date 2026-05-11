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
     * The native {@see \Imagick::autoOrientImage()} can silently no-op on some
     * Imagick / ImageMagick builds (or specific EXIF layouts) — in that case
     * we'd happily proceed downstream thinking pixels are upright when they're
     * not, and the manual rotate-asset path then re-rotates the raw raster
     * giving a 180° flip on photos with orientation 6/8 (e.g. older Canon
     * portraits). To prevent that, when the native call doesn't move pixels
     * for an orientation that *should* swap dimensions (5–8) — or doesn't run
     * at all for a non-1 tag — we fall back to a manual rotate/flop sequence
     * that matches the EXIF spec exactly.
     *
     * @return array{
     *   applied: bool,
     *   imagick_orientation_before: ?int,
     *   width_before: int,
     *   height_before: int,
     *   width_after: int,
     *   height_after: int,
     *   reset_to_topleft: bool,
     *   manual_fallback_applied: bool,
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
            $applied = false;
        }

        // Detect the silent-no-op case: native call ran but pixel dimensions
        // didn't swap for an orientation tag (5–8) that should have swapped
        // them. That means the call's a lie — we still have the raw raster.
        $manualFallback = false;
        $widthMid = (int) $im->getImageWidth();
        $heightMid = (int) $im->getImageHeight();
        if ($orientBefore !== null && $orientBefore >= 2 && $orientBefore <= 8) {
            $shouldSwap = self::exifOrientationSwapsDimensions($orientBefore);
            $didSwap = ($widthMid !== $wb) || ($heightMid !== $hb);
            if (! $applied || ($shouldSwap && ! $didSwap)) {
                if (self::applyExifOrientationToImagick($im, $orientBefore)) {
                    $manualFallback = true;
                    $applied = true;
                }
            }
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
            'manual_fallback_applied' => $manualFallback,
        ];
    }

    /**
     * Manually apply an EXIF orientation tag (1–8) to an Imagick image using
     * rotateImage / flopImage / flipImage. Used when {@see \Imagick::autoOrientImage()}
     * is unavailable (Imagick 3.7+ removed it) or silently no-ops.
     *
     * EXIF orientation → display transform:
     *   1 = top-left      : identity (no-op)
     *   2 = top-right     : flop (mirror horizontal)
     *   3 = bottom-right  : rotate 180
     *   4 = bottom-left   : flip (mirror vertical)
     *   5 = left-top      : flop + rotate 90 CW
     *   6 = right-top     : rotate 90 CW
     *   7 = right-bottom  : flop + rotate 90 CCW
     *   8 = left-bottom   : rotate 90 CCW
     *
     * Imagick::rotateImage: positive angle = clockwise (verified empirically
     * against Imagick 3.8.1 / ImageMagick 6.9.12 — the Imagick PHP docstring
     * is misleading, MagickRotateImage rotates CW for positive degrees per
     * ImageMagick spec).
     */
    private static function applyExifOrientationToImagick(\Imagick $im, int $orientation): bool
    {
        if ($orientation < 2 || $orientation > 8) {
            return false;
        }
        $bg = new \ImagickPixel('rgba(0,0,0,0)');
        try {
            switch ($orientation) {
                case 2:
                    $im->flopImage();
                    break;
                case 3:
                    $im->rotateImage($bg, 180.0);
                    break;
                case 4:
                    $im->flipImage();
                    break;
                case 5:
                    $im->flopImage();
                    $im->rotateImage($bg, 90.0);
                    break;
                case 6:
                    $im->rotateImage($bg, 90.0);
                    break;
                case 7:
                    $im->flopImage();
                    $im->rotateImage($bg, -90.0);
                    break;
                case 8:
                    $im->rotateImage($bg, -90.0);
                    break;
                default:
                    return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
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
