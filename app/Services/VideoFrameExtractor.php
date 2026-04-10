<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Samples still frames from a local video file using FFmpeg (no external API cost).
 *
 * Rules: at most one frame every N seconds, hard cap on frame count, only the first
 * {@see config('assets.video_ai.max_duration_seconds')} of the timeline are considered.
 */
class VideoFrameExtractor
{
    /**
     * @return array{frame_paths: list<string>, video_has_audio: bool, effective_duration_sampled: float}
     */
    public function extractFrames(string $videoPath): array
    {
        $ffmpeg = $this->findFFmpegPath();
        $ffprobe = $this->findFfprobePath();

        if (! $ffmpeg || ! $ffprobe) {
            throw new \RuntimeException('FFmpeg or ffprobe not found; video frame extraction requires FFmpeg.');
        }

        if (! is_readable($videoPath)) {
            throw new \RuntimeException('Video path is not readable: '.$videoPath);
        }

        $maxFrames = max(1, (int) config('assets.video_ai.max_frames', 20));
        $interval = max(1, (int) config('assets.video_ai.frame_interval_seconds', 3));
        $maxDuration = max($interval, (int) config('assets.video_ai.max_duration_seconds', 120));

        $duration = $this->probeDuration($ffprobe, $videoPath);
        $videoHasAudio = $this->probeHasAudio($ffprobe, $videoPath);

        $timelineCap = min($duration > 0 ? $duration : (float) $maxDuration, (float) $maxDuration);
        $framePaths = [];

        for ($i = 0; $i < $maxFrames; $i++) {
            $t = $i * $interval;
            if ($t >= $timelineCap) {
                break;
            }

            $out = tempnam(sys_get_temp_dir(), 'vf_frame_').'.jpg';
            if ($out === false) {
                throw new \RuntimeException('Failed to allocate temp path for frame');
            }

            $cmd = sprintf(
                '%s -hide_banner -loglevel error -ss %.3f -i %s -frames:v 1 -q:v 5 -y %s 2>&1',
                escapeshellarg($ffmpeg),
                $t,
                escapeshellarg($videoPath),
                escapeshellarg($out)
            );

            $output = [];
            $code = 0;
            exec($cmd, $output, $code);

            if ($code !== 0 || ! is_file($out) || filesize($out) < 32) {
                Log::warning('[VideoFrameExtractor] Frame extract failed', [
                    'time' => $t,
                    'return' => $code,
                    'output' => implode("\n", array_slice($output, 0, 5)),
                ]);
                @unlink($out);

                continue;
            }

            $framePaths[] = $out;
        }

        return [
            'frame_paths' => $framePaths,
            'video_has_audio' => $videoHasAudio,
            'effective_duration_sampled' => $timelineCap,
        ];
    }

    /**
     * Billable timeline length in minutes (capped by max_duration_seconds), for plan limits before full analysis.
     */
    public function estimateBillableMinutes(string $videoPath): float
    {
        $ffprobe = $this->findFfprobePath();
        if (! $ffprobe || ! is_readable($videoPath)) {
            return 0.0;
        }

        $maxDuration = max(1, (int) config('assets.video_ai.max_duration_seconds', 120));
        $duration = $this->probeDuration($ffprobe, $videoPath);
        $cap = min($duration > 0 ? $duration : (float) $maxDuration, (float) $maxDuration);

        return round($cap / 60, 4);
    }

    protected function probeDuration(string $ffprobe, string $videoPath): float
    {
        $cmd = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($videoPath)
        );
        $out = shell_exec($cmd);
        $v = is_string($out) ? trim($out) : '';
        $f = (float) $v;

        return $f > 0 ? $f : 0.0;
    }

    protected function probeHasAudio(string $ffprobe, string $videoPath): bool
    {
        $cmd = sprintf(
            '%s -v error -select_streams a -show_entries stream=codec_type -of csv=p=0 %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($videoPath)
        );
        $out = shell_exec($cmd);

        return is_string($out) && str_contains(strtolower($out), 'audio');
    }

    protected function findFFmpegPath(): ?string
    {
        return $this->findBinary(['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg']);
    }

    protected function findFfprobePath(): ?string
    {
        return $this->findBinary(['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe']);
    }

    /**
     * @param  list<string>  $candidates
     */
    protected function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if ($path === 'ffmpeg' || $path === 'ffprobe') {
                $out = [];
                $code = 0;
                $which = $path === 'ffmpeg' ? 'ffmpeg' : 'ffprobe';
                exec('which '.$which.' 2>/dev/null', $out, $code);
                if ($code === 0 && isset($out[0]) && is_executable($out[0])) {
                    return $out[0];
                }
            } elseif (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
