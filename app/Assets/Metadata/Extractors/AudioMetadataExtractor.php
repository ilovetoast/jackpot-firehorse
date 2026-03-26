<?php

namespace App\Assets\Metadata\Extractors;

use App\Assets\Metadata\Extractors\Contracts\EmbeddedMetadataExtractor;

/**
 * Audio file tags via ffprobe (format tags), same mechanics as video container.
 */
class AudioMetadataExtractor implements EmbeddedMetadataExtractor
{
    public function supports(string $mimeType, string $extension): bool
    {
        if (str_starts_with($mimeType, 'audio/')) {
            return true;
        }

        return in_array(strtolower($extension), ['mp3', 'aac', 'm4a', 'wav', 'flac', 'ogg'], true);
    }

    /**
     * {@inheritdoc}
     */
    public function extract(string $localPath, string $mimeType, string $extension): array
    {
        $video = new VideoMetadataExtractor;
        $partial = $video->extract($localPath, $mimeType, $extension);

        return [
            'audio' => $partial['video'] ?? [],
            'other' => $partial['other'] ?? [],
        ];
    }
}
