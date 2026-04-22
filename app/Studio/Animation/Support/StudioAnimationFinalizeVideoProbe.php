<?php

namespace App\Studio\Animation\Support;

use App\Support\VideoDisplayProbe;

/**
 * FFprobe-based checks on a remote clip before credits are charged and the job completes.
 */
final class StudioAnimationFinalizeVideoProbe
{
    /**
     * Inspect an in-memory MP4 (or other container) download; uses a temp file.
     *
     * @return array{
     *     duration: float,
     *     display_width: int|null,
     *     display_height: int|null,
     *     coded_width: int|null,
     *     coded_height: int|null,
     * }
     */
    public function probeBinary(string $binary): array
    {
        $minSize = max(32, (int) config('studio_animation.finalize_validation.min_size_bytes', 256));
        if (strlen($binary) < $minSize) {
            throw new \RuntimeException(
                'FINALIZE_INVALID_VIDEO: Downloaded file is too small to be a valid video ('.strlen($binary).' bytes).'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sa_fin_');
        if ($tmp === false) {
            throw new \RuntimeException('FINALIZE_INVALID_VIDEO: Could not allocate temp file.');
        }

        try {
            if (file_put_contents($tmp, $binary) === false) {
                throw new \RuntimeException('FINALIZE_INVALID_VIDEO: Could not write temp file.');
            }

            return $this->probeFilePath($tmp);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @return array{
     *     duration: float,
     *     display_width: int|null,
     *     display_height: int|null,
     *     coded_width: int|null,
     *     coded_height: int|null,
     * }
     */
    public function probeFilePath(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw new \RuntimeException('FINALIZE_INVALID_VIDEO: Path is not readable.');
        }

        $ffprobe = $this->resolveFfprobePath();
        if ($ffprobe === null) {
            throw new \RuntimeException('FINALIZE_INVALID_VIDEO: ffprobe is not available; cannot validate video output.');
        }

        $command = sprintf(
            '%s -v error -print_format json -show_format -show_streams %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($absolutePath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        $jsonOutput = implode("\n", $output);

        if ($returnCode !== 0) {
            throw new \RuntimeException(
                'FINALIZE_INVALID_VIDEO: ffprobe failed (exit '.$returnCode.'): '.mb_substr($jsonOutput, 0, 300)
            );
        }

        $videoData = json_decode($jsonOutput, true);
        if (! is_array($videoData) || ! isset($videoData['format'])) {
            throw new \RuntimeException('FINALIZE_INVALID_VIDEO: Could not parse ffprobe JSON.');
        }

        $dims = VideoDisplayProbe::dimensionsFromFfprobe($videoData);
        if ($dims === null) {
            throw new \RuntimeException('FINALIZE_INVALID_VIDEO: No decodable video stream in file.');
        }

        $duration = $this->effectiveDurationSeconds($videoData);
        $minW = max(1, (int) config('studio_animation.finalize_validation.min_width', 16));
        $minH = max(1, (int) config('studio_animation.finalize_validation.min_height', 16));
        $dw = (int) $dims['display_width'];
        $dh = (int) $dims['display_height'];
        if ($dw < $minW || $dh < $minH) {
            throw new \RuntimeException(
                "FINALIZE_INVALID_VIDEO: Video dimensions too small ({$dw}×{$dh}), expected at least {$minW}×{$minH}."
            );
        }

        $minDur = (float) config('studio_animation.finalize_validation.min_effective_duration_seconds', 0.3);
        if (! ($duration > $minDur)) {
            throw new \RuntimeException(
                'FINALIZE_INVALID_VIDEO: Video has no useful timeline (effective duration '.
                round($duration, 3)."s, need > {$minDur}s). Provider may have returned an empty or corrupt file."
            );
        }

        return [
            'duration' => $duration,
            'display_width' => $dw,
            'display_height' => $dh,
            'coded_width' => (int) $dims['width'],
            'coded_height' => (int) $dims['height'],
        ];
    }

    /**
     * Prefer format duration; fall back to primary video stream duration.
     */
    private function effectiveDurationSeconds(array $videoData): float
    {
        $formatDur = (float) ($videoData['format']['duration'] ?? 0);
        $streams = $videoData['streams'] ?? [];
        if (! is_array($streams)) {
            return $formatDur > 0 ? $formatDur : 0.0;
        }

        $maxStream = 0.0;
        foreach ($streams as $s) {
            if (! is_array($s) || ($s['codec_type'] ?? '') !== 'video') {
                continue;
            }
            $d = (float) ($s['duration'] ?? 0);
            if ($d > $maxStream) {
                $maxStream = $d;
            }
        }

        $eff = $formatDur > 0 ? $formatDur : $maxStream;
        if ($eff > 0) {
            return $eff;
        }
        if ($maxStream > 0) {
            return $maxStream;
        }

        return 0.0;
    }

    private function resolveFfprobePath(): ?string
    {
        $candidates = [
            (string) config('studio_animation.finalize_validation.ffprobe_path', ''),
            'ffprobe',
            '/usr/bin/ffprobe',
            '/usr/local/bin/ffprobe',
            '/opt/homebrew/bin/ffprobe',
        ];

        foreach ($candidates as $path) {
            if ($path === '' || $path === 'ffprobe') {
                $output = [];
                $code = 0;
                exec('which ffprobe 2>/dev/null', $output, $code);
                if ($code === 0 && ! empty($output[0]) && is_executable($output[0])) {
                    return $output[0];
                }
            } elseif (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
