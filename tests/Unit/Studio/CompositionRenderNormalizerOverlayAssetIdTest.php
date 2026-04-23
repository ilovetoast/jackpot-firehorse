<?php

namespace Tests\Unit\Studio;

use App\Models\Composition;
use App\Studio\Rendering\CompositionRenderNormalizer;
use App\Studio\Rendering\Dto\RenderTimeline;
use Tests\TestCase;

class CompositionRenderNormalizerOverlayAssetIdTest extends TestCase
{
    private function baseDoc(array $overlayLayer): array
    {
        return [
            'width' => 1080,
            'height' => 1920,
            'layers' => [
                [
                    'id' => 'pv',
                    'type' => 'video',
                    'visible' => true,
                    'z' => 0,
                    'assetId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                    'src' => 'https://example.invalid/video.mp4',
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1920],
                ],
                $overlayLayer,
            ],
        ];
    }

    public function test_image_layer_resolves_asset_id_from_snake_case(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440001';
        $c = new Composition([
            'document_json' => $this->baseDoc([
                'id' => 'layer_overlay',
                'type' => 'image',
                'visible' => true,
                'z' => 10,
                'asset_id' => $uuid,
                'src' => '/app/api/assets/'.$uuid.'/file',
                'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
            ]),
        ]);
        $n = new CompositionRenderNormalizer;
        $tl = new RenderTimeline(1080, 1920, 30, 10_000, '#000000');
        $layers = $n->buildOverlayLayers($c, ['id' => 'pv', 'z' => 0], $tl);
        $this->assertCount(1, $layers);
        $this->assertSame($uuid, $layers[0]->extra['asset_id'] ?? null);
    }

    public function test_image_layer_resolves_asset_id_from_editor_src_url_when_keys_missing(): void
    {
        $uuid = '660e8400-e29b-41d4-a716-446655440002';
        $c = new Composition([
            'document_json' => $this->baseDoc([
                'id' => 'layer_overlay',
                'type' => 'image',
                'visible' => true,
                'z' => 10,
                'src' => '/app/api/assets/'.$uuid.'/file',
                'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
            ]),
        ]);
        $n = new CompositionRenderNormalizer;
        $tl = new RenderTimeline(1080, 1920, 30, 10_000, '#000000');
        $layers = $n->buildOverlayLayers($c, ['id' => 'pv', 'z' => 0], $tl);
        $this->assertCount(1, $layers);
        $this->assertSame($uuid, $layers[0]->extra['asset_id'] ?? null);
    }

    public function test_generative_image_resolves_result_asset_id_snake_case(): void
    {
        $uuid = '770e8400-e29b-41d4-a716-446655440003';
        $c = new Composition([
            'document_json' => $this->baseDoc([
                'id' => 'layer_gen',
                'type' => 'generative_image',
                'visible' => true,
                'z' => 10,
                'result_asset_id' => $uuid,
                'resultSrc' => 'https://cdn.example/out.png',
                'prompt' => ['scene' => 'x'],
                'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
            ]),
        ]);
        $n = new CompositionRenderNormalizer;
        $tl = new RenderTimeline(1080, 1920, 30, 10_000, '#000000');
        $layers = $n->buildOverlayLayers($c, ['id' => 'pv', 'z' => 0], $tl);
        $this->assertCount(1, $layers);
        $this->assertSame('image', $layers[0]->type);
        $this->assertSame($uuid, $layers[0]->extra['asset_id'] ?? null);
    }
}
