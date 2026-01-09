<?php

namespace App\Jobs;

use App\Events\AssetDeleted;
use App\Models\Asset;
use App\Models\AssetEvent;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [60, 300, 900]; // 1 minute, 5 minutes, 15 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Find asset including soft-deleted
        $asset = Asset::withTrashed()->findOrFail($this->assetId);

        // Idempotency: Check if asset was already permanently deleted
        if (!$asset->trashed()) {
            Log::info('Asset hard deletion skipped - asset is not soft-deleted', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Verify storage - check if files exist in S3
        $this->verifyStorage($asset);

        // Delete all files and folders from S3
        $deletedPaths = $this->deleteStorageFiles($asset);

        // Confirm removal - verify files are gone
        $this->confirmRemoval($asset, $deletedPaths);

        // Emit deletion event before database deletion
        event(new AssetDeleted($asset, $deletedPaths));

        // Emit asset deleted event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null, // System event
            'event_type' => 'asset.hard_deleted',
                'metadata' => [
                    'deletion_type' => 'hard',
                    'deleted_paths' => $deletedPaths,
                    'original_filename' => $asset->original_filename,
                ],
            'created_at' => now(),
        ]);

        Log::info('Asset permanently deleted', [
            'asset_id' => $asset->id,
            'file_name' => $asset->file_name,
            'deleted_paths' => $deletedPaths,
        ]);

        // Permanently delete asset from database
        $asset->forceDelete();
    }

    /**
     * Verify storage before deletion.
     *
     * @param Asset $asset
     * @return void
     * @throws \RuntimeException If verification fails
     */
    protected function verifyStorage(Asset $asset): void
    {
        $bucket = $asset->storageBucket;
        $s3Client = $this->createS3Client();

        try {
            // Verify main file exists
            $exists = $s3Client->doesObjectExist($bucket->name, $asset->storage_root_path);
            if (!$exists) {
                Log::warning('Asset file not found in storage during deletion', [
                    'asset_id' => $asset->id,
                    'storage_root_path' => $asset->storage_root_path,
                    'bucket' => $bucket->name,
                ]);
                // Continue deletion even if main file doesn't exist (idempotent)
            }
        } catch (S3Exception $e) {
            Log::error('Failed to verify asset storage before deletion', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to verify storage: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Delete all files and folders from S3.
     *
     * @param Asset $asset
     * @return array Array of deleted paths
     * @throws \RuntimeException If deletion fails
     */
    protected function deleteStorageFiles(Asset $asset): array
    {
        $bucket = $asset->storageBucket;
        $s3Client = $this->createS3Client();
        $deletedPaths = [];

        try {
            // Get asset metadata to find all related files
            $metadata = $asset->metadata ?? [];
            
            // Delete main asset file
            $mainPath = $asset->storage_root_path;
            if ($s3Client->doesObjectExist($bucket->name, $mainPath)) {
                $s3Client->deleteObject([
                    'Bucket' => $bucket->name,
                    'Key' => $mainPath,
                ]);
                $deletedPaths[] = $mainPath;
            }

            // Delete thumbnails if they exist
            $thumbnails = $metadata['thumbnails'] ?? [];
            foreach ($thumbnails as $thumbnail) {
                $thumbnailPath = $thumbnail['path'] ?? null;
                if ($thumbnailPath && $s3Client->doesObjectExist($bucket->name, $thumbnailPath)) {
                    $s3Client->deleteObject([
                        'Bucket' => $bucket->name,
                        'Key' => $thumbnailPath,
                    ]);
                    $deletedPaths[] = $thumbnailPath;
                }
            }

            // Delete preview if it exists
            $preview = $metadata['preview'] ?? null;
            if ($preview && isset($preview['path'])) {
                $previewPath = $preview['path'];
                if ($s3Client->doesObjectExist($bucket->name, $previewPath)) {
                    $s3Client->deleteObject([
                        'Bucket' => $bucket->name,
                        'Key' => $previewPath,
                    ]);
                    $deletedPaths[] = $previewPath;
                }
            }

            // Delete asset folder (if structured as folders)
            // Extract folder path from asset storage path
            $folderPath = dirname($asset->storage_root_path);
            if ($folderPath !== '.' && $folderPath !== '/') {
                // List and delete all objects in folder
                $objects = $s3Client->listObjectsV2([
                    'Bucket' => $bucket->name,
                    'Prefix' => $folderPath . '/',
                ]);

                if (isset($objects['Contents'])) {
                    foreach ($objects['Contents'] as $object) {
                        $objectPath = $object['Key'];
                        if ($s3Client->doesObjectExist($bucket->name, $objectPath)) {
                            $s3Client->deleteObject([
                                'Bucket' => $bucket->name,
                                'Key' => $objectPath,
                            ]);
                            $deletedPaths[] = $objectPath;
                        }
                    }
                }
            }

            Log::info('Asset storage files deleted', [
                'asset_id' => $asset->id,
                'deleted_paths_count' => count($deletedPaths),
            ]);

            return $deletedPaths;
        } catch (S3Exception $e) {
            Log::error('Failed to delete asset storage files', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'code' => $e->getAwsErrorCode(),
            ]);
            throw new \RuntimeException("Failed to delete storage files: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Confirm removal - verify files are gone from S3 (best-effort).
     * Does not throw exceptions - logs warnings instead.
     *
     * @param Asset $asset
     * @param array $deletedPaths
     * @return void
     */
    protected function confirmRemoval(Asset $asset, array $deletedPaths): void
    {
        $bucket = $asset->storageBucket;
        $s3Client = $this->createS3Client();

        try {
            // Verify main file is gone (best-effort)
            $mainPath = $asset->storage_root_path;
            $stillExists = $s3Client->doesObjectExist($bucket->name, $mainPath);

            if ($stillExists) {
                Log::warning('Asset file still exists after deletion attempt (best-effort verification)', [
                    'asset_id' => $asset->id,
                    'path' => $mainPath,
                    'note' => 'Deletion will proceed - file may be deleted asynchronously or verification may be delayed',
                ]);
                // Don't throw - best-effort verification
                return;
            }

            Log::info('Asset removal confirmed', [
                'asset_id' => $asset->id,
                'deleted_paths_count' => count($deletedPaths),
            ]);
        } catch (S3Exception $e) {
            Log::warning('Failed to confirm asset removal (best-effort verification)', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'note' => 'Deletion will proceed - verification failure is non-critical',
            ]);
            // Don't throw - best-effort verification
        } catch (\Exception $e) {
            Log::warning('Unexpected error during asset removal verification (best-effort)', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'note' => 'Deletion will proceed - verification failure is non-critical',
            ]);
            // Don't throw - best-effort verification
        }
    }

    /**
     * Create S3 client instance.
     *
     * @return S3Client
     * @throws \RuntimeException
     */
    protected function createS3Client(): S3Client
    {
        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException(
                'AWS SDK for PHP is required for asset deletion. ' .
                'Install it via: composer require aws/aws-sdk-php'
            );
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        // Add credentials if provided
        if (config('filesystems.disks.s3.key') && config('filesystems.disks.s3.secret')) {
            $config['credentials'] = [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ];
        }

        // Add endpoint for MinIO/local S3
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::withTrashed()->find($this->assetId);

        if ($asset) {
            // Log failure but don't change asset status (already DELETED)
            Log::error('Asset hard deletion failed', [
                'asset_id' => $asset->id,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            // Emit deletion failed event
            AssetEvent::create([
                'tenant_id' => $asset->tenant_id,
                'brand_id' => $asset->brand_id,
                'asset_id' => $asset->id,
                'user_id' => null,
                'event_type' => 'asset.hard_deletion.failed',
                'metadata' => [
                    'job' => 'DeleteAssetJob',
                    'error' => $exception->getMessage(),
                    'attempts' => $this->attempts(),
                ],
                'created_at' => now(),
            ]);
        }
    }
}
