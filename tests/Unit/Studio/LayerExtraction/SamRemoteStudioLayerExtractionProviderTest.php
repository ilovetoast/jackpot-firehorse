<?php

namespace Tests\Unit\Studio\LayerExtraction;

use App\Models\Asset;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;
use App\Studio\LayerExtraction\Providers\FloodfillStudioLayerExtractionProvider;
use App\Studio\LayerExtraction\Providers\SamStudioLayerExtractionProvider;
use App\Studio\LayerExtraction\Sam\SamMaskSegment;
use App\Studio\LayerExtraction\Sam\SamSegmentationResult;
use Tests\TestCase;

class SamRemoteStudioLayerExtractionProviderTest extends TestCase
{
    public function test_auto_segment_returns_multiple_candidates_from_fake_client(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $m1 = $this->maskPngWithBlob(0, 0, 4, 4, 32, 32);
        $m2 = $this->maskPngWithBlob(10, 10, 6, 6, 32, 32);
        $fake = new RecordingFakeSamSegmentationClient;
        $fake->auto = new SamSegmentationResult(
            [
                new SamMaskSegment($m1, ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4], 0.9, 'A'),
                new SamMaskSegment($m2, ['x' => 10, 'y' => 10, 'width' => 6, 'height' => 6], 0.8, 'B'),
            ],
            'fake_sam2',
            10,
            'fal_sam2',
        );
        $this->remoteConfig();
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider, $fake);
        $a = $this->mockAsset();
        $png = $this->smallRgbPng(32, 32);
        $r = $sam->extractMasks($a, ['image_binary' => $png]);
        $this->assertCount(2, $r->candidates);
        $this->assertSame('A', $r->candidates[0]->label);
        $this->assertSame('B', $r->candidates[1]->label);
    }

    public function test_point_prompt_passes_norm_coords_to_client(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $m = $this->maskPngWithBlob(0, 0, 2, 2, 16, 16);
        $fake = new RecordingFakeSamSegmentationClient;
        $fake->byPoints = new SamSegmentationResult(
            [new SamMaskSegment($m, ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2], null, 'P')],
            'fake_sam2',
            1,
            'fal_sam2',
        );
        $this->remoteConfig();
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider, $fake);
        $a = $this->mockAsset();
        $png = $this->smallRgbPng(16, 16);
        $sam->extractCandidateFromPoint($a, 0.5, 0.25, [
            'image_binary' => $png,
            'label' => 'Picked',
            'candidate_id' => 'pick_x',
        ]);
        $this->assertCount(1, $fake->pointCalls);
        $this->assertCount(1, $fake->pointCalls[0]['pos']);
        $this->assertSame(0.5, $fake->pointCalls[0]['pos'][0]['x']);
        $this->assertSame(0.25, $fake->pointCalls[0]['pos'][0]['y']);
        $this->assertSame([], $fake->pointCalls[0]['neg']);
    }

    public function test_refine_passes_positive_and_negative_points_to_client(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $m = $this->maskPngWithBlob(0, 0, 2, 2, 16, 16);
        $fake = new RecordingFakeSamSegmentationClient;
        $fake->byPoints = new SamSegmentationResult(
            [new SamMaskSegment($m, ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2], null, 'P')],
            'fake_sam2',
            1,
            'fal_sam2',
        );
        $this->remoteConfig();
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider, $fake);
        $a = $this->mockAsset();
        $png = $this->smallRgbPng(16, 16);
        $base = new LayerExtractionCandidateDto(
            'c1',
            'X',
            null,
            ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
            null,
            base64_encode($m),
            null,
            true,
            'n',
            [
                'segmentation_engine' => 'fal_sam2',
                'provider' => 'sam',
                'model' => 'm',
                'prompt_type' => 'point',
            ]
        );
        $pos = [['x' => 0.3, 'y' => 0.4], ['x' => 0.5, 'y' => 0.5]];
        $neg = [['x' => 0.1, 'y' => 0.1]];
        $sam->refineCandidateWithPoints($a, $base, $pos, $neg, ['image_binary' => $png]);
        $this->assertCount(1, $fake->pointCalls);
        $this->assertCount(2, $fake->pointCalls[0]['pos']);
        $this->assertCount(1, $fake->pointCalls[0]['neg']);
    }

    public function test_box_passes_pixel_box_sized_to_fal_dimensions(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $m = $this->maskPngWithBlob(0, 0, 2, 2, 8, 8);
        $fake = new RecordingFakeSamSegmentationClient;
        $fake->byBox = new SamSegmentationResult(
            [new SamMaskSegment($m, ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2], null, 'B')],
            'fake_sam2',
            1,
            'fal_sam2',
        );
        $this->remoteConfig();
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider, $fake);
        $a = $this->mockAsset();
        $png = $this->smallRgbPng(8, 8);
        $sam->extractCandidateFromBox($a, [
            'x' => 0.25,
            'y' => 0.25,
            'width' => 0.5,
            'height' => 0.5,
        ], [
            'image_binary' => $png,
            'candidate_id' => 'box_x',
            'label' => 'L',
        ]);
        $this->assertCount(1, $fake->boxCalls);
        $b = $fake->boxCalls[0]['box'];
        $this->assertArrayHasKey('x_min', $b);
    }

    public function test_remote_candidate_metadata_includes_engine_and_model(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        $m = $this->maskPngWithBlob(0, 0, 2, 2, 16, 16);
        $fake = new RecordingFakeSamSegmentationClient;
        $fake->byPoints = new SamSegmentationResult(
            [new SamMaskSegment($m, ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2], 0.7, 'P')],
            'fal_model_test',
            25,
            'fal_sam2',
        );
        $this->remoteConfig();
        $sam = new SamStudioLayerExtractionProvider(new FloodfillStudioLayerExtractionProvider, $fake);
        $a = $this->mockAsset();
        $c = $sam->extractCandidateFromPoint($a, 0.4, 0.4, [
            'image_binary' => $this->smallRgbPng(16, 16),
            'label' => 'L',
        ]);
        $this->assertNotNull($c);
        $meta = $c->metadata ?? [];
        $this->assertSame('fal_sam2', $meta['segmentation_engine'] ?? null);
        $this->assertArrayHasKey('model', $meta);
        $this->assertArrayHasKey('remote_duration_ms', $meta);
        $this->assertArrayHasKey('prompt_type', $meta);
    }

    private function remoteConfig(): void
    {
        config([
            'studio_layer_extraction.sam.enabled' => true,
            'studio_layer_extraction.sam.model' => 'test_sam2',
            'studio_layer_extraction.sam.max_input_edge' => 4096,
            'studio_layer_extraction.sam.max_input_pixels' => 1_000_000,
        ]);
    }

    private function mockAsset(): Asset
    {
        $a = new Asset;
        $a->id = '00000000-0000-4000-8000-000000000001';

        return $a;
    }

    private function smallRgbPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new \RuntimeException('gd');
        }
        $c = imagecolorallocate($im, 120, 100, 90);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $c);
        ob_start();
        imagepng($im);
        $p = (string) ob_get_clean();
        imagedestroy($im);

        return $p;
    }

    private function maskPngWithBlob(int $bx, int $by, int $bw, int $bh, int $fw, int $fh): string
    {
        $im = imagecreatetruecolor($fw, $fh);
        if ($im === false) {
            throw new \RuntimeException('gd');
        }
        $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
        $fg = imagecolorallocate($im, 255, 255, 255);
        imagealphablending($im, true);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $fw - 1, $fh - 1, $bg);
        imagefilledrectangle($im, $bx, $by, $bx + $bw - 1, $by + $bh - 1, $fg);
        ob_start();
        imagepng($im);
        $p = (string) ob_get_clean();
        imagedestroy($im);

        return $p;
    }
}
