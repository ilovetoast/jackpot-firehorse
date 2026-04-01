<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Routes the asset pipeline to the standard images queue or a dedicated heavy queue
 * based on source file size (bytes on asset or current version).
 */
final class PipelineQueueResolver
{
    public static function forByteSize(int $bytes): string
    {
        $min = (int) config('assets.processing.heavy_queue_min_bytes', 200 * 1024 * 1024);
        if ($min > 0 && $bytes >= $min) {
            return (string) config('queue.images_heavy_queue', 'images-heavy');
        }

        return (string) config('queue.images_queue', 'images');
    }

    public static function imagesQueueForAsset(Asset $asset): string
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
