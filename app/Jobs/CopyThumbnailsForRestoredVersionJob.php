<?php

namespace App\Jobs;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Copy thumbnails from a source version to a newly restored version path.
 *
 * Runs on the worker (which has S3 ListBucket permission). The web server
 * typically has restricted S3 permissions (e.g. GetObject/PutObject only),
 * so listObjectsV2 fails with 403 when restore is triggered from web.
 */
class CopyThumbnailsForRestoredVersionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public function __construct(
        public string $assetId,
        public string $sourceVersionId,
        public string $newVersionId
    ) {}

    public function handle(): void
    {
        $asset = Asset::find($this->assetId);
        $sourceVersion = AssetVersion::withTrashed()->find($this->sourceVersionId);
        $newVersion = AssetVersion::find($this->newVersionId);

        if (!$asset || !$sourceVersion || !$newVersion) {
            Log::warning('[CopyThumbnailsForRestoredVersionJob] Missing asset or version', [
                'asset_id' => $this->assetId,
                'source_version_id' => $this->sourceVersionId,
                'new_version_id' => $this->newVersionId,
            ]);
            return;
        }

        $bucket = $asset->storageBucket;
        if (!$bucket) {
            Log::warning('[CopyThumbnailsForRestoredVersionJob] Asset has no storage bucket', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        $sourceBase = dirname($sourceVersion->file_path);
        $newBase = dirname($newVersion->file_path);

        if ($sourceBase === $newBase) {
            return;
        }

        $thumbnailsPrefix = $sourceBase . '/thumbnails/';
        $s3Client = $this->createS3Client();

        $files = [];
        $continuationToken = null;
        do {
            $params = ['Bucket' => $bucket->name, 'Prefix' => $thumbnailsPrefix];
            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }
            $result = $s3Client->listObjectsV2($params);
            $contents = $result['Contents'] ?? [];
            foreach ($contents as $obj) {
                $key = $obj['Key'] ?? null;
                if ($key) {
                    $files[] = $key;
                }
            }
            $continuationToken = $result['IsTruncated'] ? ($result['NextContinuationToken'] ?? null) : null;
        } while ($continuationToken);

        if (empty($files)) {
            return;
        }

        $copiedThumbnails = [];
        $copiedPreview = [];

        foreach ($files as $path) {
            $relPath = substr($path, strlen($thumbnailsPrefix));
            $parts = explode('/', $relPath, 2);
            $style = $parts[0] ?? null;
            if (!$style) {
                continue;
            }
            $newPath = $newBase . '/thumbnails/' . $relPath;

            try {
                $s3Client->copyObject([
                    'Bucket' => $bucket->name,
                    'CopySource' => rawurlencode($bucket->name . '/' . $path),
                    'Key' => $newPath,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[CopyThumbnailsForRestoredVersionJob] Failed to copy thumbnail', [
                    'asset_id' => $asset->id,
                    'source' => $path,
                    'dest' => $newPath,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $entry = ['path' => $newPath];
            if ($style === 'preview') {
                $copiedPreview[$style] = $entry;
            } else {
                $copiedThumbnails[$style] = $entry;
            }
        }

        if (!empty($copiedThumbnails) || !empty($copiedPreview)) {
            $meta = $asset->metadata ?? [];
            $meta['thumbnails'] = $copiedThumbnails;
            $meta['preview_thumbnails'] = $copiedPreview;
            $meta['thumbnails_generated'] = true;
            $meta['thumbnails_generated_at'] = now()->toIso8601String();
            $asset->update([
                'metadata' => $meta,
                'thumbnail_status' => ThumbnailStatus::COMPLETED,
            ]);
        }
    }

    protected function createS3Client(): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region', 'us-east-1'),
        ];
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }
        return new S3Client($config);
    }
}
