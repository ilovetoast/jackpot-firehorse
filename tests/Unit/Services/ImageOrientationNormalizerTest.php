<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ImageOrientationNormalizer;
use Tests\TestCase;

class ImageOrientationNormalizerTest extends TestCase
{
    public function test_orientation_read_returns_null_for_missing_file(): void
    {
        $this->assertNull(ImageOrientationNormalizer::readExifOrientationTag('/nonexistent/path/no-file.jpg'));
    }

    /**
     * Regression: on Imagick builds without {@see \Imagick::autoOrientImage()}
     * (Imagick 3.7+ removed it as deprecated), the normalizer used to silently
     * no-op — every downstream caller (thumbnail generation, rotate-asset) then
     * acted on raw EXIF-uncorrected pixels. For a Canon-style portrait JPEG
     * with orientation tag 6, that produced a visible 180° flip when users
     * clicked "rotate 90°". The manual rotate/flop fallback below has to fire
     * regardless of whether the native call exists.
     *
     * @dataProvider orientationFallbackCases
     */
    public function test_manual_fallback_uprights_pixels_when_native_auto_orient_unavailable(
        int $orientation,
        int $sourceWidth,
        int $sourceHeight,
        int $expectedWidth,
        int $expectedHeight,
        string $expectedTopLeftHex,
    ): void {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required for orientation behaviour test.');
        }

        $im = $this->makeOrientedTestImage($sourceWidth, $sourceHeight, $orientation);
        $beforeWidth = (int) $im->getImageWidth();
        $beforeHeight = (int) $im->getImageHeight();
        $this->assertSame($sourceWidth, $beforeWidth, 'Test fixture must start at raw dimensions');
        $this->assertSame($sourceHeight, $beforeHeight);

        $diag = ImageOrientationNormalizer::imagickAutoOrientAndResetOrientation($im);

        // For orientation 1, the image is already upright — no transform
        // needed. `applied` is allowed to be false there. For tags 2–8,
        // either native autoOrient OR the manual fallback MUST have run.
        if ($orientation > 1) {
            $this->assertTrue($diag['applied'],
                "EXIF tag {$orientation}: native autoOrient OR manual fallback must report applied");
        }
        $this->assertSame($expectedWidth, (int) $im->getImageWidth(),
            "EXIF tag {$orientation}: width must match upright orientation");
        $this->assertSame($expectedHeight, (int) $im->getImageHeight(),
            "EXIF tag {$orientation}: height must match upright orientation");
        $this->assertTrue($diag['reset_to_topleft'], 'Final EXIF tag must be reset to TOPLEFT to prevent double-rotate');

        // Verify the actual pixel transform: probe a pixel a few rows in
        // from the upright top-left and confirm it matches the colour
        // painted at the *upright* corner of the source. Probing strictly
        // at (0,0) would catch ImageMagick's edge antialiasing on rotation
        // (rgba(0,0,0,0) bg blending into the corner), which isn't the
        // contract under test — we care that the right *quadrant* moved
        // to the upright top-left, not that the corner pixel is pure.
        $px = $im->getImagePixelColor(5, 5);
        $rgb = $px->getColor();
        $this->assertTrue(
            $this->dominantQuadrantMatches($rgb, $expectedTopLeftHex),
            "EXIF tag {$orientation}: pixel near upright top-left must come from the {$expectedTopLeftHex} quadrant ".
            'of the source raster. Got rgb('.$rgb['r'].','.$rgb['g'].','.$rgb['b'].'). '.
            'A "dimensions swapped but pixels rotated wrong way" regression would land a different quadrant here.',
        );

