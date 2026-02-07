<?php

namespace App\Services;

use App\Models\Download;
use App\Services\TenantBucketService;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use ZipStream\ZipStream;

/**
 * Phase D-4: Streams ZIP directly to response without temp files or disk writes.
 *
 * Uses ZipStream to stream assets from S3 into a ZIP. No BuildDownloadZipJob.
 * Enable via config('features.streaming_downloads') when total_bytes > threshold.
 * Bucket resolved via TenantBucketService::resolveActiveBucketOrFail (never config).
 */
class StreamingZipService
{
    protected const DEFAULT_FILENAME = 'download.zip';

    public function __construct() {}

    /**
     * Stream download assets as ZIP to output.
     * No temp file; reads from S3 and streams directly.
     */
    public function stream(Download $download, string $outputFilename = self::DEFAULT_FILENAME): void
    {
        $assets = $download->assets()->orderBy('assets.id')->get();
        if ($assets->isEmpty()) {
            throw new \RuntimeException('Download has no assets');
        }

        $bucketService = app(TenantBucketService::class);
        $bucket = $bucketService->resolveActiveBucketOrFail($download->tenant);

        $s3Client = $this->createS3Client();
        $usedNames = [];

        $zip = new ZipStream(
            outputName: $outputFilename,
            sendHttpHeaders: false,
        );

        foreach ($assets as $asset) {
            $assetPath = $asset->storage_root_path ?? $asset->path ?? null;
            if (! $assetPath) {
                Log::warning('[StreamingZipService] Asset missing storage path, skipping', [
                    'asset_id' => $asset->id,
                    'download_id' => $download->id,
                ]);
                continue;
            }

            if (! $s3Client->doesObjectExist($bucket->name, $assetPath)) {
                Log::warning('[StreamingZipService] Asset file does not exist in S3', [
                    'bucket' => $bucket->name,
                    'asset_path' => $assetPath,
                ]);
                continue;
            }

            $zipFileName = $asset->original_filename ?? basename($assetPath);
            $zipFileName = $this->uniqueFileName($zipFileName, $usedNames);
            $usedNames[] = $zipFileName;

            try {
                $result = $s3Client->getObject([
                    'Bucket' => $bucket->name,
                    'Key' => $assetPath,
                ]);
                $body = $result['Body'];
                if ($body instanceof \Psr\Http\Message\StreamInterface) {
                    $zip->addFileFromPsr7Stream(
                        fileName: $zipFileName,
                        stream: $body,
                    );
                } else {
                    $zip->addFile(fileName: $zipFileName, data: (string) $body);
                }
            } catch (\Throwable $e) {
                Log::error('[StreamingZipService] Failed to stream asset from S3', [
                    'asset_id' => $asset->id,
                    'asset_path' => $assetPath,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        $zip->finish();
    }

    protected function uniqueFileName(string $base, array $used): string
    {
        $name = $base;
        $i = 0;
        while (in_array($name, $used, true)) {
            $i++;
            $pathInfo = pathinfo($base);
            $name = ($pathInfo['filename'] ?? 'file') . '_' . $i;
            if (isset($pathInfo['extension'])) {
                $name .= '.' . $pathInfo['extension'];
            }
        }
        return $name;
    }

    protected function createS3Client(): S3Client
    {
        if (app()->bound(S3Client::class)) {
            return app(S3Client::class);
        }

        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
        ]);
    }
}
