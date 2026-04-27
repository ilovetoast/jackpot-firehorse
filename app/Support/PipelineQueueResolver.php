<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Routes the asset pipeline to the standard images queue or a dedicated heavy queue
 * based on source file size (bytes on asset or current version).
 *
 * Optional: when {@see config('queue.images_psd_queue')} is set, Photoshop documents
 * route to a dedicated queue so they can be processed by very high–memory workers
 * without contending with other large rasters.
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

    /**
     * Full pipeline queue: optional PSD/PSB isolation, then size-based heavy vs images.
     *
     * @param  string|null  $mimeType  Current version or asset mime (e.g. image/vnd.adobe.photoshop)
     * @param  string|null  $originalFilename  Used for .psd / .psb when MIME is generic
     */
    public static function forPipeline(int $fileSizeBytes, ?string $mimeType, ?string $originalFilename): string
    {
        $psdQueue = trim((string) config('queue.images_psd_queue', ''));
        if ($psdQueue !== '' && self::isPsdLike($mimeType, $originalFilename)) {
            return $psdQueue;
        }

        return self::forByteSize($fileSizeBytes);
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

        $mime = $asset->mime_type;
        if ($asset->relationLoaded('currentVersion') && $asset->currentVersion) {
            $mime = $asset->currentVersion->mime_type ?? $mime;
        } else {
            $cv = $asset->currentVersion()->first();
            if ($cv) {
                $mime = $cv->mime_type ?? $mime;
            }
        }

        return self::forPipeline(
            $bytes,
            $mime,
            (string) ($asset->original_filename ?? '')
        );
    }

    /**
     * @param  string|null  $mimeType
     * @param  string|null  $originalFilename
     */
    public static function isPsdLike(?string $mimeType, ?string $originalFilename): bool
    {
        $m = strtolower(trim((string) $mimeType));
        if (in_array($m, [
            'image/vnd.adobe.photoshop',
            'application/x-photoshop',
            'application/vnd.adobe.photoshop',
        ], true)) {
            return true;
        }
        if ($m === 'image/photoshop') {
            return true;
        }
        $ext = strtolower((string) pathinfo((string) $originalFilename, PATHINFO_EXTENSION));

        return in_array($ext, ['psd', 'psb'], true);
    }
}
