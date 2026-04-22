<?php

namespace Tests\Unit;

use App\Studio\Animation\Support\StudioAnimationFinalizeVideoProbe;
use Tests\TestCase;

class StudioAnimationFinalizeVideoProbeTest extends TestCase
{
    public function test_probe_binary_accepts_h264_fixture(): void
    {
        $path = __DIR__.'/../fixtures/studio_animation_valid_1s.mp4';
        if (! is_file($path)) {
            $this->markTestSkipped('Fixture missing: tests/fixtures/studio_animation_valid_1s.mp4');
        }
        if (! is_executable('/usr/bin/ffprobe') && ! is_executable('/bin/ffprobe')) {
            $out = [];
            $code = 0;
            exec('which ffprobe 2>/dev/null', $out, $code);
            if ($code !== 0 || ($out[0] ?? '') === '') {
                $this->markTestSkipped('ffprobe not available on PATH');
            }
        }

        $bin = (string) file_get_contents($path);
        $probe = new StudioAnimationFinalizeVideoProbe;
        $r = $probe->probeBinary($bin);
        $this->assertGreaterThan(0.5, $r['duration']);
        $this->assertGreaterThan(15, $r['display_width'] * $r['display_height']);
    }
}
