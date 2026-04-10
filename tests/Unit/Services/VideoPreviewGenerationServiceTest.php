<?php

namespace Tests\Unit\Services;

use App\Services\VideoPreviewGenerationService;
use Aws\S3\S3Client;
use Tests\TestCase;

/**
 * Smoke tests for hover MP4 pipeline (FFmpeg must exist in CI / Sail).
 *
 * @group ffmpeg
 */
class VideoPreviewGenerationServiceTest extends TestCase
{
    private function resolveFfmpegPath(): ?string
    {
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        exec('which ffmpeg 2>/dev/null', $out, $rc);
        if ($rc === 0 && ! empty($out[0]) && is_executable($out[0])) {
            return $out[0];
        }

        return null;
    }

    private function makeTinyH264Mp4(string $ffmpeg, string $path): void
    {
        $cmd = sprintf(
            '%s -nostdin -y -f lavfi -i testsrc2=duration=2:size=640x480:rate=24 -c:v libx264 -preset ultrafast -t 1 %s 2>/dev/null',
            escapeshellarg($ffmpeg),
            escapeshellarg($path)
        );
        exec($cmd, $o, $rc);
        if ($rc !== 0 || ! is_file($path) || filesize($path) < 100) {
            static::markTestSkipped('Could not build lavfi H.264 fixture (ffmpeg lavfi/libx264)');
        }
    }

    public function test_extract_preview_segment_writes_mp4(): void
    {
        $ffmpeg = $this->resolveFfmpegPath();
        if ($ffmpeg === null) {
            static::markTestSkipped('ffmpeg not found');
        }

        $in = tempnam(sys_get_temp_dir(), 'vpin').'.mp4';
        $this->makeTinyH264Mp4($ffmpeg, $in);

        $s3 = $this->createMock(S3Client::class);
        $service = new VideoPreviewGenerationService($s3);
        $method = new \ReflectionMethod($service, 'extractPreviewSegment');
        $method->setAccessible(true);

        $out = $method->invoke($service, $in, $ffmpeg, 0.0, 0.5, 320, 0);
        try {
            self::assertIsString($out);
            self::assertFileExists($out);
            self::assertGreaterThan(2000, filesize($out));
        } finally {
            @unlink($in);
            if (is_string($out)) {
                @unlink($out);
            }
        }
    }

    public function test_get_video_info_reads_duration_and_display_size(): void
    {
        $ffmpeg = $this->resolveFfmpegPath();
        if ($ffmpeg === null) {
            static::markTestSkipped('ffmpeg not found');
        }

        $in = tempnam(sys_get_temp_dir(), 'vpin').'.mp4';
        $this->makeTinyH264Mp4($ffmpeg, $in);

        $s3 = $this->createMock(S3Client::class);
        $service = new VideoPreviewGenerationService($s3);
        $method = new \ReflectionMethod($service, 'getVideoInfo');
        $method->setAccessible(true);

        try {
            $info = $method->invoke($service, $in, $ffmpeg);
            self::assertGreaterThan(0, $info['duration']);
            self::assertGreaterThan(0, $info['display_width']);
            self::assertGreaterThan(0, $info['display_height']);
            self::assertArrayHasKey('rotation', $info);
        } finally {
            @unlink($in);
        }
    }
}
