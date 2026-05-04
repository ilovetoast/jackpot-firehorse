<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Loads original asset bytes from object storage — no HTTP to the app (avoids HTML/auth failures).
 * Defaults to {@see Asset::storage_root_path}; pass $objectKey for the current version file path when it differs.
 *
 * Some pipeline rows (e.g. studio_animation) omit storage_bucket_id while still
 * storing the object on the shared output disk — see {@see self::loadViaFallbackDisks()}.
 */
final class EditorAssetOriginalBytesLoader
{
    /**
     * Resolve bytes via {@see Asset::storageBucket} + configured / dynamic S3 disk (never pass a model into {@see Storage::disk()}).
     *
     * @param  ?string  $objectKey  Object key in bucket; null/empty uses {@see Asset::storage_root_path}.
     *
     * @throws \InvalidArgumentException
     */
    public static function loadFromStorage(Asset $asset, ?string $objectKey = null): string
    {
        $asset->loadMissing('storageBucket');
        $key = ($objectKey !== null && $objectKey !== '') ? $objectKey : $asset->storage_root_path;
        if (! is_string($key) || $key === '') {
            throw new \InvalidArgumentException('Original file is not available in storage.');
        }

        if ($asset->storageBucket !== null) {
            return self::loadViaBucket($asset, $key);
        }

        return self::loadViaFallbackDisks($asset, $key);
    }

    /**
     * Ordered Laravel disk names used when an asset has no {@see Asset::$storageBucket} row
     * (studio outputs, etc.). Same order as {@see self::loadViaFallbackDisks()}.
     *
     * @return list<string>
     */
    public static function fallbackDiskNamesInPriorityOrder(): array
    {
        $candidates = [];
        $outputDisk = config('studio_animation.output_disk');
        if (is_string($outputDisk) && $outputDisk !== '') {
            $candidates[] = $outputDisk;
        }
        $candidates[] = 's3';

        $out = [];
        foreach (array_unique($candidates) as $diskName) {
            if (! is_string($diskName) || $diskName === '' || ! config("filesystems.disks.{$diskName}")) {
                continue;
            }
            $out[] = $diskName;
        }

        return $out;
    }

    /**
     * Which fallback disk successfully reads this object key (no storage_bucket row).
     */
    public static function resolveFallbackDiskForObjectKey(Asset $asset, ?string $objectKey = null): ?string
    {
        $asset->loadMissing('storageBucket');
        if ($asset->storageBucket !== null) {
            return null;
        }
        $key = ($objectKey !== null && $objectKey !== '') ? $objectKey : $asset->storage_root_path;
        if (! is_string($key) || $key === '') {
            return null;
        }
        foreach (self::fallbackDiskNamesInPriorityOrder() as $diskName) {
            try {
                $contents = Storage::disk($diskName)->get($key);
                if (is_string($contents) && $contents !== '') {
                    return $diskName;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function loadViaBucket(Asset $asset, string $key): string
    {
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

        Log::info('Loaded asset bytes from storage', [
            'bytes' => strlen($contents),
            'path' => $key,
            'bucket' => $bucketName,
        ]);

        return $contents;
    }

    /**
     * Studio animation (and similar) assets are written with Storage::disk() but may not set storage_bucket_id.
     *
     * @throws \InvalidArgumentException
     */
    private static function loadViaFallbackDisks(Asset $asset, string $key): string
    {
        foreach (self::fallbackDiskNamesInPriorityOrder() as $diskName) {
            try {
                $contents = Storage::disk($diskName)->get($key);
                if (is_string($contents) && $contents !== '') {
                    Log::info('Loaded asset bytes from storage (no storage_bucket row)', [
                        'asset_id' => $asset->id,
                        'disk' => $diskName,
                        'path' => $key,
                        'bytes' => strlen($contents),
                    ]);

                    return $contents;
                }
            } catch (\Throwable $e) {
                Log::debug('[EditorAssetOriginalBytesLoader] fallback disk read miss', [
                    'asset_id' => $asset->id,
                    'disk' => $diskName,
                    'path' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new \InvalidArgumentException(
            'Original file is not available in storage (asset has no storage bucket; tried configured output and s3 disks).',
        );
    }

    /**
     * Overwrite an object key in the same disk/bucket resolution order as {@see loadFromStorage()}.
     *
     * @param  array<string, mixed>  $options  Laravel filesystem options (e.g. visibility, ContentType for S3)
     *
     * @throws \InvalidArgumentException
     */
    public static function put(Asset $asset, string $objectKey, string $body, array $options = []): void
    {
        $asset->loadMissing('storageBucket');
        if (! is_string($objectKey) || $objectKey === '') {
            throw new \InvalidArgumentException('Object key is required.');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('Refusing to write empty body.');
        }

        if ($asset->storageBucket !== null) {
            self::putViaBucket($asset, $objectKey, $body, $options);

            return;
        }

        self::putViaFallbackDisks($asset, $objectKey, $body, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function putViaBucket(Asset $asset, string $key, string $body, array $options): void
    {
        $bucketName = $asset->storageBucket->name;
        $diskName = $asset->storage_disk ?? null;
        if (is_string($diskName) && $diskName !== '' && config("filesystems.disks.{$diskName}")) {
            try {
                Storage::disk($diskName)->put($key, $body, $options);

                return;
            } catch (\Throwable $e) {
                Log::error('Storage put failed', [
                    'error' => $e->getMessage(),
                    'asset_id' => $asset->id,
                    'disk' => $diskName,
                    'path' => $key,
                ]);

                throw new \InvalidArgumentException('Failed to write image to storage.', 0, $e);
            }
        }

        $defaultBucket = config('filesystems.disks.s3.bucket');
        try {
            if ($defaultBucket !== null && $defaultBucket !== '' && $bucketName === $defaultBucket) {
                Storage::disk('s3')->put($key, $body, $options);
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
                Storage::build($config)->put($key, $body, $options);
            }
        } catch (\Throwable $e) {
            Log::error('Storage put failed', [
                'error' => $e->getMessage(),
                'asset_id' => $asset->id,
                'bucket' => $bucketName,
                'path' => $key,
            ]);

            throw new \InvalidArgumentException('Failed to write image to storage.', 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function putViaFallbackDisks(Asset $asset, string $key, string $body, array $options): void
    {
        $diskName = self::resolveFallbackDiskForObjectKey($asset, $key);
        if ($diskName === null) {
            throw new \InvalidArgumentException(
                'Could not resolve storage disk for write (asset has no storage bucket; no disk had this key).',
            );
        }
        try {
            Storage::disk($diskName)->put($key, $body, $options);
        } catch (\Throwable $e) {
            Log::error('[EditorAssetOriginalBytesLoader] fallback disk put failed', [
                'asset_id' => $asset->id,
                'disk' => $diskName,
                'path' => $key,
                'error' => $e->getMessage(),
            ]);

            throw new \InvalidArgumentException('Failed to write image to storage.', 0, $e);
        }
    }
}
