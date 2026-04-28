<?php

namespace Tests\Unit\Studio\LayerExtraction;

use App\Studio\LayerExtraction\Sam\FalSamSegmentationClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FalSamSegmentationClientTest extends TestCase
{
    public function test_sync_response_with_image_url_downloads_mask(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $maskPng = $this->tinyMaskPng();
        config([
            'services.fal.sam2_endpoint' => 'https://fal.test/fal-ai/sam2/image',
        ]);
        Http::fake([
            'https://fal.test/*' => Http::response(['image' => ['url' => 'https://cdn.test/m.png']], 200),
            'https://cdn.test/*' => Http::response($maskPng, 200, ['Content-Type' => 'image/png']),
        ]);
        $c = new FalSamSegmentationClient('k');
        $r = $c->autoSegment($this->tinyRgbPng(), ['min_component_area_px' => 1, 'fal_log_mode' => 'auto']);
        $this->assertNotEmpty($r->segments);
    }

    public function test_request_id_triggers_queue_poll_and_result_fetch(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $maskPng = $this->tinyMaskPng();
        config([
            'services.fal.sam2_endpoint' => 'https://fal.test/fal-ai/sam2/image',
            'services.fal.queue_base' => 'https://queue.fal.test',
            'studio_layer_extraction.sam.fal_queue_max_polls' => 10,
            'studio_layer_extraction.sam.fal_queue_poll_interval_ms' => 1,
        ]);
        $n = 0;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$n, $maskPng) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, 'fal.test/fal-ai')) {
                return Http::response([
                    'request_id' => 'rid-1',
                    'status_url' => 'https://queue.fal.test/fal-ai/sam2/image/requests/rid-1/status',
                    'response_url' => 'https://queue.fal.test/fal-ai/sam2/image/requests/rid-1',
                ], 200);
            }
            if (str_contains($url, '/status')) {
                $n++;
                if ($n < 2) {
                    return Http::response(['status' => 'IN_QUEUE', 'request_id' => 'rid-1'], 200);
                }

                return Http::response([
                    'status' => 'COMPLETED',
                    'request_id' => 'rid-1',
                    'response_url' => 'https://queue.fal.test/fal-ai/sam2/image/requests/rid-1',
                ], 200);
            }
            if (str_contains($url, 'queue.fal.test') && ! str_contains($url, '/status')) {
                return Http::response(['image' => ['url' => 'https://cdn.test/m.png']], 200);
            }
            if (str_contains($url, 'cdn.test')) {
                return Http::response($maskPng, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response(['error' => 'unmocked: '.$url], 500);
        });
        $c = new FalSamSegmentationClient('k');
        $r = $c->autoSegment($this->tinyRgbPng(), ['min_component_area_px' => 1, 'fal_log_mode' => 'auto']);
        $this->assertNotEmpty($r->segments);
    }

    public function test_http_500_on_post_throws_user_facing_runtime_exception(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config(['services.fal.sam2_endpoint' => 'https://fal.test/fal-ai/sam2/image']);
        Http::fake(['https://fal.test/*' => Http::response(['detail' => 'nope'], 500)]);
        $c = new FalSamSegmentationClient('k');
        $this->expectException(RuntimeException::class);
        $c->autoSegment($this->tinyRgbPng(), ['fal_log_mode' => 'auto']);
    }

    private function tinyRgbPng(): string
    {
        $im = imagecreatetruecolor(8, 8);
        if ($im === false) {
            throw new RuntimeException('gd');
        }
        $c = imagecolorallocate($im, 10, 20, 30);
        imagefilledrectangle($im, 0, 0, 7, 7, $c);
        ob_start();
        imagepng($im);
        $p = (string) ob_get_clean();
        imagedestroy($im);

        return $p;
    }

    private function tinyMaskPng(): string
    {
        $im = imagecreatetruecolor(8, 8);
        if ($im === false) {
            throw new RuntimeException('gd');
        }
        $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
        $fg = imagecolorallocate($im, 255, 255, 255);
        imagealphablending($im, true);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, 7, 7, $bg);
        imagefilledrectangle($im, 1, 1, 6, 6, $fg);
        ob_start();
        imagepng($im);
        $p = (string) ob_get_clean();
        imagedestroy($im);

        return $p;
    }
}
