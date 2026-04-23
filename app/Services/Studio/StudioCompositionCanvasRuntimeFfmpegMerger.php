<?php

namespace App\Services\Studio;

use App\Contracts\StudioCanvasRuntimeFfmpegProcessInvokerContract;
use Illuminate\Support\Facades\File;

/**
 * FFmpeg merge for canvas_runtime: scaled/padded base video + captured PNG sequence overlay, audio per legacy rules.
 *
 * Visual policy: Playwright captures the full composition scene raster (including HTML5 video).
 * raster (including HTML5 video). Those PNGs are typically opaque; compositing them over the decoded base video
 * reproduces the same pixels as the PNG sequence while keeping the base asset on the graph for trim alignment and
 * audio muxing (see diagnostics duration_policy / merge_visual_policy).
 */
final class StudioCompositionCanvasRuntimeFfmpegMerger
{
    public function __construct(
        private StudioCanvasRuntimeFfmpegProcessInvokerContract $ffmpegInvoker,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest  capture-manifest.json (decoded)
     * @return array{
     *     ok: true,
     *     local_mp4_path: string,
     *     diagnostics: array<string, mixed>
     * }|array{
     *     ok: false,
     *     diagnostics: array<string, mixed>
     * }
     */
    public function mergeToTempMp4(
        string $workingDirectory,
        array $manifest,
        string $baseVideoLocalPath,
        float $trimInSeconds,
        float $outputDurationSeconds,
        int $canvasWidth,
        int $canvasHeight,
        string $padColor,
        bool $wantAudio,
    ): array {
        $mergeStarted = microtime(true);
        $ffmpeg = (string) config('studio_video.ffmpeg_binary', 'ffmpeg');
        $timeout = max(30.0, (float) config('studio_video.canvas_runtime_merge_timeout_seconds', 3600));

        $v = $this->validateInputs($workingDirectory, $manifest, $baseVideoLocalPath, $trimInSeconds, $outputDurationSeconds, $canvasWidth, $canvasHeight);
        if ($v !== null) {
            return [
                'ok' => false,
                'diagnostics' => array_merge($this->baseDiagnostics($ffmpeg, $workingDirectory, $manifest, $outputDurationSeconds, $wantAudio), [
                    'phase' => 'validation_failed',
                    'validation' => $v,
                ]),
            ];
        }

        $fps = (int) $manifest['fps'];
        $pattern = (string) $manifest['frame_filename_pattern'];
        $padW = $this->patternPadWidth($pattern);
        if ($padW === null) {
            return [
                'ok' => false,
                'diagnostics' => array_merge($this->baseDiagnostics($ffmpeg, $workingDirectory, $manifest, $outputDurationSeconds, $wantAudio), [
                    'phase' => 'validation_failed',
                    'validation' => ['code' => 'invalid_frame_filename_pattern', 'pattern' => $pattern],
                ]),
            ];
        }

        $seqPath = rtrim($workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($pattern, DIRECTORY_SEPARATOR.'/\\');

        $tmpOut = storage_path('app/tmp/studio-canvas-merge-'.bin2hex(random_bytes(8)).'.mp4');
        @mkdir(dirname($tmpOut), 0775, true);

        $w = $canvasWidth;
        $h = $canvasHeight;
        $outDur = sprintf('%.6f', max(0.04, $outputDurationSeconds));
        $trimIn = sprintf('%.6f', max(0.0, $trimInSeconds));
        $preset = (string) config('studio_video.canvas_runtime_merge_x264_preset', 'veryfast');
        $crf = (string) (int) config('studio_video.canvas_runtime_merge_x264_crf', 23);
        $pixFmt = (string) config('studio_video.canvas_runtime_merge_pixel_format', 'yuv420p');

        // Base: trim + duration cap (same ordering as legacy). Overlay: fps + duration trim to match export length.
        $padEsc = $this->escapeFilterValue($padColor);
        // Base duration is capped by demuxer -t; overlay uses shortest=1 so output matches the shorter stream.
        $fc = sprintf(
            '[0:v]scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:%s,setsar=1[bg];'.
            '[1:v]fps=%d,format=rgba[fg];'.
            '[bg][fg]overlay=0:0:format=auto:shortest=1,format=yuv420p[vout]',
            $w,
            $h,
            $w,
            $h,
            $padEsc,
            $fps
        );

        $argv = [$ffmpeg, '-y'];
        if ($trimInSeconds > 0.0001) {
            $argv[] = '-ss';
            $argv[] = $trimIn;
        }
        $argv = array_merge($argv, [
            '-i', $baseVideoLocalPath,
            '-t', $outDur,
            '-framerate', (string) $fps,
            '-start_number', '0',
            '-i', $seqPath,
            '-filter_complex', $fc,
            '-map', '[vout]',
        ]);
        if ($wantAudio) {
            $argv = array_merge($argv, [
                '-map', '0:a:0',
                '-c:a', 'aac',
                '-b:a', '192k',
            ]);
        } else {
            $argv[] = '-an';
        }
        $argv = array_merge($argv, [
            '-c:v', 'libx264',
            '-preset', $preset,
            '-crf', $crf,
            '-pix_fmt', $pixFmt,
            '-movflags', '+faststart',
            $tmpOut,
        ]);

        $result = $this->ffmpegInvoker->run($argv, null, $timeout);
        $wallMs = (int) round((microtime(true) - $mergeStarted) * 1000);
        $exit = $result['exitCode'];

        $redactedArgv = $this->redactArgvForDiagnostics($argv, $workingDirectory, $baseVideoLocalPath, $tmpOut);

        $baseDiag = array_merge($this->baseDiagnostics($ffmpeg, $workingDirectory, $manifest, $outputDurationSeconds, $wantAudio), [
            'phase' => $exit === 0 && is_file($tmpOut) ? 'ffmpeg_finished' : 'ffmpeg_failed',
            'exit_code' => $exit,
            'encode_wall_clock_ms' => $wallMs,
            'ffmpeg_argv_redacted' => $redactedArgv,
            'filter_complex_summary' => 'base(scale+pad)+png(fps+rgba)+overlay(shortest)+yuv420p',
            'stderr_tail' => mb_substr($result['stderr'], -8000),
            'stdout_tail' => mb_substr($result['stdout'], -2000),
        ]);

        if ($exit !== 0 || ! is_file($tmpOut) || filesize($tmpOut) < 32) {
            @unlink($tmpOut);

            return [
                'ok' => false,
                'diagnostics' => array_merge($baseDiag, [
                    'phase' => 'ffmpeg_failed',
                ]),
            ];
        }

        return [
            'ok' => true,
            'local_mp4_path' => $tmpOut,
            'diagnostics' => array_merge($baseDiag, [
                'phase' => 'complete',
                'output_bytes' => filesize($tmpOut),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null  error payload or null if ok
     */
    private function validateInputs(
        string $workingDirectory,
        array $manifest,
        string $baseVideoLocalPath,
        float $trimInSeconds,
        float $outputDurationSeconds,
        int $canvasWidth,
        int $canvasHeight,
    ): ?array {
        if (! is_dir($workingDirectory)) {
            return ['code' => 'working_directory_missing', 'path' => $workingDirectory];
        }
        if (! is_file($baseVideoLocalPath) || filesize($baseVideoLocalPath) < 32) {
            return ['code' => 'base_video_local_missing', 'path' => $baseVideoLocalPath];
        }
        if ($canvasWidth < 2 || $canvasHeight < 2) {
            return ['code' => 'invalid_canvas_dimensions', 'width' => $canvasWidth, 'height' => $canvasHeight];
        }
        if ($outputDurationSeconds < 0.04) {
            return ['code' => 'invalid_output_duration', 'output_duration_s' => $outputDurationSeconds];
        }
        $fps = (int) ($manifest['fps'] ?? 0);
        if ($fps < 1) {
            return ['code' => 'invalid_manifest_fps', 'fps' => $manifest['fps'] ?? null];
        }
        $totalCaptured = (int) ($manifest['total_captured_frames'] ?? 0);
        $totalExpected = (int) ($manifest['total_expected_frames'] ?? $totalCaptured);
        if ($totalCaptured < 1) {
            return ['code' => 'zero_frames_captured', 'total_captured_frames' => $totalCaptured];
        }
        if ($totalExpected > 0 && $totalCaptured !== $totalExpected) {
            return [
                'code' => 'frame_count_expected_mismatch',
                'total_captured_frames' => $totalCaptured,
                'total_expected_frames' => $totalExpected,
            ];
        }
        $durationMs = (int) ($manifest['duration_ms'] ?? 0);
        if ($durationMs < 1) {
            return ['code' => 'invalid_manifest_duration_ms', 'duration_ms' => $manifest['duration_ms'] ?? null];
        }

        $mw = (int) ($manifest['width'] ?? 0);
        $mh = (int) ($manifest['height'] ?? 0);
        if ($mw !== $canvasWidth || $mh !== $canvasHeight) {
            return [
                'code' => 'manifest_dimension_mismatch',
                'manifest_width' => $mw,
                'manifest_height' => $mh,
                'canvas_width' => $canvasWidth,
                'canvas_height' => $canvasHeight,
            ];
        }

        $pattern = (string) ($manifest['frame_filename_pattern'] ?? '');
        if ($pattern === '' || ! str_contains($pattern, '%')) {
            return ['code' => 'manifest_frame_pattern_missing', 'frame_filename_pattern' => $pattern];
        }
        $padW = $this->patternPadWidth($pattern);
        if ($padW === null) {
            return ['code' => 'manifest_frame_pattern_unparsed', 'frame_filename_pattern' => $pattern];
        }

        $glob = rtrim($workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'frame_*.png';
        $files = File::glob($glob) ?: [];
        if (count($files) !== $totalCaptured) {
            return [
                'code' => 'disk_frame_count_mismatch',
                'glob_count' => count($files),
                'total_captured_frames' => $totalCaptured,
            ];
        }

        $expectedByScript = (int) max(1, ceil(($durationMs / 1000.0) * $fps - 1e-12));
        if ($totalCaptured !== $expectedByScript) {
            return [
                'code' => 'manifest_frame_count_formula_mismatch',
                'total_captured_frames' => $totalCaptured,
                'expected_frames_from_duration_fps' => $expectedByScript,
                'duration_ms' => $durationMs,
                'fps' => $fps,
            ];
        }

        $captureSpanS = $totalCaptured / $fps;
        $tolS = max(0.05, 1.5 / $fps);
        $dSpan = abs($captureSpanS - $outputDurationSeconds);
        $dManifest = abs($durationMs / 1000.0 - $outputDurationSeconds);
        if ($dSpan > $tolS && $dManifest > $tolS) {
            return [
                'code' => 'capture_duration_vs_export_duration_mismatch',
                'capture_span_s' => $captureSpanS,
                'manifest_duration_s' => $durationMs / 1000.0,
                'target_output_duration_s' => $outputDurationSeconds,
                'tolerance_s' => $tolS,
                'delta_span_s' => $dSpan,
                'delta_manifest_s' => $dManifest,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function baseDiagnostics(
        string $ffmpegBinary,
        string $workingDirectory,
        array $manifest,
        float $outputDurationSeconds,
        bool $wantAudio,
    ): array {
        return [
            'schema' => 'studio_canvas_runtime_merge_diagnostics_v1',
            'ffmpeg_binary' => basename($ffmpegBinary),
            'working_directory' => $workingDirectory,
            'target_output_duration_s' => $outputDurationSeconds,
            'captured_frame_count' => (int) ($manifest['total_captured_frames'] ?? 0),
            'manifest_fps' => $manifest['fps'] ?? null,
            'manifest_duration_ms' => $manifest['duration_ms'] ?? null,
            'audio_policy' => $wantAudio
                ? 'mux_base_audio_aac_when_stream_present_matches_legacy_include_audio_and_layer_mute_rules'
                : 'silent_mp4_no_audio_track_legacy_equivalent',
            'duration_policy' => 'final_mux_length_matches_legacy_output_duration_s_trimmed_base_and_trimmed_overlay_manifest_must_align_within_tolerance',
            'merge_visual_policy' => 'opaque_png_overlay_on_scaled_trimmed_base_html_video_is_included_in_pngs_base_kept_for_audio_and_contract_alignment',
        ];
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function redactArgvForDiagnostics(array $argv, string $workDir, string $baseTmp, string $outTmp): array
    {
        $wd = rtrim($workDir, DIRECTORY_SEPARATOR);
        $map = [
            $wd => '<capture_work_dir>',
            $baseTmp => '<tmp_base_video>',
            $outTmp => '<tmp_out_mp4>',
        ];

        return array_map(static function (string $s) use ($map): string {
            foreach ($map as $real => $label) {
                if ($real !== '' && str_contains($s, $real)) {
                    return str_replace($real, $label, $s);
                }
            }

            return $s;
        }, $argv);
    }

    private function escapeFilterValue(string $padColor): string
    {
        return str_replace(['\\', ':'], ['\\\\', '\\:'], $padColor);
    }

    /**
     * @return positive-int|null
     */
    private function patternPadWidth(string $pattern): ?int
    {
        if (preg_match('/%0(\d+)d/', $pattern, $m) === 1) {
            $n = (int) $m[1];

            return $n > 0 ? $n : null;
        }

        return null;
    }
}
