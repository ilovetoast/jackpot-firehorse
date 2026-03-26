<?php

namespace App\Assets\Metadata\Extractors;

use App\Assets\Metadata\Extractors\Contracts\EmbeddedMetadataExtractor;

/**
 * Container / format tags via ffprobe when available.
 * TODO: extend with stream-level codec metadata if needed for search.
 */
class VideoMetadataExtractor implements EmbeddedMetadataExtractor
{
    public function supports(string $mimeType, string $extension): bool
    {
        if (str_starts_with($mimeType, 'video/')) {
            return true;
        }

        return in_array(strtolower($extension), ['mp4', 'mov', 'webm', 'mkv', 'avi', 'm4v'], true);
    }

    /**
     * {@inheritdoc}
     */
    public function extract(string $localPath, string $mimeType, string $extension): array
    {
        $ffprobe = $this->resolveFfprobePath();
        if (! $ffprobe) {
            return [
                'video' => [],
                'other' => ['video_extract' => 'ffprobe_unavailable'],
            ];
        }

        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($localPath)
        );
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            return ['video' => [], 'other' => ['video_extract' => 'ffprobe_failed']];
        }

        $json = json_decode(implode("\n", $output), true);
        if (! is_array($json)) {
            return ['video' => []];
        }

        $video = [];
        if (! empty($json['format']['tags']) && is_array($json['format']['tags'])) {
            foreach ($json['format']['tags'] as $k => $v) {
                if (is_string($v) || is_numeric($v)) {
                    $video['format.'.$k] = is_string($v) ? $v : (string) $v;
                }
            }
        }

        return ['video' => $video];
    }

    protected function resolveFfprobePath(): ?string
    {
        $candidates = ['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe'];
        foreach ($candidates as $path) {
            if ($path === 'ffprobe') {
                exec('which ffprobe 2>/dev/null', $o, $r);
                if ($r === 0 && ! empty($o[0]) && is_executable($o[0])) {
                    return $o[0];
                }
            } elseif (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
