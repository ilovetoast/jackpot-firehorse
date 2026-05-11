<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;

/**
 * Pin the rotation direction contract: the public method is named
 * `rotateCurrentVersionClockwise` and the API parameter is `degrees_clockwise`,
 * so the resulting image MUST be rotated clockwise by that many degrees from
 * the upright source.
 *
 * Two long-standing bugs combined here on staging produced the user-reported
 * "rotate 90° actually flips 180°" symptom:
 *
 *   1. {@see \Imagick::autoOrientImage()} was missing on the runtime
 *      (Imagick 3.7+ removed it). The normalizer silently no-op'd, so the
 *      raw EXIF-uncorrected raster was rotated. For an orientation tag 6
 *      portrait that means the rotation was applied to landscape pixels →
 *      result was 90° off from "rotate from what the user sees".
 *   2. {@see \Imagick::rotateImage()} positive angle is CW (verified
 *      empirically against ImageMagick 6.9.12), not CCW as the previous
 *      docstring claimed. The previous code multiplied by -1 thinking it was
 *      converting CW to native CCW, but actually flipped the direction.
 *
 * 1 + 2 stack to a 180° flip on EXIF=6 portraits (the user's screenshot)
 * and a 90° wrong-direction on plain images. This test exercises just the
 * pixel transform inside `transformBytes()` (the part that's pure / unit-
 * testable without S3) to guarantee the result faces the correct direction.
 */
class AssetOriginalRasterRotationDirectionTest extends TestCase
{
    public function test_rotate_90_clockwise_actually_rotates_clockwise(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required.');
        }

        // Source: red top-left, green top-right, blue bottom-left, yellow bottom-right.
        $bytes = $this->makeQuadrantJpeg(120, 80);

        $rotated = $this->rotateLikeService($bytes, 90);

        // After 90° CW, the upright top-left should be raw bottom-left = blue.
        // (red would mean identity, green would mean 90 CCW, yellow would mean 180.)
        $this->assertQuadrant($rotated, 'blue', '90° CW must place raw bottom-left at the new top-left');

        $im = new \Imagick;
        $im->readImageBlob($rotated);
        $this->assertSame(80, (int) $im->getImageWidth(),
            '90° rotation must swap dimensions: source 120x80 -> 80x120');
        $this->assertSame(120, (int) $im->getImageHeight());
        $im->clear();
        $im->destroy();
    }

    public function test_rotate_270_clockwise_rotates_270_clockwise(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required.');
        }

        $bytes = $this->makeQuadrantJpeg(120, 80);
        $rotated = $this->rotateLikeService($bytes, 270);

        // 270° CW = 90° CCW. Upright top-left should be raw top-right = green.
        $this->assertQuadrant($rotated, 'green', '270° CW must place raw top-right at the new top-left (= 90° CCW)');
    }

    public function test_rotate_180_inverts_image(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required.');
        }

        $bytes = $this->makeQuadrantJpeg(120, 80);
        $rotated = $this->rotateLikeService($bytes, 180);

        // 180°: upright top-left should be raw bottom-right = yellow.
        $this->assertQuadrant($rotated, 'yellow', '180° must place raw bottom-right at the new top-left');
    }

    /**
     * Mirror exactly what AssetOriginalRasterRotationService::transformBytes()
     * does to the pixel buffer (skipping the S3/DB plumbing). If this drifts
     * from the service the test loses its meaning.
     */
    private function rotateLikeService(string $bytes, int $degreesClockwise): string
    {
        $im = new \Imagick;
        $im->readImageBlob($bytes);
        $im->setFirstIterator();

        \App\Services\ImageOrientationNormalizer::imagickAutoOrientAndResetOrientation($im);
        // Match the production code's rotation step verbatim.
        $im->rotateImage(new \ImagickPixel('rgba(0,0,0,0)'), (float) $degreesClockwise);
        if (defined('Imagick::ORIENTATION_TOPLEFT') && method_exists($im, 'setImageOrientation')) {
            $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        }
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(92);

        $out = $im->getImageBlob();
        $im->clear();
        $im->destroy();

        return $out;
    }

    private function makeQuadrantJpeg(int $w, int $h): string
    {
        $im = new \Imagick;
        $im->newImage($w, $h, new \ImagickPixel('white'));
        $halfW = (int) ($w / 2);
        $halfH = (int) ($h / 2);
        $d = new \ImagickDraw;
        $d->setFillColor(new \ImagickPixel('red'));    $d->rectangle(0, 0, $halfW - 1, $halfH - 1);
        $d->setFillColor(new \ImagickPixel('green'));  $d->rectangle($halfW, 0, $w - 1, $halfH - 1);
        $d->setFillColor(new \ImagickPixel('blue'));   $d->rectangle(0, $halfH, $halfW - 1, $h - 1);
        $d->setFillColor(new \ImagickPixel('yellow')); $d->rectangle($halfW, $halfH, $w - 1, $h - 1);
        $im->drawImage($d);
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(92);
        $bytes = $im->getImageBlob();
        $im->clear();
        $im->destroy();

        return $bytes;
    }

    private function assertQuadrant(string $bytes, string $expectedColour, string $message): void
    {
        $im = new \Imagick;
        $im->readImageBlob($bytes);
        // Probe a few pixels in to dodge JPEG/rotation antialiasing at the corner.
        $px = $im->getImagePixelColor(5, 5)->getColor();
        $im->clear();
        $im->destroy();

        $matches = match ($expectedColour) {
            'red' => $px['r'] > 100 && $px['g'] < 80 && $px['b'] < 80,
            'green' => $px['g'] > 80 && $px['r'] < 80 && $px['b'] < 80,
            'blue' => $px['b'] > 100 && $px['r'] < 80 && $px['g'] < 80,
            'yellow' => $px['r'] > 100 && $px['g'] > 100 && $px['b'] < 80,
            default => false,
        };
        $this->assertTrue($matches,
            "{$message}. Got rgb({$px['r']},{$px['g']},{$px['b']}) — expected to match {$expectedColour} quadrant.");
    }
}
