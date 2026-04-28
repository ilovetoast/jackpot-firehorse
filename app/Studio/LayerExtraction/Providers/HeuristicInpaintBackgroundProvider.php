<?php

namespace App\Studio\LayerExtraction\Providers;

use App\Models\Asset;
use App\Models\StudioLayerExtractionSession;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionInpaintBackgroundInterface;
use RuntimeException;

/**
 * Local, API-free “fill”: replace masked-foreground pixels with a neutral light gray (dev/staging; swap for a remote inpaint provider in production if needed).
 * Never mutates the source asset; returns new image bytes only.
 */
final class HeuristicInpaintBackgroundProvider implements StudioLayerExtractionInpaintBackgroundInterface
{
    public function supportsBackgroundFill(): bool
    {
        return true;
    }

    public function buildFilledBackground(
        Asset $sourceAsset,
        string $sourceBinary,
        string $combinedForegroundMaskPng,
        StudioLayerExtractionSession $session,
    ): string {
        $im = @imagecreatefromstring($sourceBinary);
        if ($im === false) {
            throw new RuntimeException('Could not decode the source image for background fill.');
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $m = @imagecreatefromstring($combinedForegroundMaskPng);
        if ($m === false) {
            imagedestroy($im);
            throw new RuntimeException('Could not decode the combined mask for background fill.');
        }
        if (! imageistruecolor($m)) {
            imagepalettetotruecolor($m);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if (imagesx($m) !== $w || imagesy($m) !== $h) {
            imagedestroy($im);
            imagedestroy($m);
            throw new RuntimeException('Mask size does not match the source image.');
        }
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $mc = imagecolorat($m, $x, $y);
                $mr = ($mc >> 16) & 0xFF;
                $mg = ($mc >> 8) & 0xFF;
                $mbit = $mc & 0xFF;
                $mma = ($mc >> 24) & 127;
                $wgt = (($mr + $mg + $mbit) / 3.0) / 255.0 * (127 - $mma) / 127.0;
                if ($wgt > 0.35) {
                    $c = imagecolorallocate($im, 240, 240, 240);
                    imagesetpixel($im, $x, $y, $c);
                }
            }
        }
        imagedestroy($m);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        if ($png === '') {
            throw new RuntimeException('Failed to encode filled background image.');
        }

        return $png;
    }
}
