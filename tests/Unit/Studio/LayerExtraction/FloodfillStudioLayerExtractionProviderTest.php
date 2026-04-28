<?php

namespace Tests\Unit\Studio\LayerExtraction;

use App\Models\Asset;
use App\Studio\LayerExtraction\Providers\FloodfillStudioLayerExtractionProvider;
use Tests\TestCase;

class FloodfillStudioLayerExtractionProviderTest extends TestCase
{
    private function makePngWithTwoBlackQuaresOnWhite(): string
    {
        $w = 80;
        $h = 50;
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new \RuntimeException('GD');
        }
        $wpx = imagecolorallocate($im, 255, 255, 255);
        $bpx = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $wpx);
        // Two disconnected non-touching black squares
        imagefilledrectangle($im, 6, 10, 20, 24, $bpx);
        imagefilledrectangle($im, 55, 10, 70, 24, $bpx);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        if ($png === '') {
            throw new \RuntimeException('encode');
        }

        return $png;
    }

    public function test_single_connected_region_returns_one_detected_element(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
            'studio_layer_extraction.floodfill.color_tolerance' => 50,
            'studio_layer_extraction.local_floodfill.enable_multi_candidate' => true,
            'studio_layer_extraction.local_floodfill.max_candidates' => 6,
            'studio_layer_extraction.local_floodfill.min_area_ratio' => 0.01,
            'studio_layer_extraction.local_floodfill.max_area_ratio' => 0.9,
        ]);

        $p = new FloodfillStudioLayerExtractionProvider;
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $r = $p->extractMasks($a, ['image_binary' => $png]);
        $this->assertCount(1, $r->candidates);
        $this->assertStringContainsString('Detected element', (string) $r->candidates[0]->label);
    }

    public function test_separated_objects_can_produce_multiple_candidates(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
            'studio_layer_extraction.floodfill.color_tolerance' => 50,
            'studio_layer_extraction.local_floodfill.enable_multi_candidate' => true,
        ]);

        $p = new FloodfillStudioLayerExtractionProvider;
        $png = $this->makePngWithTwoBlackQuaresOnWhite();
        $a = $this->makeMockAsset();
        $r = $p->extractMasks($a, ['image_binary' => $png]);
        $this->assertGreaterThanOrEqual(2, count($r->candidates));
    }

    public function test_too_many_pixels_rejected(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $cap = 200_000;
        config(['studio_layer_extraction.local_floodfill.max_analysis_pixels' => $cap]);

        $p = new FloodfillStudioLayerExtractionProvider;
        $png = $this->makeWhitePngExceedingPixelCap($cap);
        $a = $this->makeMockAsset();
        $this->expectException(\InvalidArgumentException::class);
        $p->extractMasks($a, ['image_binary' => $png]);
    }

    public function test_supports_multiple_masks_tracks_config(): void
    {
        config(['studio_layer_extraction.local_floodfill.enable_multi_candidate' => false]);
        $p = new FloodfillStudioLayerExtractionProvider;
        $this->assertFalse($p->supportsMultipleMasks());
        config(['studio_layer_extraction.local_floodfill.enable_multi_candidate' => true]);
        $p2 = new FloodfillStudioLayerExtractionProvider;
        $this->assertTrue($p2->supportsMultipleMasks());
    }

    public function test_extract_from_point_finds_center_region(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
            'studio_layer_extraction.floodfill.color_tolerance' => 50,
            'studio_layer_extraction.local_floodfill.enable_multi_candidate' => true,
        ]);

        $p = new FloodfillStudioLayerExtractionProvider;
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $c = $p->extractCandidateFromPoint(
            $a,
            0.38,
            0.38,
            [
                'image_binary' => $png,
                'label' => 'Picked element',
                'candidate_id' => 'pick_test',
            ]
        );
        $this->assertNotNull($c);
        $this->assertSame('Picked element', $c->label);
        $this->assertIsArray($c->metadata);
        $this->assertSame('point', $c->metadata['prompt_type'] ?? null);
        $this->assertSame('local_seed_floodfill', $c->metadata['method'] ?? null);
    }

    public function test_refine_with_negative_shrinks_foreground(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
            'studio_layer_extraction.floodfill.color_tolerance' => 50,
            'studio_layer_extraction.local_floodfill.refine_enabled' => true,
            'studio_layer_extraction.local_floodfill.negative_point_radius_ratio' => 0.15,
            'studio_layer_extraction.local_floodfill.min_area_ratio' => 0.0001,
        ]);

        $p = new FloodfillStudioLayerExtractionProvider;
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $pick = $p->extractCandidateFromPoint(
            $a,
            0.38,
            0.38,
            [
                'image_binary' => $png,
                'label' => 'Picked',
                'candidate_id' => 'pick_unit',
            ]
        );
        $this->assertNotNull($pick);
        $w0 = (int) $pick->bbox['width'] * (int) $pick->bbox['height'];

        $pos = [['x' => 0.38, 'y' => 0.38]];
        $neg = [['x' => 0.38, 'y' => 0.38]];
        $ref = $p->refineCandidateWithPoints($a, $pick, $pos, $neg, ['image_binary' => $png]);
        $this->assertNotNull($ref);
        $this->assertSame('pick_unit', $ref->id);
        $w1 = (int) $ref->bbox['width'] * (int) $ref->bbox['height'];
        // assertLessThan($maximum, $actual) asserts $actual < $maximum
        $this->assertLessThan($w0, $w1, 'refine should reduce mask area when excluding near the seed');
        $this->assertIsArray($ref->metadata);
        $this->assertSame('point_refine', $ref->metadata['prompt_type'] ?? null);
        $this->assertSame('local_seed_floodfill_refined', $ref->metadata['method'] ?? null);
        $this->assertSame(1, (int) ($ref->metadata['refine_count'] ?? 0));
    }

    public function test_refine_disabled_returns_null(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.local_floodfill.refine_enabled' => false,
        ]);
        $p = new FloodfillStudioLayerExtractionProvider;
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $pick = $p->extractCandidateFromPoint(
            $a,
            0.38,
            0.38,
            [
                'image_binary' => $png,
                'label' => 'P',
                'candidate_id' => 'pick_a',
            ]
        );
        $this->assertNotNull($pick);
        $r = $p->refineCandidateWithPoints(
            $a,
            $pick,
            [['x' => 0.38, 'y' => 0.38]],
            [['x' => 0.5, 'y' => 0.5]],
            ['image_binary' => $png]
        );
        $this->assertNull($r);
    }

    public function test_dedupe_drops_overlapping_bboxes_by_iou(): void
    {
        $p = new FloodfillStudioLayerExtractionProvider;
        $ref = new \ReflectionClass(FloodfillStudioLayerExtractionProvider::class);
        $m = $ref->getMethod('dedupeCandidatesByBboxIou');
        $m->setAccessible(true);
        $items = [
            [
                'id' => 1,
                'count' => 100,
                'bbox' => ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20],
            ],
            [
                'id' => 2,
                'count' => 50,
                'bbox' => ['x' => 2, 'y' => 2, 'width' => 16, 'height' => 16],
            ],
        ];
        $out = $m->invoke($p, $items, 0.5);
        $this->assertCount(1, $out);
        $this->assertSame(1, $out[0]['id']);
    }

    public function test_extract_from_box_returns_box_metadata(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
            'studio_layer_extraction.floodfill.color_tolerance' => 50,
            'studio_layer_extraction.local_floodfill.box_pick_enabled' => true,
            'studio_layer_extraction.local_floodfill.box_fallback_rectangle' => true,
            'studio_layer_extraction.local_floodfill.min_area_ratio' => 0.0001,
        ]);

        $p = new FloodfillStudioLayerExtractionProvider;
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $c = $p->extractCandidateFromBox(
            $a,
            ['x' => 0.3, 'y' => 0.3, 'width' => 0.4, 'height' => 0.4],
            [
                'image_binary' => $png,
                'label' => 'Box-selected element',
                'candidate_id' => 'box_unit',
            ]
        );
        $this->assertNotNull($c);
        $this->assertSame('box_unit', $c->id);
        $this->assertIsArray($c->metadata);
        $this->assertSame('box', $c->metadata['prompt_type'] ?? null);
        $this->assertArrayHasKey('box_normalized', $c->metadata ?? []);
    }

    public function test_box_pick_disabled_returns_null(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config(['studio_layer_extraction.local_floodfill.box_pick_enabled' => false]);
        $p = new FloodfillStudioLayerExtractionProvider;
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $c = $p->extractCandidateFromBox(
            $a,
            ['x' => 0.1, 'y' => 0.1, 'width' => 0.8, 'height' => 0.8],
            [
                'image_binary' => $png,
                'label' => 'B',
                'candidate_id' => 'box_x',
            ]
        );
        $this->assertNull($c);
    }

    private function makeWhitePngExceedingPixelCap(int $maxPixels): string
    {
        $w = (int) ceil(sqrt($maxPixels + 1_000));
        $h = $w;
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new \RuntimeException('GD');
        }
        $wpx = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $wpx);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    private function makeWhiteWithBlackDotPng(): string
    {
        $im = imagecreatetruecolor(24, 24);
        $w = imagecolorallocate($im, 255, 255, 255);
        $b = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, 23, 23, $w);
        imagefilledrectangle($im, 8, 8, 10, 10, $b);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    private function makeMockAsset(): Asset
    {
        $a = new Asset;
        $a->id = '00000000-0000-4000-8000-000000000001';

        return $a;
    }
}
