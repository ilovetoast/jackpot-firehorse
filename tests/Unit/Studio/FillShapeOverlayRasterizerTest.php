<?php

namespace Tests\Unit\Studio;

use App\Studio\Rendering\Dto\RenderLayer;
use App\Studio\Rendering\FillShapeOverlayRasterizer;
use Tests\TestCase;

class FillShapeOverlayRasterizerTest extends TestCase
{
    public function test_rectangle_solid_rasterizes_to_png_with_imagick(): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick required');
        }
        $dir = sys_get_temp_dir().'/jp_fillshape_'.uniqid('', true);
        mkdir($dir, 0777, true);
        try {
            $layer = new RenderLayer(
                id: 'shape1',
                type: 'image',
                zIndex: 1,
                startSeconds: 0.0,
                endSeconds: 5.0,
                visible: true,
                x: 0,
                y: 0,
                width: 40,
                height: 24,
                opacity: 1.0,
                rotationDegrees: 0.0,
                fit: 'fill',
                isPrimaryVideo: false,
                mediaPath: null,
                trimInMs: 0,
                trimOutMs: 0,
                muted: false,
                fadeInMs: 0,
                fadeOutMs: 0,
                extra: [
                    'studio_preraster' => 'fill_shape',
                    'fill_shape_spec' => [
                        'kind' => 'shape_rect',
                        'fill' => '#ff0000',
                        'border_radius' => 4,
                    ],
                    'asset_id' => '',
                ],
            );
            $r = new FillShapeOverlayRasterizer;
            $path = $r->rasterizeToPath($layer, $dir);
            $this->assertFileExists($path);
            $this->assertGreaterThan(32, filesize($path));
        } finally {
            if (is_dir($dir)) {
                foreach (glob($dir.'/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
    }

    public function test_radial_text_boost_fill_rasterizes_to_png_with_imagick(): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick required');
        }
        $dir = sys_get_temp_dir().'/jp_fillshape_radial_'.uniqid('', true);
        mkdir($dir, 0777, true);
        try {
            $layer = new RenderLayer(
                id: 'fill_radial_1',
                type: 'image',
                zIndex: 1,
                startSeconds: 0.0,
                endSeconds: 5.0,
                visible: true,
                x: 0,
                y: 0,
                width: 48,
                height: 48,
                opacity: 1.0,
                rotationDegrees: 0.0,
                fit: 'fill',
                isPrimaryVideo: false,
                mediaPath: null,
                trimInMs: 0,
                trimOutMs: 0,
                muted: false,
                fadeInMs: 0,
                fadeOutMs: 0,
                extra: [
                    'studio_preraster' => 'fill_shape',
                    'fill_shape_spec' => [
                        'kind' => 'fill_radial_text_boost',
                        'color_center_hex' => 'transparent',
                        'color_edge_hex' => '#ff0000',
                        'opacity' => 0.5,
                        'gradient_scale' => 1.0,
                        'border_radius' => 0,
                    ],
                    'asset_id' => '',
                ],
            );
            $r = new FillShapeOverlayRasterizer;
            $path = $r->rasterizeToPath($layer, $dir);
            $this->assertFileExists($path);
            $this->assertGreaterThan(32, filesize($path));
        } finally {
            if (is_dir($dir)) {
                foreach (glob($dir.'/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
    }
}
