<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Collection as CollectionModel;
use App\Models\StorageBucket;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

/**
 * Builds ZIP files from collections of assets.
 *
 * Supports two modes:
 * 1. On-the-fly (temp file, caller deletes after send)
 * 2. Cached in S3 (built once, served via signed URL, invalidated on asset changes)
 *
 * The cached mode stores the ZIP in the tenant's bucket under a deterministic key.
 * When collection assets change, Collection::invalidatePublicZip() clears the
 * cache columns and the old S3 object is cleaned up on next build.
 *
 * Large collections: each S3 object is streamed to a temp file and added with
 * ZipArchive::addFile (not addFromString) so PHP does not load every object into
 * memory — avoids OOM and generic 500s on multi‑GB public collection ZIPs.
 */
class CollectionZipBuilderService
{
    private const STREAM_CHUNK_BYTES = 8 * 1024 * 1024;

    /**
     * Directory for tempnam() scratch files (ZIP + per-object streams). Falls back to system temp.
     */
    protected function writableTempDirectory(): string
    {
        $configured = (string) config('collection_zip.temp_directory', '');
        $configured = $configured !== '' ? rtrim($configured, '/\\') : '';
        if ($configured !== '' && is_dir($configured) && is_writable($configured)) {
            return $configured;
        }

        return sys_get_temp_dir();
    }

    /**
     * Build a ZIP file from assets, writing to a temp file.
     *
     * @param  Collection<int, Asset>  $assets  Assets with storageBucket relation loaded
     * @return string Path to temporary ZIP file (caller must unlink when done)
     */
    public function buildZipFromAssets(Collection $assets, StorageBucket $bucket, S3Client $s3Client): string
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $tempBase = $this->writableTempDirectory();
        $tempZipPath = tempnam($tempBase, 'collection_zip_').'.zip';

        $zip = new ZipArchive;
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

        $scratchFiles = [];

