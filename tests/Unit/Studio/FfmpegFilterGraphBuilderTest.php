<?php

namespace Tests\Unit\Studio;

use App\Studio\Rendering\Dto\RenderLayer;
use App\Studio\Rendering\Dto\RenderTimeline;
use App\Studio\Rendering\FfmpegFilterGraphBuilder;
use Tests\TestCase;

class FfmpegFilterGraphBuilderTest extends TestCase
{
    public function test_builds_graph_without_overlays(): void
    {
        $tl = new RenderTimeline(640, 480, 30, 5000, 'black');
        $b = new FfmpegFilterGraphBuilder;
        $g = $b->buildOverlayGraph($tl, []);
        $this->assertStringContainsString('[0:v]scale=640:480', $g['filter_complex']);
        $this->assertStringContainsString('format=yuv420p[vout]', $g['filter_complex']);
        $this->assertSame('vout', $g['video_out_label']);
        $this->assertSame(1, $g['input_count']);
    }

    public function test_builds_graph_with_one_overlay(): void
    {
        $tl = new RenderTimeline(1080, 1080, 30, 10_000, '0x112233');
        $layer = new RenderLayer(
            id: 'L1',
            type: 'image',
            zIndex: 2,
            startSeconds: 0.0,
            endSeconds: 10.0,
            visible: true,
            x: 10,
            y: 20,
            width: 100,
            height: 50,
            opacity: 1.0,
            rotationDegrees: 0.0,
            fit: 'cover',
            isPrimaryVideo: false,
            mediaPath: '/tmp/fake.png',
            trimInMs: 0,
            trimOutMs: 0,
            muted: false,
            fadeInMs: 0,
            fadeOutMs: 0,
            extra: [],
        );
        $b = new FfmpegFilterGraphBuilder;
        $g = $b->buildOverlayGraph($tl, [$layer]);
        $this->assertStringContainsString('[1:v]', $g['filter_complex']);
        $this->assertStringContainsString('overlay=10:20', $g['filter_complex']);
        $this->assertSame(2, $g['input_count']);
    }

    public function test_builds_graph_with_text_logo_and_shape_style_overlays(): void
    {
        $makePng = static function (): string {
            $p = sys_get_temp_dir().'/ffov_'.uniqid('', true).'.png';
            file_put_contents($p, str_repeat("\0", 200));

            return $p;
        };
        $p1 = $makePng();
        $p2 = $makePng();
        $p3 = $makePng();
        try {
            $tl = new RenderTimeline(1080, 1080, 30, 10_000, '0x112233');
            $layers = [];
            foreach ([['L1', 10, 20], ['L2', 0, 200], ['L3', 400, 400]] as [$id, $x, $y]) {
                $layers[] = new RenderLayer(
                    id: $id,
                    type: 'image',
                    zIndex: 2,
                    startSeconds: 0.0,
                    endSeconds: 10.0,
                    visible: true,
                    x: $x,
                    y: $y,
                    width: 100,
                    height: 50,
                    opacity: 1.0,
                    rotationDegrees: 0.0,
                    fit: 'fill',
                    isPrimaryVideo: false,
                    mediaPath: match ($id) {
                        'L1' => $p1,
                        'L2' => $p2,
                        default => $p3,
                    },
                    trimInMs: 0,
                    trimOutMs: 0,
                    muted: false,
                    fadeInMs: 0,
                    fadeOutMs: 0,
                    extra: [],
                );
            }
            $b = new FfmpegFilterGraphBuilder;
            $g = $b->buildOverlayGraph($tl, $layers);
            $this->assertSame(4, $g['input_count']);
            $this->assertStringContainsString('[3:v]', $g['filter_complex']);
        } finally {
            @unlink($p1);
            @unlink($p2);
            @unlink($p3);
        }
    }
}
