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

    public function test_sample_aspect_ratio_widens_display_width(): void
    {
        $stream = [
            'width' => 720,
            'height' => 480,
            'sample_aspect_ratio' => '32:27',
        ];
        $d = VideoDisplayProbe::dimensionsFromStream($stream);
        $this->assertSame(853, $d['display_width']);
        $this->assertSame(480, $d['display_height']);
    }

    public function test_select_primary_prefers_default_disposition(): void
    {
        $streams = [
            ['codec_type' => 'video', 'width' => 320, 'height' => 240, 'disposition' => ['default' => 0]],
            ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'disposition' => ['default' => 1]],
        ];
        $p = VideoDisplayProbe::selectPrimaryVideoStream($streams);
        $this->assertSame(1920, $p['width']);
    }

    public function test_select_primary_prefers_hevc_over_default_mjpeg_poster(): void
    {
        $streams = [
            [
                'codec_type' => 'video',
                'codec_name' => 'mjpeg',
                'width' => 320,
                'height' => 320,
                'disposition' => ['default' => 1],
            ],
            [
                'codec_type' => 'video',
                'codec_name' => 'hevc',
                'width' => 1920,
                'height' => 1080,
                'disposition' => ['default' => 0],
            ],
        ];
        $p = VideoDisplayProbe::selectPrimaryVideoStream($streams);
        $this->assertSame('hevc', $p['codec_name']);
        $this->assertSame(1920, $p['width']);
    }

    public function test_rotation_from_display_matrix_string_180_degrees(): void
    {
        // 180° in 16.16 fixed point (matches libavutil display matrix layout).
        $matrix = '-65536 0 0 0 -65536 0 0 0 1073741824';
        $stream = [
            'width' => 1920,
            'height' => 1080,
            'side_data_list' => [[
                'side_data_type' => 'Display Matrix',
                'displaymatrix' => $matrix,
            ]],
        ];
        $d = VideoDisplayProbe::dimensionsFromStream($stream);
        $this->assertSame(180, $d['rotation']);
        $this->assertSame('hflip,vflip', VideoDisplayProbe::ffmpegTransposeFilters(180));
    }

    public function test_display_matrix_wins_over_tags_rotate_zero(): void
    {
        $matrix = '-65536 0 0 0 -65536 0 0 0 1073741824';
        $stream = [
            'width' => 1920,
            'height' => 1080,
            'tags' => ['rotate' => '0'],
            'side_data_list' => [[
                'side_data_type' => 'Display Matrix',
                'displaymatrix' => $matrix,
            ]],
        ];
        $d = VideoDisplayProbe::dimensionsFromStream($stream);
        $this->assertSame(180, $d['rotation']);
    }

    public function test_rotation_from_side_data_numeric_without_matrix_in_type(): void
    {
        $stream = [
            'width' => 1280,
            'height' => 720,
            'side_data_list' => [[
                'side_data_type' => 'Unknown',
                'rotation' => '-90',
            ]],
        ];
        $d = VideoDisplayProbe::dimensionsFromStream($stream);
        $this->assertSame(270, $d['rotation']);
    }

    public function test_parse_display_matrix_nine_from_json_array(): void
    {
        $raw = [65536, 0, 0, 0, 65536, 0, 0, 0, 1073741824];
        $this->assertSame($raw, VideoDisplayProbe::parseDisplayMatrixNine($raw));
        $deg = VideoDisplayProbe::rotationDegreesFromFfmpegMatrixNine($raw);
        $this->assertEqualsWithDelta(0.0, $deg, 0.001);
    }

    public function test_format_rotate_fallback_when_stream_tags_zero(): void
    {
        $videoData = [
            'format' => ['tags' => ['rotate' => '180']],
            'streams' => [[
                'codec_type' => 'video',
                'width' => 1920,
                'height' => 1080,
                'tags' => ['rotate' => '0'],
                'disposition' => ['default' => 1],
            ]],
        ];
        $dims = VideoDisplayProbe::dimensionsFromFfprobe($videoData);
        $this->assertNotNull($dims);
        $this->assertSame(180, $dims['rotation']);
    }

    public function test_dimensions_from_ffprobe_null_without_video_stream(): void
    {
        $this->assertNull(VideoDisplayProbe::dimensionsFromFfprobe([
            'format' => ['duration' => '1'],
            'streams' => [['codec_type' => 'audio']],
        ]));
    }
}
