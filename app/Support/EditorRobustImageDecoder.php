<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Imagick first, GD temp JPEG second — tolerant decode for editor AI pipelines.
 */
final class EditorRobustImageDecoder
{
    /**
     * Decode bytes into a flattened, stripped Imagick handle normalized to JPEG @ 90 (consistent internal state).
     * Caller must {@see \Imagick::clear()} / {@see \Imagick::destroy()} when done.
     *
     * @throws \InvalidArgumentException
     */
    public static function decodeToImagick(string $contents, ?string $mime = null): \Imagick
    {
        if ($contents === '') {
            throw new \InvalidArgumentException('Empty image data.');
        }

        if (! extension_loaded('imagick')) {
            throw new \InvalidArgumentException('Imagick is required for this decode path.');
        }

        $imagick = null;

        try {
            $imagick = new \Imagick();
            $imagick->readImageBlob($contents);
        } catch (\Throwable $e) {
            if ($imagick instanceof \Imagick) {
                $imagick->clear();
                $imagick->destroy();
                $imagick = null;
            }

            Log::warning('Imagick decode failed, trying GD fallback', [
                'error' => $e->getMessage(),
            ]);

            $gd = @imagecreatefromstring($contents);
            if ($gd !== false) {
                $dir = storage_path('app/tmp');
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $tempPath = $dir.'/gd-fallback-'.uniqid('', true).'.jpg';
                imagejpeg($gd, $tempPath, 90);
                imagedestroy($gd);

                try {
                    $imagick = new \Imagick();
                    $imagick->readImage($tempPath);
                } finally {
                    if (is_file($tempPath)) {
                        @unlink($tempPath);
                    }
                }
            } else {
                Log::error('Both Imagick and GD failed to decode image', [
                    'mime' => $mime,
                    'bytes' => strlen($contents),
                    'preview' => substr($contents, 0, 50),
                ]);

                throw new \InvalidArgumentException(
                    'We could not process this image. Try a JPG or PNG original file.'
                );
            }
        }

        if ($imagick->getNumberImages() > 1) {
            $imagick = $imagick->coalesceImages();
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        }
        $imagick->setIteratorIndex(0);
        $imagick->setImageBackgroundColor(new \ImagickPixel('#ffffff'));
        if ($imagick->getImageAlphaChannel()) {
            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        }
        $imagick->stripImage();

        $w = $imagick->getImageWidth();
        $h = $imagick->getImageHeight();
        if ($w < 2 || $h < 2) {
            $imagick->clear();
            $imagick->destroy();

            throw new \InvalidArgumentException('Invalid image dimensions.');
        }

        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(90);

        Log::info('Final decoded image', [
            'width' => $imagick->getImageWidth(),
            'height' => $imagick->getImageHeight(),
            'format' => $imagick->getImageFormat(),
        ]);

        return $imagick;
    }
}
