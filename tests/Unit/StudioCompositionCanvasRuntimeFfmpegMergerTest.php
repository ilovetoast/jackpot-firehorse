<?php

namespace Tests\Unit;

use App\Contracts\StudioCanvasRuntimeFfmpegProcessInvokerContract;
use App\Services\Studio\StudioCompositionCanvasRuntimeFfmpegMerger;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class StudioCompositionCanvasRuntimeFfmpegMergerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_validation_fails_when_working_directory_missing(): void
    {
        $invoker = Mockery::mock(StudioCanvasRuntimeFfmpegProcessInvokerContract::class);
        $invoker->shouldReceive('run')->never();
        $this->app->instance(StudioCanvasRuntimeFfmpegProcessInvokerContract::class, $invoker);

        $merger = $this->app->make(StudioCompositionCanvasRuntimeFfmpegMerger::class);
        $r = $merger->mergeToTempMp4(
            '/no/such/canvas-runtime-dir',
            $this->validManifest(2),
            __FILE__,
            0.0,
            0.066,
            1080,
            1920,
            'black',
            false,
        );

        $this->assertFalse($r['ok']);
        Assert::assertSame('validation_failed', $r['diagnostics']['phase'] ?? null);
        Assert::assertSame('working_directory_missing', $r['diagnostics']['validation']['code'] ?? null);
    }

    public function test_validation_fails_when_disk_frame_count_mismatch(): void
    {
        $dir = sys_get_temp_dir().'/jp-merger-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        try {
            file_put_contents($dir.'/frame_000000.png', $this->minimalPngBytes());
            $manifest = $this->validManifest(2);

            $invoker = Mockery::mock(StudioCanvasRuntimeFfmpegProcessInvokerContract::class);
            $invoker->shouldReceive('run')->never();
            $this->app->instance(StudioCanvasRuntimeFfmpegProcessInvokerContract::class, $invoker);

            $merger = $this->app->make(StudioCompositionCanvasRuntimeFfmpegMerger::class);
            $r = $merger->mergeToTempMp4(
                $dir,
                $manifest,
                $this->tinyMp4Path(),
                0.0,
                2 / 30.0,
                1080,
                1920,
                'black',
                false,
            );

            $this->assertFalse($r['ok']);
            Assert::assertSame('disk_frame_count_mismatch', $r['diagnostics']['validation']['code'] ?? null);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_invoker_failure_persists_exit_code_in_diagnostics(): void
    {
        $dir = sys_get_temp_dir().'/jp-merger-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        try {
            file_put_contents($dir.'/frame_000000.png', $this->minimalPngBytes());
            file_put_contents($dir.'/frame_000001.png', $this->minimalPngBytes());

            $invoker = Mockery::mock(StudioCanvasRuntimeFfmpegProcessInvokerContract::class);
            $invoker->shouldReceive('run')->once()->andReturn([
                'exitCode' => 99,
                'stdout' => '',
                'stderr' => 'mock ffmpeg error',
            ]);
            $this->app->instance(StudioCanvasRuntimeFfmpegProcessInvokerContract::class, $invoker);

            $merger = $this->app->make(StudioCompositionCanvasRuntimeFfmpegMerger::class);
            $r = $merger->mergeToTempMp4(
                $dir,
                $this->validManifest(2),
                $this->tinyMp4Path(),
                0.0,
                2 / 30.0,
                1080,
                1920,
                'black',
                false,
            );

            $this->assertFalse($r['ok']);
            Assert::assertSame(99, $r['diagnostics']['exit_code'] ?? null);
            Assert::assertStringContainsString('mock ffmpeg error', (string) ($r['diagnostics']['stderr_tail'] ?? ''));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validManifest(int $frames): array
    {
        return [
            'schema' => 'studio_canvas_capture_manifest_v1',
            'fps' => 30,
            'duration_ms' => 66,
            'width' => 1080,
            'height' => 1920,
            'total_expected_frames' => $frames,
            'total_captured_frames' => $frames,
            'frame_filename_pattern' => 'frame_%06d.png',
        ];
    }

    private function tinyMp4Path(): string
    {
        static $cached = null;
        if ($cached !== null && is_file($cached)) {
            return $cached;
        }
        $path = sys_get_temp_dir().'/jp-studio-tiny-base-'.hash('sha256', __FILE__).'.mp4';
        if (! is_file($path) || filesize($path) < 100) {
            $cmd = sprintf(
                'ffmpeg -y -nostdin -loglevel error -f lavfi -i color=c=black:s=320x240:r=30 -t 2 -pix_fmt yuv420p %s 2>/dev/null',
                escapeshellarg($path)
            );
            exec($cmd, $o, $code);
            if ($code !== 0 || ! is_file($path)) {
                $this->markTestSkipped('ffmpeg not available to build tiny test MP4');
            }
        }
        $cached = $path;

        return $cached;
    }

    private function minimalPngBytes(): string
    {
        $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        return base64_decode($b64, true) ?: '';
    }
}
