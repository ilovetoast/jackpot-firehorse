<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Routes audio-pipeline jobs (transcoding, AI prep) to a dedicated queue
 * so heavy re-encodes do not contend with image thumbnails or starve the
 * standard `images` worker.
 *
 *   - audio_queue        small/medium files (default)
 *   - audio_heavy_queue  source bytes >= assets.audio.heavy_queue_min_bytes
 *
 * If a tenant does not run a separate Horizon supervisor for these queues,
 * set both env vars to `images` and the jobs land back in the standard
 * pipeline — graceful degradation rather than dropped work.
 */
final class AudioPipelineQueueResolver
{
    public static function forByteSize(int $bytes): string
    {
        $min = (int) config('assets.audio.heavy_queue_min_bytes', 100 * 1024 * 1024);
        if ($min > 0 && $bytes >= $min) {
            return (string) config('queue.audio_heavy_queue', 'audio-heavy');
        }

        return (string) config('queue.audio_queue', 'audio');
    }

    public static function forAsset(Asset $asset): string
    {
        $bytes = (int) ($asset->size_bytes ?? 0);
        if ($asset->relationLoaded('currentVersion') && $asset->currentVersion) {
            $bytes = max($bytes, (int) ($asset->currentVersion->file_size ?? 0));
        } else {
            $cv = $asset->currentVersion()->first();
            if ($cv) {
                $bytes = max($bytes, (int) ($cv->file_size ?? 0));
            }
        }

        return self::forByteSize($bytes);
    }
}
