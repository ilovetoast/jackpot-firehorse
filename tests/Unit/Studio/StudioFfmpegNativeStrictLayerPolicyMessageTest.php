<?php

namespace Tests\Unit\Studio;

use App\Studio\Rendering\StudioFfmpegNativeStrictLayerPolicyMessage;
use Tests\TestCase;

class StudioFfmpegNativeStrictLayerPolicyMessageTest extends TestCase
{
    public function test_summarizes_empty_text_and_below_primary(): void
    {
        $s = StudioFfmpegNativeStrictLayerPolicyMessage::summarize([
            'unsupported_visible' => [
                [
                    'layer_id' => 't1',
                    'type' => 'text',
                    'reason' => 'text_layer_empty_content',
                ],
            ],
            'skipped_below_primary_video' => [
                [
                    'layer_id' => 'img2',
                    'type' => 'image',
                    'canonical' => 'image',
                    'z' => 0,
                    'primary_video_z' => 5,
                    'reason' => 'below_primary_video_z_v1',
                ],
            ],
        ]);
        $this->assertStringContainsString('t1', $s);
        $this->assertStringContainsString('text', $s);
        $this->assertStringContainsString('img2', $s);
        $this->assertStringContainsString('z-order 0', $s);
    }

    public function test_summarizes_radial_fill_reason(): void
    {
        $s = StudioFfmpegNativeStrictLayerPolicyMessage::summarize([
            'unsupported_visible' => [
                [
                    'layer_id' => 'f-bg',
                    'type' => 'fill',
                    'reason' => 'fill_radial_or_unsupported_v1',
                ],
            ],
            'skipped_below_primary_video' => [],
        ]);
        $this->assertStringContainsString('f-bg', $s);
        $this->assertStringContainsString('scrim', $s);
    }
}
