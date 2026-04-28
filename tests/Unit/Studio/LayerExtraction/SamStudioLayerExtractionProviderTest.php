<?php

namespace Tests\Unit\Studio\LayerExtraction;

use App\Models\Asset;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionBoxPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointRefineProviderInterface;
use App\Studio\LayerExtraction\Providers\FloodfillStudioLayerExtractionProvider;
use App\Studio\LayerExtraction\Providers\SamStudioLayerExtractionProvider;
use Tests\TestCase;

class SamStudioLayerExtractionProviderTest extends TestCase
{
    public function test_capability_flags_match_sam_style_contract(): void
    {
        $p = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider);
        $this->assertTrue($p->supportsMultipleMasks());
        $this->assertFalse($p->supportsBackgroundFill());
        $this->assertTrue($p->supportsLabels());
        $this->assertTrue($p->supportsConfidence());
        $this->assertInstanceOf(StudioLayerExtractionPointPickProviderInterface::class, $p);
        $this->assertInstanceOf(StudioLayerExtractionPointRefineProviderInterface::class, $p);
        $this->assertInstanceOf(StudioLayerExtractionBoxPickProviderInterface::class, $p);
    }

    public function test_auto_masks_merge_sam_metadata_with_prompt_fields(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
            'studio_layer_extraction.floodfill.color_tolerance' => 50,
            'studio_layer_extraction.local_floodfill.enable_multi_candidate' => true,
            'studio_layer_extraction.sam.model' => 'unit_test_sam',
        ]);
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider);
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $r = $sam->extractMasks($a, ['image_binary' => $png]);
        $this->assertNotEmpty($r->candidates);
        $meta = $r->candidates[0]->metadata ?? [];
        $this->assertSame('sam', $meta['provider'] ?? null);
        $this->assertSame('unit_test_sam', $meta['model'] ?? null);
        $this->assertSame('auto', $meta['prompt_type'] ?? null);
        $this->assertArrayHasKey('image_size', $meta);
        $this->assertSame(24, $meta['image_size']['width'] ?? 0);
        $this->assertSame(24, $meta['image_size']['height'] ?? 0);
    }

    public function test_point_pick_merges_positive_points_in_metadata(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
            'studio_layer_extraction.floodfill.color_tolerance' => 50,
        ]);
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider);
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $c = $sam->extractCandidateFromPoint($a, 10 / 24, 10 / 24, [
            'image_binary' => $png,
            'label' => 'Test label',
            'candidate_id' => 'cand-1',
        ]);
        $this->assertNotNull($c);
        $meta = $c->metadata ?? [];
        $this->assertSame('point', $meta['prompt_type'] ?? null);
        $this->assertIsArray($meta['positive_points'] ?? null);
        $this->assertNotEmpty($meta['positive_points']);
    }

    public function test_box_pick_merges_boxes_in_metadata(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        config([
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
            'studio_layer_extraction.floodfill.color_tolerance' => 50,
        ]);
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider);
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $box = ['x' => 0.2, 'y' => 0.2, 'width' => 0.4, 'height' => 0.4];
        $c = $sam->extractCandidateFromBox($a, $box, [
            'image_binary' => $png,
            'label' => 'Test label',
            'candidate_id' => 'cand-1',
        ]);
        $this->assertNotNull($c);
        $meta = $c->metadata ?? [];
        $this->assertSame('box', $meta['prompt_type'] ?? null);
        $this->assertIsArray($meta['boxes'] ?? null);
        $this->assertNotEmpty($meta['boxes']);
    }

    public function test_refine_merges_positive_and_negative_points_in_metadata(): void
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
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider);
        $png = $this->makeWhiteWithBlackDotPng();
        $a = $this->makeMockAsset();
        $opts = [
            'image_binary' => $png,
            'label' => 'Picked',
            'candidate_id' => 'pick_unit',
        ];
        $base = $sam->extractCandidateFromPoint($a, 0.38, 0.38, $opts);
        $this->assertNotNull($base);
        $pos = [['x' => 0.38, 'y' => 0.38]];
        $neg = [['x' => 0.38, 'y' => 0.38]];
        $c = $sam->refineCandidateWithPoints($a, $base, $pos, $neg, $opts);
        $this->assertNotNull($c);
        $meta = $c->metadata ?? [];
        $this->assertSame('point_refine', $meta['prompt_type'] ?? null);
        $this->assertEquals($pos, $meta['positive_points'] ?? null);
        $this->assertEquals($neg, $meta['negative_points'] ?? null);
    }

    private function makeWhiteWithBlackDotPng(): string
    {
        $im = imagecreatetruecolor(24, 24);
        if ($im === false) {
            throw new \RuntimeException('GD');
        }
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
