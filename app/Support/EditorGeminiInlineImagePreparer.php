<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Prepares raster data for Gemini generateContent inlineData: JPEG, bounded dimensions and payload size.
 */
final class EditorGeminiInlineImagePreparer
{
    private const MAX_EDGE_PX = 2048;

    /** Target max binary size before base64 (~3.2MB b64) so request stays under combined limits with prompt. */
    private const TARGET_MAX_BYTES = 2_400_000;

    /**
     * @return array{binary: string, mime_type: 'image/jpeg'}
     *
     * @throws \InvalidArgumentException
     */
    public static function prepare(string $binary, ?string $sourceMime = null): array
    {
        if ($binary === '') {
            throw new \InvalidArgumentException('Empty image data.');
        }

        if (extension_loaded('imagick')) {
            try {
                $magick = EditorRobustImageDecoder::decodeToImagick($binary, $sourceMime);

                try {
                    $jpeg = self::encodeGeminiJpegFromDecodedImagick($magick);

                    return ['binary' => $jpeg, 'mime_type' => 'image/jpeg'];
                } finally {
                    $magick->clear();
                    $magick->destroy();
                }
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Gemini preparer: Imagick pipeline failed, trying GD fallback', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $png = EditorOpenAiImageNormalizer::toPngForOpenAiEdits($binary, 0, $sourceMime);

        return ['binary' => self::pngBytesToJpegViaGd($png), 'mime_type' => 'image/jpeg'];
    }

    /**
     * Input: Imagick already flattened/stripped and normalized to JPEG @ 90 by {@see EditorRobustImageDecoder}.
     */
    private static function encodeGeminiJpegFromDecodedImagick(\Imagick $magick): string
    {
        $w = $magick->getImageWidth();
        $h = $magick->getImageHeight();
        $max = max($w, $h);
        if ($max > self::MAX_EDGE_PX) {
            $scale = self::MAX_EDGE_PX / $max;
            $nw = max(2, (int) round($w * $scale));
            $nh = max(2, (int) round($h * $scale));
            $magick->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1, true);
        }

        $magick->setImageFormat('jpeg');
        $quality = 88;
        for ($guard = 0; $guard < 18; $guard++) {
            $magick->setImageCompressionQuality($quality);
            $blob = $magick->getImageBlob();
            if (is_string($blob) && $blob !== '' && strlen($blob) <= self::TARGET_MAX_BYTES) {
                return $blob;
            }
            if ($quality > 38) {
                $quality -= 6;
            } else {
                $nw = max(2, (int) ($magick->getImageWidth() * 0.88));
                $nh = max(2, (int) ($magick->getImageHeight() * 0.88));
                $magick->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1, true);
                $quality = 82;
            }
        }

        $magick->setImageCompressionQuality(35);
        $blob = $magick->getImageBlob();

        if (! is_string($blob) || $blob === '') {
            throw new \InvalidArgumentException('Could not encode image for Gemini.');
        }

        return $blob;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function pngBytesToJpegViaGd(string $png): string
    {
        $im = @imagecreatefromstring($png);
        if ($im === false) {
            throw new \InvalidArgumentException(EditorOpenAiImageNormalizer::unsupportedFormatUserMessage());
        }

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 2 || $h < 2) {
            imagedestroy($im);
            throw new \InvalidArgumentException('Invalid image dimensions.');
        }

        $max = max($w, $h);
        if ($max > self::MAX_EDGE_PX) {
            $scale = self::MAX_EDGE_PX / $max;
            $nw = max(2, (int) round($w * $scale));
            $nh = max(2, (int) round($h * $scale));
            $scaled = imagescale($im, $nw, $nh);
            if ($scaled !== false) {
                imagedestroy($im);
                $im = $scaled;
                $w = $nw;
                $h = $nh;
            }
        }

        $canvas = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
        imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);

        $quality = 88;
        for ($guard = 0; $guard < 16; $guard++) {
            ob_start();
            imagejpeg($canvas, null, $quality);
            $blob = ob_get_clean();
            if (is_string($blob) && $blob !== '' && strlen($blob) <= self::TARGET_MAX_BYTES) {
                imagedestroy($canvas);

                return $blob;
            }
            if ($quality > 38) {
                $quality -= 6;
            } else {
                $nw = max(2, (int) ($w * 0.88));
                $nh = max(2, (int) ($h * 0.88));
                $sm2 = imagescale($canvas, $nw, $nh);
                if ($sm2 !== false) {
                    imagedestroy($canvas);
                    $canvas = $sm2;
                    $w = $nw;
                    $h = $nh;
                }
                $quality = 82;
            }
        }

        ob_start();
        imagejpeg($canvas, null, 35);
        $blob = ob_get_clean();
        imagedestroy($canvas);

        if (! is_string($blob) || $blob === '') {
            throw new \InvalidArgumentException('Could not encode image for Gemini.');
        }

        return $blob;
    }
}
