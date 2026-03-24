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
     * Decode arbitrary raster bytes and return PNG bytes (Imagick robust path, else GD).
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

        if (extension_loaded('imagick')) {
            try {
                $magick = EditorRobustImageDecoder::decodeToImagick($binary, $sourceMime);

                try {
                    $w = $magick->getImageWidth();
                    $h = $magick->getImageHeight();
                    if ($w > self::MAX_EDGE_PX || $h > self::MAX_EDGE_PX) {
                        $scale = min(self::MAX_EDGE_PX / $w, self::MAX_EDGE_PX / $h);
                        $nw = max(2, (int) round($w * $scale));
                        $nh = max(2, (int) round($h * $scale));
                        $magick->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1, true);
                    }

                    $magick->setImageFormat('png');
                    $png = $magick->getImageBlob();

                    $guard = 0;
                    while (is_string($png) && strlen($png) > self::MAX_BYTES && $guard < 10) {
                        $guard++;
                        $nw = max(2, (int) ($magick->getImageWidth() * 0.82));
                        $nh = max(2, (int) ($magick->getImageHeight() * 0.82));
                        $magick->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1, true);
                        $magick->setImageFormat('png');
                        $png = $magick->getImageBlob();
                    }

                    if (! is_string($png) || $png === '') {
                        throw new \RuntimeException('Failed to encode PNG.');
                    }

                    if (strlen($png) > self::MAX_BYTES) {
                        throw new \InvalidArgumentException('Image is too large after processing. Try a smaller source image.');
                    }

                    return $png;
                } finally {
                    $magick->clear();
                    $magick->destroy();
                }
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('OpenAI normalize: Imagick pipeline failed, trying GD', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! function_exists('imagecreatefromstring')) {
            throw new \InvalidArgumentException(self::unsupportedFormatUserMessage());
        }

        $im = @imagecreatefromstring($binary);
        if ($im === false) {
            throw new \InvalidArgumentException(self::unsupportedFormatUserMessage());
        }

        try {
            $w = imagesx($im);
            $h = imagesy($im);
            if ($w < 2 || $h < 2) {
                throw new \InvalidArgumentException('Invalid image dimensions.');
            }

            if ($w > self::MAX_EDGE_PX || $h > self::MAX_EDGE_PX) {
                $scale = min(self::MAX_EDGE_PX / $w, self::MAX_EDGE_PX / $h);
                $nw = max(2, (int) round($w * $scale));
                $nh = max(2, (int) round($h * $scale));
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
                $nw = max(2, (int) (imagesx($im) * 0.82));
                $nh = max(2, (int) (imagesy($im) * 0.82));
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
}
