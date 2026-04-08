<?php

namespace Tests\Unit\Services;

use App\Services\PrintLayoutCropService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrintLayoutCropServiceTest extends TestCase
{
    private function makePrintWithCenteredContentPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plcrop_').'.png';
        $w = 640;
        $h = 820;

        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefill($im, 0, 0, $white);

        $t = 22;
        imagefilledrectangle($im, 0, 0, $w - 1, $t - 1, $black);
        imagefilledrectangle($im, 0, $h - $t, $w - 1, $h - 1, $black);
        imagefilledrectangle($im, 0, 0, $t - 1, $h - 1, $black);
        imagefilledrectangle($im, $w - $t, 0, $w - 1, $h - 1, $black);

        $dk = imagecolorallocate($im, 55, 55, 55);
        // Large enough that bbox ≥ min_content_bbox_dimension_ratio (default 0.6) vs full image
        imagefilledrectangle($im, 100, 150, $w - 100, $h - 150, $dk);

        $stripeW = 44;
        $segH = (int) ($h * 0.1);
        $y = (int) ($h * 0.15);
        foreach (
            [
                imagecolorallocate($im, 0, 255, 255),
                imagecolorallocate($im, 255, 0, 255),
                imagecolorallocate($im, 255, 255, 0),
                imagecolorallocate($im, 32, 32, 32),
            ] as $col
        ) {
            imagefilledrectangle($im, 2, $y, $stripeW, $y + $segH - 1, $col);
            $y += $segH + 4;
        }

        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    #[Test]
    public function crops_inward_from_registration_marks(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        Config::set('assets.print_layout.min_cropped_dimension_ratio', 0.38);

        $path = $this->makePrintWithCenteredContentPng();
        try {
            $svc = new PrintLayoutCropService;
            $r = $svc->cropPrintLayout($path);

            $this->assertTrue($r['applied'], 'Expected crop: '.($r['skip_reason'] ?? ''));
            $this->assertFileExists($r['path']);
            $this->assertNotSame($path, $r['path']);
            $this->assertGreaterThan(0.0, $r['confidence']);

            $info = @getimagesize($r['path']);
            $this->assertNotFalse($info);
            $this->assertLessThan(640, (int) $info[0]);
            $this->assertLessThan(820, (int) $info[1]);
        } finally {
            @unlink($path);
            if (isset($r['path']) && is_string($r['path']) && $r['path'] !== $path) {
                @unlink($r['path']);
            }
        }
    }

    #[Test]
    public function skips_when_content_bbox_smaller_than_configured_ratio(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        Config::set('assets.print_layout.min_content_bbox_dimension_ratio', 0.95);
        Config::set('assets.print_layout.min_cropped_dimension_ratio', 0.95);

        $path = $this->makePrintWithCenteredContentPng();
        try {
            $svc = new PrintLayoutCropService;
            $r = $svc->cropPrintLayout($path);

            $this->assertFalse($r['applied']);
            $this->assertSame($path, $r['path']);
            $this->assertSame('content_bbox_too_small', $r['skip_reason'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function original_file_is_never_modified(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        Config::set('assets.print_layout.min_cropped_dimension_ratio', 0.38);

        $path = $this->makePrintWithCenteredContentPng();
        $hash = md5_file($path);
        try {
            $svc = new PrintLayoutCropService;
            $r = $svc->cropPrintLayout($path);
            $this->assertTrue($r['applied']);
            $this->assertSame($hash, md5_file($path));
        } finally {
            @unlink($path);
            if (isset($r['path']) && is_string($r['path']) && $r['path'] !== $path) {
                @unlink($r['path']);
            }
        }
    }
}
