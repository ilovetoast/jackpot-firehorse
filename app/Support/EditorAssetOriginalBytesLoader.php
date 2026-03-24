<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Loads original asset bytes from object storage — no HTTP to the app (avoids HTML/auth failures).
 * Uses {@see Asset::storage_root_path} (canonical original), never thumbnail paths.
 */
final class EditorAssetOriginalBytesLoader
{
    /**
     * Disk name from config when the asset uses the default app bucket; otherwise a dynamic disk is built.
     *
     * @throws \InvalidArgumentException
     */
    public static function loadFromStorage(Asset $asset): string
    {
        $asset->loadMissing('storageBucket');
        $key = $asset->storage_root_path;
        if (! is_string($key) || $key === '' || ! $asset->storageBucket) {
            throw new \InvalidArgumentException('Original file is not available in storage.');
        }

        $bucketName = $asset->storageBucket->name;
        $diskName = $asset->storage_disk ?? null;
        if (is_string($diskName) && $diskName !== '' && config("filesystems.disks.{$diskName}")) {
            try {
                $contents = Storage::disk($diskName)->get($key);
            } catch (\Throwable $e) {
                Log::error('Storage read failed', [
                    'error' => $e->getMessage(),
                    'asset_id' => $asset->id,
                    'disk' => $diskName,
                    'path' => $key,
                ]);

                throw new \InvalidArgumentException('Failed to load original image from storage.', 0, $e);
            }
        } else {
            $defaultBucket = config('filesystems.disks.s3.bucket');
            try {
                if ($defaultBucket !== null && $defaultBucket !== '' && $bucketName === $defaultBucket) {
                    $contents = Storage::disk('s3')->get($key);
                } else {
                    $base = config('filesystems.disks.s3', []);
                    if (! is_array($base) || ($base['driver'] ?? '') !== 's3') {
                        throw new \RuntimeException('S3 disk not configured.');
                    }
                    $config = array_merge($base, [
                        'driver' => 's3',
                        'bucket' => $bucketName,
                        'throw' => true,
                    ]);
                    $contents = Storage::build($config)->get($key);
                }
            } catch (\Throwable $e) {
                Log::error('Storage read failed', [
                    'error' => $e->getMessage(),
                    'asset_id' => $asset->id,
                    'bucket' => $bucketName,
                    'path' => $key,
                ]);

                throw new \InvalidArgumentException('Failed to load original image from storage.', 0, $e);
            }
        }

        if (! is_string($contents) || $contents === '') {
            throw new \InvalidArgumentException('Empty file from storage');
        }

        Log::info('Loaded image from storage', [
            'bytes' => strlen($contents),
            'path' => $key,
            'bucket' => $bucketName,
        ]);

        return $contents;
    }
}
