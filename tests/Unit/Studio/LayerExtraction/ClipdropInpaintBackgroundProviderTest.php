<?php

namespace Tests\Unit\Studio\LayerExtraction;

use App\Models\Asset;
use App\Models\StudioLayerExtractionSession;
use App\Studio\LayerExtraction\Providers\ClipdropInpaintBackgroundProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClipdropInpaintBackgroundProviderTest extends TestCase
{
    public function test_happy_path_returns_image_bytes_from_clipdrop(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $src = $this->makeJpeg(8, 8);
        $mask = $this->makeMask(8, 8);
        $ep = 'https://clipdrop-api.co/cleanup/v1';
        Http::fake([$ep => Http::response($src, 200, ['Content-Type' => 'image/png'])]);
        $p = new ClipdropInpaintBackgroundProvider('test_key');
        config([
            'services.clipdrop.cleanup_endpoint' => $ep,
            'studio_layer_extraction.inpaint.max_source_mb' => 25,
        ]);
        $a = new Asset;
        $a->id = 'a1';
        $s = new StudioLayerExtractionSession;
        $s->id = (string) \Illuminate\Support\Str::uuid();
        $out = $p->buildFilledBackground($a, $src, $mask, $s);
        $this->assertNotSame('', $out);
    }

    public function test_inpaint_supports_disables_null_provider(): void
    {
        $n = new \App\Studio\LayerExtraction\Providers\NullInpaintBackgroundProvider;
        $this->assertFalse($n->supportsBackgroundFill());
    }

    public function test_floodfill_does_not_support_fill(): void
    {
        $f = new \App\Studio\LayerExtraction\Providers\FloodfillStudioLayerExtractionProvider;
        $this->assertFalse($f->supportsBackgroundFill());
    }

    private function makeJpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new \RuntimeException('gd');
        }
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 20, 40, 60));
        ob_start();
        imagejpeg($im);
        $b = (string) ob_get_clean();
        imagedestroy($im);

        return $b;
    }

    private function makeMask(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new \RuntimeException('gd');
        }
        $z = imagecolorallocate($im, 0, 0, 0);
        $o = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $z);
        imagefilledrectangle($im, 1, 1, 2, 2, $o);
        ob_start();
        imagepng($im);
        $b = (string) ob_get_clean();
        imagedestroy($im);

        return $b;
    }
}