        try {
            foreach ($assets as $asset) {
                try {
                    $assetPath = $asset->storage_root_path ?? $asset->path;
                    if (! $assetPath) {
                        Log::warning('[CollectionZipBuilderService] Asset missing storage path, skipping', [
                            'asset_id' => $asset->id,
                        ]);

                        continue;
                    }

                    $localPath = $this->streamS3ObjectToTempFile($bucket, $assetPath, $s3Client);
                    if ($localPath === null) {
                        Log::warning('[CollectionZipBuilderService] Failed to download asset from S3, skipping', [
                            'asset_id' => $asset->id,
                            'asset_path' => $assetPath,
                        ]);

                        continue;
                    }

                    $scratchFiles[] = $localPath;

                    $zipFileName = $asset->original_filename ?? basename($assetPath);
                    $index = 0;
                    while ($zip->locateName($zipFileName) !== false) {
                        $index++;
                        $pathInfo = pathinfo($asset->original_filename ?? basename($assetPath));
                        $zipFileName = ($pathInfo['filename'] ?? 'file').'_'.$index;
                        if (isset($pathInfo['extension'])) {
                            $zipFileName .= '.'.$pathInfo['extension'];
                        }
                    }

                    if (! $zip->addFile($localPath, $zipFileName)) {
                        Log::warning('[CollectionZipBuilderService] addFile failed, skipping asset', [
                            'asset_id' => $asset->id,
                            'local_path' => $localPath,
                        ]);

                        continue;
                    }

                    // Avoid re-compressing JPEG/RAW/etc.; faster builds and lower CPU for huge archives.
                    if (defined('ZipArchive::CM_STORE')) {
                        $zip->setCompressionName($zipFileName, ZipArchive::CM_STORE);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[CollectionZipBuilderService] Failed to add asset to ZIP, continuing', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $zip->close()) {
                throw new \RuntimeException('Failed to finalize ZIP archive');
            }

            foreach ($scratchFiles as $path) {
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    @unlink($path);
                }
            }
            $scratchFiles = [];

            if (! file_exists($tempZipPath) || filesize($tempZipPath) === 0) {
                if (file_exists($tempZipPath)) {
                    @unlink($tempZipPath);
                }
                throw new \RuntimeException('ZIP file is empty or does not exist');
            }

            return $tempZipPath;
        } catch (\Throwable $e) {
            foreach ($scratchFiles as $path) {
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    @unlink($path);
                }
            }
            try {
                $zip->close();
            } catch (\Throwable) {
                // ignore
            }
            if (file_exists($tempZipPath)) {
                @unlink($tempZipPath);
            }
            throw $e;
        }
    }

    /**
     * Get or build a cached ZIP for a public collection, stored in S3.
     *
     * Strategy: lazy rebuild on first request after invalidation.
     * - If cached ZIP exists in S3 and collection record has a path → return S3 key.
     * - Otherwise build locally, upload to S3, update collection record, return S3 key.
     * - Old ZIP objects are overwritten at the same key (deterministic path).
     *
     * @return string S3 object key of the cached ZIP
     */
    public function getOrBuildCachedZip(
        CollectionModel $collection,
        Collection $assets,
        StorageBucket $bucket,
        S3Client $s3Client
    ): string {
        $s3Key = $this->cachedZipS3Key($collection);

        if ($collection->hasPublicZipCached()) {
            if ($this->s3ObjectExists($bucket, $s3Key, $s3Client)) {
                return $s3Key;
            }
            Log::info('[CollectionZipBuilderService] Cached ZIP missing from S3, rebuilding', [
                'collection_id' => $collection->id,
                's3_key' => $s3Key,
            ]);
        }

        $tempPath = $this->buildZipFromAssets($assets, $bucket, $s3Client);

        try {
            $s3Client->putObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
                'SourceFile' => $tempPath,
                'ContentType' => 'application/zip',
                'CacheControl' => 'private, max-age=86400',
            ]);

            $collection->update([
                'public_zip_path' => $s3Key,
                'public_zip_built_at' => now(),
                'public_zip_asset_count' => $assets->count(),
            ]);

            Log::info('[CollectionZipBuilderService] Cached ZIP built and uploaded', [
                'collection_id' => $collection->id,
                's3_key' => $s3Key,
                'asset_count' => $assets->count(),
            ]);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        return $s3Key;
    }

    /**
     * Generate a signed download URL for the cached collection ZIP.
     */
    public function getSignedZipUrl(StorageBucket $bucket, string $s3Key, S3Client $s3Client, int $ttlMinutes = 30): string
    {
        $cmd = $s3Client->getCommand('GetObject', [
            'Bucket' => $bucket->name,
            'Key' => $s3Key,
            'ResponseContentType' => 'application/zip',
        ]);

        return (string) $s3Client->createPresignedRequest($cmd, "+{$ttlMinutes} minutes")->getUri();
    }

    /**
     * Delete the cached ZIP from S3 (e.g. when collection is deleted or made private).
     */
    public function deleteCachedZip(CollectionModel $collection, StorageBucket $bucket, S3Client $s3Client): void
    {
        $s3Key = $collection->public_zip_path ?? $this->cachedZipS3Key($collection);

        try {
            $s3Client->deleteObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CollectionZipBuilderService] Failed to delete cached ZIP from S3', [
                'collection_id' => $collection->id,
                's3_key' => $s3Key,
                'error' => $e->getMessage(),
            ]);
        }

        $collection->invalidatePublicZip();
    }

    /**
     * Deterministic S3 key for collection ZIP. Overwrites on rebuild.
     */
    protected function cachedZipS3Key(CollectionModel $collection): string
    {
        return "_system/collection-zips/{$collection->id}/collection-download.zip";
    }

    protected function s3ObjectExists(StorageBucket $bucket, string $key, S3Client $s3Client): bool
    {
        try {
            return $s3Client->doesObjectExist($bucket->name, $key);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Stream an S3 object to a temp file (bounded RAM). Caller must unlink the path.
     */
    protected function streamS3ObjectToTempFile(StorageBucket $bucket, string $assetPath, S3Client $s3Client): ?string
    {
        try {
            if (! $s3Client->doesObjectExist($bucket->name, $assetPath)) {
                return null;
            }

            $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $assetPath,
            ]);

            $tmpPath = tempnam($this->writableTempDirectory(), 'zip_part_');
            if ($tmpPath === false) {
                return null;
            }

            $out = fopen($tmpPath, 'wb');
            if ($out === false) {
                @unlink($tmpPath);

                return null;
            }

            $body = $result['Body'] ?? null;
            try {
                if ($body instanceof StreamInterface) {
                    while (! $body->eof()) {
                        $chunk = $body->read(self::STREAM_CHUNK_BYTES);
                        if ($chunk === '' || $chunk === false) {
                            break;
                        }
                        if (fwrite($out, $chunk) === false) {
                            throw new \RuntimeException('Failed writing S3 stream to temp file');
                        }
                    }
                } elseif (is_string($body)) {
                    if (fwrite($out, $body) === false) {
                        throw new \RuntimeException('Failed writing S3 body string to temp file');
                    }
                } else {
                    fclose($out);
                    @unlink($tmpPath);

                    return null;
                }
            } finally {
                if ($body instanceof StreamInterface) {
                    $body->close();
                }
            }

            fclose($out);

            return $tmpPath;
        } catch (S3Exception $e) {
            Log::warning('[CollectionZipBuilderService] Failed to stream asset from S3', [
                'bucket' => $bucket->name,
                'asset_path' => $assetPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function createS3Client(): S3Client
    {
        if (app()->bound(S3Client::class)) {
            return app(S3Client::class);
        }

        $config = [
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
        ];
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }
}
