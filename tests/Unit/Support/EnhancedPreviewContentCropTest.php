<?php

namespace Tests\Unit\Support;

use App\Support\EnhancedPreviewContentCrop;
use Tests\TestCase;

class EnhancedPreviewContentCropTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }
    }

    public function test_trims_uniform_side_margins_around_noisy_center(): void
    {
        $w = 120;
        $h = 100;
        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $white);

        // Noisy interior (columns 35–84): high per-column variance vs white margins
        for ($y = 0; $y < $h; $y++) {
            for ($x = 35; $x < 85; $x++) {
                $v = (int) (($x + $y * 7) % 200);
                $c = imagecolorallocate($im, $v, (int) (($v + 40) % 220), (int) (($v + 80) % 220));
                imagesetpixel($im, $x, $y, $c);
            }
        }

        $rect = EnhancedPreviewContentCrop::computeCropRect($im);
        imagedestroy($im);

        $this->assertNotNull($rect);
        $this->assertGreaterThanOrEqual(30, $rect['x']);
        $this->assertLessThanOrEqual(45, $rect['x']);
        $this->assertGreaterThanOrEqual(50, $rect['width']);
        $this->assertLessThanOrEqual(95, $rect['width']);
    }

    public function test_returns_null_for_uniform_image(): void
    {
        $im = imagecreatetruecolor(64, 64);
        $gray = imagecolorallocate($im, 200, 200, 200);
        imagefill($im, 0, 0, $gray);

        $rect = EnhancedPreviewContentCrop::computeCropRect($im);
        imagedestroy($im);

        $this->assertNull($rect);
    }
}
