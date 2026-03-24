<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * OpenAI /v1/images/edits is strict about input format; re-encode as PNG and bound size/bytes.
 */
final class EditorOpenAiImageNormalizer
{
    private const MAX_EDGE_PX = 4096;

    private const MAX_BYTES = 4 * 1024 * 1024;

    /**
     * Decode arbitrary raster bytes (JPEG/PNG/GIF/WebP when GD supports them) and return PNG bytes.
     *
     * @throws \InvalidArgumentException
     */
    public static function toPngForOpenAiEdits(string $binary, int $depth = 0, ?string $sourceMime = null): string
    {
        if ($binary === '') {
            throw new \InvalidArgumentException('Empty image data.');
        }

        if ($depth > 3) {
            throw new \InvalidArgumentException(self::unsupportedFormatUserMessage());
        }

        if (! function_exists('imagecreatefromstring')) {
            return $binary;
        }

        $im = @imagecreatefromstring($binary);
        if ($im === false) {
            $fromImagick = self::tryImagickBlobToPng($binary, $sourceMime);
            if ($fromImagick !== null) {
                return self::toPngForOpenAiEdits($fromImagick, $depth + 1, 'image/png');
            }

            throw new \InvalidArgumentException(self::unsupportedFormatUserMessage());
        }

        try {
            $w = imagesx($im);
            $h = imagesy($im);
            if ($w < 10 || $h < 10) {
                throw new \InvalidArgumentException('Invalid image dimensions.');
            }

            if ($w > self::MAX_EDGE_PX || $h > self::MAX_EDGE_PX) {
                $scale = min(self::MAX_EDGE_PX / $w, self::MAX_EDGE_PX / $h);
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $scaled = imagescale($im, $nw, $nh);
                if ($scaled !== false) {
                    imagedestroy($im);
                    $im = $scaled;
                }
            }

            if (function_exists('imageistruecolor') && imageistruecolor($im)) {
                imagealphablending($im, false);
                imagesavealpha($im, true);
            }

            $png = self::pngEncode($im);

            $guard = 0;
            while (strlen($png) > self::MAX_BYTES && $guard < 10) {
                $guard++;
                $nw = max(256, (int) (imagesx($im) * 0.82));
                $nh = max(256, (int) (imagesy($im) * 0.82));
                $scaled = imagescale($im, $nw, $nh);
                if ($scaled === false) {
                    break;
                }
                imagedestroy($im);
                $im = $scaled;
                if (function_exists('imageistruecolor') && imageistruecolor($im)) {
                    imagealphablending($im, false);
                    imagesavealpha($im, true);
                }
                $png = self::pngEncode($im);
            }

            if (strlen($png) > self::MAX_BYTES) {
                throw new \InvalidArgumentException('Image is too large after processing. Try a smaller source image.');
            }

            return $png;
        } finally {
            if (isset($im) && (is_resource($im) || $im instanceof \GdImage)) {
                imagedestroy($im);
            }
        }
    }

    public static function unsupportedFormatUserMessage(): string
    {
        return 'This image format is not supported for editing. Please use a PNG or JPG original asset (not a thumbnail).';
    }

    /**
     * @param  resource|\GdImage  $im
     */
    private static function pngEncode($im): string
    {
        ob_start();
        imagepng($im, null, 6, PNG_ALL_FILTERS);
        $out = ob_get_clean();
        if (! is_string($out) || $out === '') {
            throw new \RuntimeException('Failed to encode PNG.');
        }

        return $out;
    }

    /**
     * AVIF, HEIC, some TIFF/CMYK JPEG, etc. fail GD's imagecreatefromstring; Imagick often reads them when installed.
     *
     * @return non-empty-string|null PNG bytes, or null if Imagick is unavailable or cannot decode
     */
    private static function tryImagickBlobToPng(string $binary, ?string $sourceMime = null): ?string
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $magick = new \Imagick();
            $magick->readImageBlob($binary);
        } catch (\Throwable $e) {
            Log::error('Imagick decode failed', ['error' => $e->getMessage()]);

            return null;
        }

        try {
            $magick->setIteratorIndex(0);
            $magick->setImageFormat('png');
            $w = $magick->getImageWidth();
            $h = $magick->getImageHeight();
            if ($w < 10 || $h < 10) {
                $magick->clear();
                $magick->destroy();
                throw new \InvalidArgumentException('Invalid image dimensions.');
            }
            $out = $magick->getImageBlob();
            $magick->clear();
            $magick->destroy();

            return is_string($out) && $out !== '' ? $out : null;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Imagick PNG encode failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
