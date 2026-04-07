<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Models\Category;
use App\Services\TemplateRenderer;
use Tests\TestCase;

class TemplateRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required for TemplateRenderer');
        }
    }

    public function test_select_template_pdf_is_catalog(): void
    {
        $asset = new Asset(['mime_type' => 'application/pdf']);
        $asset->setRelation('category', null);

        $r = app(TemplateRenderer::class);

        $this->assertSame('catalog_v1', $r->selectTemplateForAsset($asset));
    }

    public function test_select_template_product_slug_is_surface(): void
    {
        $asset = new Asset(['mime_type' => 'image/jpeg']);
        $cat = new Category(['slug' => 'product-photos']);
        $asset->setRelation('category', $cat);

        $r = app(TemplateRenderer::class);

        $this->assertSame('surface_v1', $r->selectTemplateForAsset($asset));
    }

    public function test_render_composited_thumbnail_produces_valid_image_file(): void
    {
        $src = imagecreatetruecolor(80, 60);
        $white = imagecolorallocate($src, 255, 255, 255);
        imagefill($src, 0, 0, $white);
        $tmpSrc = tempnam(sys_get_temp_dir(), 'tr_src_').'.png';
        imagepng($src, $tmpSrc);
        imagedestroy($src);

        $r = app(TemplateRenderer::class);
        $out = $r->renderCompositedThumbnail($tmpSrc, 'neutral_v1', 'thumb', [
            'width' => 160,
            'height' => 160,
            'quality' => 85,
        ]);

        @unlink($tmpSrc);

        $this->assertNotNull($out);
        $this->assertFileExists($out);
        $info = @getimagesize($out);
        $this->assertNotFalse($info);
        $this->assertSame(160, $info[0]);
        $this->assertSame(160, $info[1]);
        @unlink($out);
    }
}