        $im->clear();
        $im->destroy();
    }

    /**
     * @return iterable<string, array{int, int, int, int, int, string}>
     */
    public static function orientationFallbackCases(): iterable
    {
        // Source raster painted as 4 quadrants:
        //   raw top-left = red, raw top-right = green,
        //   raw bottom-left = blue, raw bottom-right = yellow.
        //
        // For each EXIF orientation tag, the "upright top-left" corner is
        // a different raw quadrant — that's what the test asserts.
        yield 'tag 1 (top-left) is identity' => [1, 100, 60, 100, 60, '#ff0000'];
        yield 'tag 2 (top-right) flops horizontally' => [2, 100, 60, 100, 60, '#00ff00'];
        yield 'tag 3 (bottom-right) rotates 180' => [3, 100, 60, 100, 60, '#ffff00'];
        yield 'tag 4 (bottom-left) flips vertically' => [4, 100, 60, 100, 60, '#0000ff'];
        yield 'tag 6 (right-top, Canon portrait) rotates 90 CW' => [6, 100, 60, 60, 100, '#0000ff'];
        yield 'tag 8 (left-bottom, iPhone portrait) rotates 90 CCW' => [8, 100, 60, 60, 100, '#00ff00'];
    }

    public function test_normalizer_is_idempotent_for_orientation_1(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required.');
        }
        $im = $this->makeOrientedTestImage(80, 50, 1);
        $diag = ImageOrientationNormalizer::imagickAutoOrientAndResetOrientation($im);

        $this->assertSame(80, (int) $im->getImageWidth());
        $this->assertSame(50, (int) $im->getImageHeight());
        $this->assertFalse($diag['manual_fallback_applied'],
            'Manual fallback must not run for orientation=1 (already upright)');

        $im->clear();
        $im->destroy();
    }

    /**
     * Compare a probed pixel to one of the four canonical quadrant colours
     * (red, green, blue, yellow). Uses dominant-channel matching rather
     * than exact equality to absorb ImageMagick rotation antialiasing.
     *
     * @param  array{r: int, g: int, b: int, a?: int}  $rgb
     */
    private function dominantQuadrantMatches(array $rgb, string $expectedHex): bool
    {
        $r = (int) $rgb['r'];
        $g = (int) $rgb['g'];
        $b = (int) $rgb['b'];
        switch ($expectedHex) {
            case '#ff0000': return $r > 100 && $g < 80 && $b < 80;
            case '#00ff00': return $g > 80 && $r < 80 && $b < 80;
            case '#0000ff': return $b > 100 && $r < 80 && $g < 80;
            case '#ffff00': return $r > 100 && $g > 100 && $b < 80;
            default: return false;
        }
    }

    /**
     * Build an Imagick image with a known 4-quadrant raster and an explicit
     * EXIF orientation tag. The raster is always the same; only the tag
     * differs — that mirrors what a camera does when it rotates the EXIF
     * tag instead of the pixel data.
     */
    private function makeOrientedTestImage(int $rawWidth, int $rawHeight, int $orientationTag): \Imagick
    {
        $im = new \Imagick;
        $im->newImage($rawWidth, $rawHeight, new \ImagickPixel('white'));
        $im->setImageFormat('png');

        $halfW = (int) ($rawWidth / 2);
        $halfH = (int) ($rawHeight / 2);
        $draw = new \ImagickDraw;

        $paintRect = function (\ImagickDraw $d, string $colour, int $x1, int $y1, int $x2, int $y2): void {
            $d->setFillColor(new \ImagickPixel($colour));
            $d->rectangle($x1, $y1, $x2, $y2);
        };

        $paintRect($draw, 'red',    0,      0,      $halfW - 1, $halfH - 1);
        $paintRect($draw, 'green',  $halfW, 0,      $rawWidth - 1, $halfH - 1);
        $paintRect($draw, 'blue',   0,      $halfH, $halfW - 1, $rawHeight - 1);
        $paintRect($draw, 'yellow', $halfW, $halfH, $rawWidth - 1, $rawHeight - 1);

        $im->drawImage($draw);

        if (defined('Imagick::ORIENTATION_TOPLEFT') && method_exists($im, 'setImageOrientation')) {
            $im->setImageOrientation($orientationTag);
        }

        return $im;
    }
}
