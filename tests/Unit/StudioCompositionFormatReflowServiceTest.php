<?php

namespace Tests\Unit;

use App\Services\Studio\StudioCompositionFormatReflowService;
use PHPUnit\Framework\TestCase;

class StudioCompositionFormatReflowServiceTest extends TestCase
{
    public function test_changes_canvas_size_and_scales_headline_zone(): void
    {
        $svc = new StudioCompositionFormatReflowService;
        $doc = [
            'width' => 1080,
            'height' => 1080,
            'layers' => [
                [
                    'id' => 't1',
                    'type' => 'text',
                    'name' => 'Headline',
                    'visible' => true,
                    'locked' => false,
                    'z' => 2,
                    'studioSyncRole' => 'headline',
                    'transform' => ['x' => 80, 'y' => 60, 'width' => 920, 'height' => 120],
                    'content' => 'Hello',
                    'style' => ['fontFamily' => 'Arial', 'fontSize' => 48, 'color' => '#fff'],
                ],
            ],
        ];
        $out = $svc->reflowToCanvasSize($doc, 1080, 1920);
        $this->assertSame(1080, $out['width']);
        $this->assertSame(1920, $out['height']);
        $layer = $out['layers'][0];
        $this->assertSame('t1', $layer['id']);
        $this->assertGreaterThan(80, (int) ($layer['transform']['y'] ?? 0));
        $this->assertGreaterThan(40, (int) ($layer['style']['fontSize'] ?? 0));
    }

    public function test_no_op_when_dimensions_unchanged(): void
    {
        $svc = new StudioCompositionFormatReflowService;
        $doc = ['width' => 1080, 'height' => 1080, 'layers' => []];
        $out = $svc->reflowToCanvasSize($doc, 1080, 1080);
        $this->assertSame($doc, $out);
    }
}
