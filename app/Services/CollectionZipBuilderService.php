<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\StorageBucket;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Builds a ZIP file from a collection of assets (on-the-fly, no Download record).
 * Used for public collection download so we do not persist a Download.
 */
class CollectionZipBuilderService
{
    /**
     * Build a ZIP file from assets, writing to a temp file.
     *
     * @param  Collection<int, Asset>  $assets  Assets with storageBucket relation loaded
     * @return string Path to temporary ZIP file (caller must unlink when done)
     */
    public function buildZipFromAssets(Collection $assets, StorageBucket $bucket, S3Client $s3Client): string
    {
        $tempZipPath = tempnam(sys_get_temp_dir(), 'collection_zip_') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

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

                    $assetContent = $this->downloadAssetFromS3($bucket, $assetPath, $s3Client);
                    if ($assetContent === null) {
                        Log::warning('[CollectionZipBuilderService] Failed to download asset from S3, skipping', [
                            'asset_id' => $asset->id,
                            'asset_path' => $assetPath,
                        ]);
                        continue;
                    }

                    $zipFileName = $asset->original_filename ?? basename($assetPath);
                    $index = 0;
                    while ($zip->locateName($zipFileName) !== false) {
                        $index++;
                        $pathInfo = pathinfo($asset->original_filename ?? basename($assetPath));
                        $zipFileName = ($pathInfo['filename'] ?? 'file') . '_' . $index;
                        if (isset($pathInfo['extension'])) {
                            $zipFileName .= '.' . $pathInfo['extension'];
                        }
                    }

                    $zip->addFromString($zipFileName, $assetContent);
                } catch (\Throwable $e) {
                    Log::warning('[CollectionZipBuilderService] Failed to add asset to ZIP, continuing', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $zip->close();

            if (! file_exists($tempZipPath) || filesize($tempZipPath) === 0) {
                if (file_exists($tempZipPath)) {
                    @unlink($tempZipPath);
                }
                throw new \RuntimeException('ZIP file is empty or does not exist');
            }

            return $tempZipPath;
        } catch (\Throwable $e) {
            $zip->close();
            if (file_exists($tempZipPath)) {
                @unlink($tempZipPath);
            }
            throw $e;
        }
    }

    protected function downloadAssetFromS3(StorageBucket $bucket, string $assetPath, S3Client $s3Client): ?string
    {
        try {
            if (! $s3Client->doesObjectExist($bucket->name, $assetPath)) {
                return null;
            }

            $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $assetPath,
            ]);

            return (string) $result['Body'];
        } catch (S3Exception $e) {
            Log::warning('[CollectionZipBuilderService] Failed to download asset from S3', [
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

        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
            'endpoint' => config('filesystems.disks.s3.endpoint'),
            'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint', false),
        ]);
    }
}
