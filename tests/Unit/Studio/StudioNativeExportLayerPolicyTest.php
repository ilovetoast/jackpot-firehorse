<?php

namespace Tests\Unit\Studio;

use App\Models\Composition;
use App\Studio\Rendering\CompositionRenderNormalizer;
use App\Studio\Rendering\Dto\RenderTimeline;
use Tests\TestCase;

class StudioNativeExportLayerPolicyTest extends TestCase
{
    public function test_live_text_alias_normalizes_to_text_overlay(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440099';
        $c = new Composition([
            'document_json' => [
                'width' => 1080,
                'height' => 1920,
                'layers' => [
                    [
                        'id' => 'pv',
                        'type' => 'video',
                        'visible' => true,
                        'z' => 0,
                        'assetId' => $uuid,
                        'src' => 'https://example.invalid/v.mp4',
                        'transform' => ['x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1920],
                    ],
                    [
                        'id' => 'lt1',
                        'type' => 'live_text',
                        'visible' => true,
                        'z' => 3,
                        'content' => 'Hello live',
                        'style' => ['fontFamily' => 'Inter', 'fontSize' => 32, 'color' => '#ffffff'],
                        'transform' => ['x' => 10, 'y' => 20, 'width' => 400, 'height' => 80],
                    ],
                ],
            ],
        ]);
        $n = new CompositionRenderNormalizer;
        $tl = new RenderTimeline(1080, 1920, 30, 10_000, '#000000');
        $plan = $n->buildOverlayPlan($c, ['id' => 'pv', 'z' => 0], $tl);
        $this->assertCount(1, $plan->overlayLayers);
        $this->assertSame('text', $plan->overlayLayers[0]->type);
        $this->assertSame('Hello live', $plan->overlayLayers[0]->extra['content'] ?? null);
    }

    public function test_unknown_visible_layer_recorded_in_diagnostics(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440099';
        $c = new Composition([
            'document_json' => [
                'width' => 1080,
                'height' => 1920,
                'layers' => [
                    [
                        'id' => 'pv',
                        'type' => 'video',
                        'visible' => true,
                        'z' => 0,
                        'assetId' => $uuid,
                        'src' => 'https://example.invalid/v.mp4',
                        'transform' => ['x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1920],
                    ],
                    [
                        'id' => 'weird',
                        'type' => 'particle_system',
                        'visible' => true,
                        'z' => 2,
                        'transform' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
                    ],
                ],
            ],
        ]);
        $n = new CompositionRenderNormalizer;
        $tl = new RenderTimeline(1080, 1920, 30, 10_000, '#000000');
        $plan = $n->buildOverlayPlan($c, ['id' => 'pv', 'z' => 0], $tl);
        $this->assertSame([], $plan->overlayLayers);
        $u = $plan->diagnostics['unsupported_visible'] ?? [];
        $this->assertNotEmpty($u);
        $this->assertSame('weird', $u[0]['layer_id'] ?? null);
    }

    public function test_radial_text_boost_fill_normalizes_to_preraster_spec(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440099';
        $c = new Composition([
            'document_json' => [
                'width' => 1080,
                'height' => 1920,
                'layers' => [
                    [
                        'id' => 'pv',
                        'type' => 'video',
                        'visible' => true,
                        'z' => 0,
                        'assetId' => $uuid,
                        'src' => 'https://example.invalid/v.mp4',
                        'transform' => ['x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1920],
                    ],
                    [
                        'id' => 'fill_rad',
                        'type' => 'fill',
                        'visible' => true,
                        'z' => 3,
                        'textBoostStyle' => 'radial',
                        'textBoostColor' => '#112233',
                        'textBoostOpacity' => 0.65,
                        'textBoostSecondaryColor' => '#ffffff',
                        'textBoostGradientScale' => 1.2,
                        'transform' => ['x' => 0, 'y' => 0, 'width' => 200, 'height' => 100],
                    ],
                ],
            ],
        ]);
        $n = new CompositionRenderNormalizer;
        $tl = new RenderTimeline(1080, 1920, 30, 10_000, '#000000');
        $plan = $n->buildOverlayPlan($c, ['id' => 'pv', 'z' => 0], $tl);
        $this->assertCount(1, $plan->overlayLayers);
        $this->assertSame([], $plan->diagnostics['unsupported_visible'] ?? []);
        $spec = $plan->overlayLayers[0]->extra['fill_shape_spec'] ?? null;
        $this->assertIsArray($spec);
        $this->assertSame('fill_radial_text_boost', $spec['kind']);
        $this->assertSame(0.65, $spec['opacity']);
        $this->assertSame('#112233', $spec['color_edge_hex']);
        $this->assertSame('#ffffff', $spec['color_center_hex']);
        $this->assertSame(1.2, $spec['gradient_scale']);
    }
}
