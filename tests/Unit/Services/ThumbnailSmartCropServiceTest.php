<?php

namespace Tests\Unit\Services;

use App\Services\ThumbnailSmartCropService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailSmartCropServiceTest extends TestCase
{
    private function makeWhitespaceHeavyPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tsc_whitespace_').'.png';
        $im = imagecreatetruecolor(800, 800);
        $white = imagecolorallocate($im, 255, 255, 255);
        $dark = imagecolorallocate($im, 30, 30, 30);
        imagefill($im, 0, 0, $white);
        imagefilledrectangle($im, 100, 200, 700, 600, $dark);
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function makeTightPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tsc_tight_').'.png';
        $im = imagecreatetruecolor(800, 800);
        $dark = imagecolorallocate($im, 40, 40, 40);
        imagefill($im, 0, 0, $dark);
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function makeSmallPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tsc_small_').'.png';
        $im = imagecreatetruecolor(200, 200);
        $w = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $w);
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    #[Test]
    public function smart_crop_trims_whitespace_on_large_images(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        $path = $this->makeWhitespaceHeavyPng();
        try {
            $svc = new ThumbnailSmartCropService;
            $result = $svc->smartCrop($path);

            $this->assertTrue($result['applied'], 'Expected crop applied: '.($result['skip_reason'] ?? ''));
            $this->assertGreaterThan(0.0, $result['confidence']);
            $this->assertIsArray($result['signals']);
            $this->assertTrue($result['signals']['padding_applied']);
            $this->assertNotNull($result['signals']['trim_ratio']);
            $this->assertFileExists($result['path']);
            $this->assertNotSame($path, $result['path']);

            $info = @getimagesize($result['path']);
            $this->assertNotFalse($info);
            $this->assertLessThan(800, (int) $info[0]);
            $this->assertLessThan(800, (int) $info[1]);
        } finally {
            @unlink($path);
            if (isset($result['path']) && is_string($result['path']) && $result['path'] !== $path) {
                @unlink($result['path']);
            }
        }
    }

    #[Test]
    public function smart_crop_skips_already_full_bleed_images(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        $path = $this->makeTightPng();
        try {
            $svc = new ThumbnailSmartCropService;
            $result = $svc->smartCrop($path);

            $this->assertFalse($result['applied']);
            $this->assertArrayHasKey('skip_reason', $result);
            $this->assertIsArray($result['signals']);
            $this->assertFalse($result['signals']['padding_applied']);
            $this->assertSame($path, $result['path']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function smart_crop_skips_below_minimum_dimension(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        $path = $this->makeSmallPng();
        try {
            $svc = new ThumbnailSmartCropService;
            $result = $svc->smartCrop($path);

            $this->assertFalse($result['applied']);
            $this->assertSame('small_dimensions', $result['skip_reason'] ?? null);
            $this->assertIsArray($result['signals']);
        } finally {
            @unlink($path);
        }
    }
}
