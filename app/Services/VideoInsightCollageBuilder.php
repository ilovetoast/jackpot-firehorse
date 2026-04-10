<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Builds one JPEG from many frame files so a single vision API call can see temporal context.
 */
class VideoInsightCollageBuilder
{
    /**
     * @param  list<string>  $framePaths  JPEG paths
     * @return string data:image/jpeg;base64,... URL suitable for vision APIs
     */
    public function buildDataUrl(array $framePaths): string
    {
        if ($framePaths === []) {
            throw new \InvalidArgumentException('No frames to composite.');
        }

        if (! extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD extension required to build video frame collage.');
        }

        $cols = 5;
        $cell = 200;
        $n = count($framePaths);
        $rows = (int) ceil($n / $cols);
        $width = $cols * $cell;
        $height = $rows * $cell;

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new \RuntimeException('Failed to allocate collage canvas.');
        }

        $bg = imagecolorallocate($canvas, 24, 24, 24);
        imagefill($canvas, 0, 0, $bg);

        foreach ($framePaths as $idx => $path) {
            $im = @imagecreatefromjpeg($path);
            if ($im === false) {
                Log::warning('[VideoInsightCollageBuilder] Skipping unreadable frame', ['path' => $path]);

                continue;
            }

            $sx = imagesx($im);
            $sy = imagesy($im);
            $col = $idx % $cols;
            $row = intdiv($idx, $cols);
            $dx = $col * $cell;
            $dy = $row * $cell;

            imagecopyresampled(
                $canvas,
                $im,
                $dx,
                $dy,
                0,
                0,
                $cell,
                $cell,
                $sx,
                $sy
            );
            imagedestroy($im);
        }

        ob_start();
        imagejpeg($canvas, null, 82);
        imagedestroy($canvas);
        $binary = ob_get_clean();
        if (! is_string($binary) || $binary === '') {
            throw new \RuntimeException('Failed to encode collage JPEG.');
        }

        return 'data:image/jpeg;base64,'.base64_encode($binary);
    }
}
