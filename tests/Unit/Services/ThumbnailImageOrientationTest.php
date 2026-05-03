<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ImageOrientationNormalizer;
use App\Services\ThumbnailGenerationService;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use ReflectionMethod;
use Tests\TestCase;

class ThumbnailImageOrientationTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->app->bound(ThumbnailGenerationService::class)) {
            $svc = $this->app->make(ThumbnailGenerationService::class);
            $m = new ReflectionMethod(ThumbnailGenerationService::class, 'resetGdRasterOrientationCache');
            $m->setAccessible(true);
            $m->invoke($svc);
        }
        parent::tearDown();
    }

    public function test_prepare_flat_raster_uprights_orientation_6_before_gd_resize(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required for EXIF-aware flat raster');
        }

        $path = tempnam(sys_get_temp_dir(), 'jp_exif6_').'.jpg';
        $this->writeJpegOrientation6($path);

        $before = @getimagesize($path);
        $this->assertIsArray($before);
        $this->assertSame(80, $before[0]);
        $this->assertSame(50, $before[1]);

        $flat = ImageOrientationNormalizer::prepareFlatRasterForGdThumbnail($path);
        $this->assertIsArray(@getimagesize($flat['path']));
        $after = getimagesize($flat['path']);
        $this->assertSame(50, $after[0]);
        $this->assertSame(80, $after[1]);
        $this->assertTrue($flat['profile']['auto_orient_applied'] ?? false);

        if ($flat['cleanup']) {
            @unlink($flat['path']);
        }
        @unlink($path);
    }

    public function test_flat_png_strips_orientation_for_browser_safe_output(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required');
        }

        $path = tempnam(sys_get_temp_dir(), 'jp_exif6b_').'.jpg';
        $this->writeJpegOrientation6($path);

        $flat = ImageOrientationNormalizer::prepareFlatRasterForGdThumbnail($path);
        $this->assertTrue($flat['cleanup']);

        $im = new Imagick($flat['path']);
        $o = defined('Imagick::ORIENTATION_TOPLEFT') ? (int) $im->getImageOrientation() : 0;
        $im->clear();
        $im->destroy();

        if (defined('Imagick::ORIENTATION_TOPLEFT')) {
            $this->assertSame(Imagick::ORIENTATION_TOPLEFT, $o);
        }

        @unlink($flat['path']);
        @unlink($path);
    }

    public function test_generate_image_thumbnail_thumb_and_medium_share_upright_geometry(): void
    {
        if (! extension_loaded('imagick') || ! extension_loaded('gd')) {
            $this->markTestSkipped('Imagick and GD required');
        }

        $path = tempnam(sys_get_temp_dir(), 'jp_exif6c_').'.jpg';
        $this->writeJpegOrientation6($path);

        $svc = $this->app->make(ThumbnailGenerationService::class);
        $gen = new ReflectionMethod(ThumbnailGenerationService::class, 'generateImageThumbnail');
        $gen->setAccessible(true);

        $thumbPath = $gen->invoke($svc, $path, [
            'width' => 40,
            'height' => 40,
            'quality' => 90,
            'fit' => 'contain',
        ]);
        $mediumPath = $gen->invoke($svc, $path, [
            'width' => 120,
            'height' => 120,
            'quality' => 90,
            'fit' => 'contain',
        ]);

        $t1 = getimagesize($thumbPath);
        $t2 = getimagesize($mediumPath);
        $this->assertIsArray($t1);
        $this->assertIsArray($t2);
        $this->assertSame($t1[0] / max(1, $t1[1]), $t2[0] / max(1, $t2[1]), 'thumb and medium should keep identical aspect from the same upright source');

        @unlink($thumbPath);
        @unlink($mediumPath);
        @unlink($path);

        $m = new ReflectionMethod(ThumbnailGenerationService::class, 'resetGdRasterOrientationCache');
        $m->setAccessible(true);
        $m->invoke($svc);
    }

    private function writeJpegOrientation6(string $path): void
    {
        $im = new Imagick;
        $im->newImage(80, 50, new ImagickPixel('white'));
        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('black'));
        $draw->rectangle(5, 5, 60, 40);
        $im->drawImage($draw);
        $im->setImageFormat('jpeg');
        if (defined('Imagick::ORIENTATION_RIGHTTOP')) {
            $im->setImageOrientation(Imagick::ORIENTATION_RIGHTTOP);
        }
        $im->writeImage($path);
        $im->clear();
        $im->destroy();
    }
}
