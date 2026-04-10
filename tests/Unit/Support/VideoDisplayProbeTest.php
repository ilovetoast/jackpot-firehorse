<?php

namespace Tests\Unit\Support;

use App\Support\VideoDisplayProbe;
use PHPUnit\Framework\TestCase;

class VideoDisplayProbeTest extends TestCase
{
    public function test_rotation_90_swaps_display_dimensions(): void
    {
        $stream = [
            'width' => 1920,
            'height' => 1080,
            'tags' => ['rotate' => '90'],
        ];
        $d = VideoDisplayProbe::dimensionsFromStream($stream);
        $this->assertSame(90, $d['rotation']);
        $this->assertSame(1080, $d['display_width']);
        $this->assertSame(1920, $d['display_height']);
        $this->assertSame('transpose=1', VideoDisplayProbe::ffmpegTransposeFilters(90));
    }

    public function test_rotation_270(): void
    {
        $this->assertSame('transpose=2', VideoDisplayProbe::ffmpegTransposeFilters(270));
    }

    public function test_normalize_negative_ninety_is_two_seventy(): void
    {
        $this->assertSame(270, VideoDisplayProbe::normalizeRotationDegrees(-90));
    }

    public function test_no_rotation_keeps_coded_size(): void
    {
        $stream = ['width' => 1280, 'height' => 720];
        $d = VideoDisplayProbe::dimensionsFromStream($stream);
        $this->assertSame(0, $d['rotation']);
        $this->assertSame(1280, $d['display_width']);
        $this->assertSame(720, $d['display_height']);
        $this->assertSame('', VideoDisplayProbe::ffmpegTransposeFilters(0));
    }
}
