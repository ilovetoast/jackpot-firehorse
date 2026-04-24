<?php

namespace Tests\Unit\Studio;

use App\Studio\Rendering\Dto\RenderLayer;
use App\Studio\Rendering\Dto\RenderTimeline;
use App\Studio\Rendering\FfmpegFilterGraphBuilder;
use Tests\TestCase;

class FfmpegFilterGraphBuilderTest extends TestCase
{
    public function test_multiply_blend_uses_blend_filter_instead_of_overlay_only(): void
    {
        $tl = new RenderTimeline(1920, 1080, 30, 10_000, 'black');
        $layer = new RenderLayer(
            id: 'img1',
            type: 'image',
            zIndex: 2,
            startSeconds: 0.0,
            endSeconds: 10.0,
            visible: true,
            x: 100,
            y: 200,
            width: 400,
            height: 300,
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
            extra: ['blend_mode' => 'multiply', 'asset_id' => 'x'],
        );
        $g = (new FfmpegFilterGraphBuilder)->buildOverlayGraph($tl, [$layer]);
        $this->assertStringContainsString('blend=all_mode=multiply', $g['filter_complex']);
        $this->assertStringContainsString('color=c=black@0.0:s=1920x1080', $g['filter_complex']);
        $this->assertStringContainsString('setpts=PTS-STARTPTS', $g['filter_complex']);
        $this->assertStringContainsString('[ovfull0]', $g['filter_complex']);
    }

    public function test_color_dodge_maps_to_ffmpeg_dodge_not_invalid_colordodge(): void
    {
        $tl = new RenderTimeline(640, 480, 30, 5_000, 'black');
        $layer = new RenderLayer(
            id: 'img1',
            type: 'image',
            zIndex: 1,
            startSeconds: 0.0,
            endSeconds: 5.0,
            visible: true,
            x: 0,
            y: 0,
            width: 100,
            height: 100,
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
            extra: ['blend_mode' => 'color-dodge'],
        );
        $g = (new FfmpegFilterGraphBuilder)->buildOverlayGraph($tl, [$layer]);
        $this->assertStringContainsString('blend=all_mode=dodge', $g['filter_complex']);
        $this->assertStringNotContainsString('colordodge', $g['filter_complex']);
    }

    public function test_hue_blend_falls_back_to_overlay_ffmpeg_has_no_hue_blend_mode(): void
    {
        $tl = new RenderTimeline(640, 480, 30, 5_000, 'black');
        $layer = new RenderLayer(
            id: 'img1',
            type: 'image',
            zIndex: 1,
            startSeconds: 0.0,
            endSeconds: 5.0,
            visible: true,
            x: 0,
            y: 0,
            width: 100,
            height: 100,
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
            extra: ['blend_mode' => 'hue'],
        );
        $g = (new FfmpegFilterGraphBuilder)->buildOverlayGraph($tl, [$layer]);
        $this->assertStringContainsString('overlay=0:0', $g['filter_complex']);
        $this->assertStringNotContainsString('blend=all_mode', $g['filter_complex']);
    }

    public function test_normal_blend_uses_classic_overlay_only(): void
    {
        $tl = new RenderTimeline(640, 480, 30, 5_000, 'black');
        $layer = new RenderLayer(
            id: 'img1',
            type: 'image',
            zIndex: 1,
            startSeconds: 0.0,
            endSeconds: 5.0,
            visible: true,
            x: 0,
            y: 0,
            width: 100,
            height: 100,
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
            extra: ['blend_mode' => 'normal'],
        );
        $g = (new FfmpegFilterGraphBuilder)->buildOverlayGraph($tl, [$layer]);
        $this->assertStringContainsString('overlay=0:0', $g['filter_complex']);
        $this->assertStringNotContainsString('blend=all_mode', $g['filter_complex']);
    }
}
