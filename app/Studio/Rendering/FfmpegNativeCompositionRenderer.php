<?php

namespace App\Studio\Rendering;

use App\Contracts\StudioCanvasRuntimeFfmpegProcessInvokerContract;
use App\Studio\Rendering\Contracts\CompositionRenderer;
use App\Studio\Rendering\Dto\CompositionRenderRequest;
use App\Studio\Rendering\Dto\CompositionRenderResult;
use App\Studio\Rendering\Dto\RenderTimeline;

/**
 * FFmpeg {@code filter_complex} renderer for normalized Studio composition requests.
 */
final class FfmpegNativeCompositionRenderer implements CompositionRenderer
{
    public function __construct(
        private FfmpegFilterGraphBuilder $graphBuilder,
        private StudioCanvasRuntimeFfmpegProcessInvokerContract $ffmpegInvoker,
    ) {}

    public function render(CompositionRenderRequest $request): CompositionRenderResult
    {
        $timeline = $request->timeline;
        $workspace = $request->workspacePath;
        if ($timeline === null || $workspace === null) {
            return CompositionRenderResult::failure('invalid_request', 'Timeline and workspace are required.', []);
        }

        $primary = $request->stagedPathsByKey['primary_video'] ?? null;
        if (! is_string($primary) || ! is_file($primary)) {
            return CompositionRenderResult::failure('missing_primary_video', 'Staged primary video path missing.', [
                'staged_keys' => array_keys($request->stagedPathsByKey),
            ]);
        }

        $ffmpeg = $this->ffmpegBinary();
        $graph = $this->graphBuilder->buildOverlayGraph($timeline, $request->layers);
        $tmpOut = $workspace.DIRECTORY_SEPARATOR.'out_'.uniqid('', true).'.mp4';

        $timing = $this->trimTiming($request->stagedPathsByKey, $timeline);
        $argv = array_merge(
            [$ffmpeg, '-y'],
            $timing['ss_before_i'] !== null ? ['-ss', $timing['ss_before_i']] : [],
            ['-i', $primary],
            $timing['t_flag'] !== null ? ['-t', $timing['t_flag']] : [],
        );
        foreach ($request->layers as $ly) {
            if ($ly->mediaPath !== null && is_file($ly->mediaPath)) {
                $argv = array_merge($argv, ['-loop', '1', '-i', $ly->mediaPath]);
            }
        }
        $argv = array_merge($argv, [
            '-filter_complex',
            $graph['filter_complex'],
            '-map',
            '['.$graph['video_out_label'].']',
        ]);
        $argv = $this->appendAudioMaps($argv, $request->includeAudio, $request->stagedPathsByKey);
        $argv = array_merge($argv, [
            '-c:v', 'libx264',
            '-preset', (string) config('studio_rendering.x264_preset', 'veryfast'),
            '-crf', (string) (int) config('studio_rendering.x264_crf', 23),
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $tmpOut,
        ]);

        $timeout = max(30.0, (float) config('studio_rendering.ffmpeg_subprocess_timeout_seconds', 3600));
        $result = $this->ffmpegInvoker->run($argv, null, $timeout);
        $exit = (int) $result['exitCode'];
        $stderr = (string) $result['stderr'];

        $diag = [
            'ffmpeg_binary' => $ffmpeg,
            'exit_code' => $exit,
            'stderr_tail' => mb_substr($stderr, -8000),
            'workspace_path' => $workspace,
            'filter_complex' => $graph['filter_complex'],
            'argv_redacted' => $this->redactArgv($argv),
        ];

        if ($exit !== 0 || ! is_file($tmpOut) || filesize($tmpOut) < 32) {
            @unlink($tmpOut);

            return CompositionRenderResult::failure('ffmpeg_failed', 'FFmpeg composition failed.', $diag);
        }

        $diag['output_bytes'] = filesize($tmpOut);

        return CompositionRenderResult::success($tmpOut, $diag);
    }

    private function ffmpegBinary(): string
    {
        $from = trim((string) config('studio_rendering.ffmpeg_binary', ''));

        return $from !== '' ? $from : (string) config('studio_video.ffmpeg_binary', 'ffmpeg');
    }

    /**
     * @param  array<string, mixed>  $staged
     * @return array{ss_before_i: ?string, t_flag: ?string}
     */
    private function trimTiming(array $staged, RenderTimeline $timeline): array
    {
        $trimInS = isset($staged['trim_in_s']) ? sprintf('%.6f', (float) $staged['trim_in_s']) : null;
        $dur = sprintf('%.6f', max(0.04, $timeline->outputDurationSeconds()));

        return [
            'ss_before_i' => $trimInS !== null && (float) $trimInS > 0.00001 ? $trimInS : null,
            't_flag' => $dur,
        ];
    }

    /**
     * @param  array<string, mixed>  $staged
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function appendAudioMaps(array $argv, bool $includeAudio, array $staged): array
    {
        $want = $includeAudio && ($staged['base_has_audio'] ?? false) && ! ($staged['layer_muted'] ?? false);
        if ($want) {
            return array_merge($argv, [
                '-map', '0:a:0?',
                '-c:a', 'aac',
                '-b:a', '192k',
            ]);
        }
        $argv[] = '-an';

        return $argv;
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function redactArgv(array $argv): array
    {
        return array_map(static function (string $s): string {
            if (strlen($s) > 260 && str_contains($s, DIRECTORY_SEPARATOR)) {
                return '<long_path>';
            }

            return $s;
        }, $argv);
    }
}
