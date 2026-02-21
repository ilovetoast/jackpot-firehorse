<?php

namespace App\Jobs;

use App\Events\AssetDeleted;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\DeletionError;
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

        try {
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

            // Clean up any existing deletion errors for this asset (successful deletion)
            DeletionError::where('asset_id', $asset->id)
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => now(),
                    'resolution_notes' => 'Asset successfully deleted',
                ]);
                
        } catch (\Throwable $e) {
            // Record detailed error information for user presentation
            $this->recordDeletionError($asset, $e);
            
            // Re-throw to trigger job failure handling
            throw $e;
        }
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
            $errorType = $this->categorizeS3Error($e);
            
            Log::error('Failed to verify asset storage before deletion', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'error_type' => $errorType,
                'aws_error_code' => $e->getAwsErrorCode(),
            ]);
            
            throw new \RuntimeException("Failed to verify storage: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Delete all files and folders from S3.
     *
     * For canonical paths (tenants/{uuid}/assets/{asset_uuid}/...), deletes the full asset prefix
     * so all versions (v1, v2, ...) and thumbnails are removed. Never overwrites; each version
     * lives in its own v{n}/ directory.
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
            $tenant = $asset->tenant;

            // Canonical path: tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/...
            // Delete full asset prefix to remove ALL versions (v1, v2, ...) and thumbnails
            if ($tenant && $tenant->uuid && str_starts_with($asset->storage_root_path ?? '', "tenants/{$tenant->uuid}/assets/{$asset->id}/")) {
                $assetPrefix = "tenants/{$tenant->uuid}/assets/{$asset->id}/";
                $deletedPaths = $this->deleteAllObjectsUnderPrefix($s3Client, $bucket->name, $assetPrefix);
            } else {
                // Legacy path: delete main file, thumbnails from metadata, and folder
                $metadata = $asset->metadata ?? [];
                $mainPath = $asset->storage_root_path;
                if ($mainPath && $s3Client->doesObjectExist($bucket->name, $mainPath)) {
                    $s3Client->deleteObject([
                        'Bucket' => $bucket->name,
                        'Key' => $mainPath,
                    ]);
                    $deletedPaths[] = $mainPath;
                }

                foreach (($metadata['thumbnails'] ?? []) as $thumbnail) {
                    $thumbnailPath = $thumbnail['path'] ?? null;
                    if ($thumbnailPath && $s3Client->doesObjectExist($bucket->name, $thumbnailPath)) {
                        $s3Client->deleteObject(['Bucket' => $bucket->name, 'Key' => $thumbnailPath]);
                        $deletedPaths[] = $thumbnailPath;
                    }
                }

                $preview = $metadata['preview'] ?? null;
                if ($preview && isset($preview['path'])) {
                    $previewPath = $preview['path'];
                    if ($s3Client->doesObjectExist($bucket->name, $previewPath)) {
                        $s3Client->deleteObject(['Bucket' => $bucket->name, 'Key' => $previewPath]);
                        $deletedPaths[] = $previewPath;
                    }
                }

                $folderPath = dirname($asset->storage_root_path ?? '');
                if ($folderPath !== '.' && $folderPath !== '/') {
                    $folderDeleted = $this->deleteAllObjectsUnderPrefix($s3Client, $bucket->name, $folderPath . '/');
                    $deletedPaths = array_merge($deletedPaths, $folderDeleted);
                }
            }

            Log::info('Asset storage files deleted', [
                'asset_id' => $asset->id,
                'deleted_paths_count' => count($deletedPaths),
            ]);

            return $deletedPaths;
        } catch (S3Exception $e) {
            $errorType = $this->categorizeS3Error($e);
            
            Log::error('Failed to delete asset storage files', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'error_type' => $errorType,
                'aws_error_code' => $e->getAwsErrorCode(),
                'deleted_paths_partial' => $deletedPaths,
            ]);
            
            throw new \RuntimeException("Failed to delete storage files: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * List and delete all S3 objects under a prefix (handles pagination).
     *
     * @return array Deleted object keys
     */
    protected function deleteAllObjectsUnderPrefix(S3Client $s3Client, string $bucketName, string $prefix): array
    {
        $deletedPaths = [];
        $continuationToken = null;

        do {
            $params = ['Bucket' => $bucketName, 'Prefix' => $prefix];
            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }
            $result = $s3Client->listObjectsV2($params);
            $contents = $result['Contents'] ?? [];

            foreach ($contents as $object) {
                $key = $object['Key'] ?? null;
                if ($key) {
                    $s3Client->deleteObject(['Bucket' => $bucketName, 'Key' => $key]);
                    $deletedPaths[] = $key;
                }
            }

            $continuationToken = $result['IsTruncated'] ? ($result['NextContinuationToken'] ?? null) : null;
        } while ($continuationToken);

        return $deletedPaths;
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

        // Endpoint for MinIO/local S3; credentials via SDK default chain
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
            // Record structured error for user presentation
            $this->recordDeletionError($asset, $exception);

            // Log failure but don't change asset status (already DELETED)
            Log::error('Asset hard deletion failed - final attempt', [
                'asset_id' => $asset->id,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
                'max_attempts' => $this->tries,
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
                    'error_type' => $this->categorizeError($exception),
                    'attempts' => $this->attempts(),
                    'final_failure' => true,
                ],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Record deletion error for user presentation and tracking.
     */
    protected function recordDeletionError(Asset $asset, \Throwable $exception): void
    {
        $errorType = $this->categorizeError($exception);
        $errorDetails = [
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace_summary' => array_slice($exception->getTrace(), 0, 3), // First 3 trace entries
        ];

        // Add AWS-specific details if it's an S3Exception
        if ($exception instanceof S3Exception) {
            $errorDetails['aws_error_code'] = $exception->getAwsErrorCode();
            $errorDetails['aws_error_type'] = $exception->getAwsErrorType();
            $errorDetails['status_code'] = $exception->getStatusCode();
        }

        // Find existing error or create new one
        $existingError = DeletionError::where('asset_id', $asset->id)
            ->where('error_type', $errorType)
            ->whereNull('resolved_at')
            ->first();

        if ($existingError) {
            // Update existing error with new attempt
            $existingError->update([
                'attempts' => $this->attempts(),
                'error_message' => $exception->getMessage(),
                'error_details' => $errorDetails,
                'updated_at' => now(),
            ]);
        } else {
            // Create new error record
            DeletionError::create([
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'original_filename' => $asset->original_filename,
                'deletion_type' => 'hard',
                'error_type' => $errorType,
                'error_message' => $exception->getMessage(),
                'error_details' => $errorDetails,
                'attempts' => $this->attempts(),
            ]);
        }
    }

    /**
     * Categorize error for better user presentation.
     */
    protected function categorizeError(\Throwable $exception): string
    {
        if ($exception instanceof S3Exception) {
            return $this->categorizeS3Error($exception);
        }

        // Check error message patterns for other exceptions
        $message = strtolower($exception->getMessage());
        
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }
        
        if (str_contains($message, 'permission') || str_contains($message, 'access denied')) {
            return 'permission_denied';
        }
        
        if (str_contains($message, 'network') || str_contains($message, 'connection')) {
            return 'network_error';
        }
        
        if (str_contains($message, 'database') || str_contains($message, 'sql')) {
            return 'database_deletion_failed';
        }

        return 'unknown_error';
    }

    /**
     * Categorize S3 errors for better user presentation.
     */
    protected function categorizeS3Error(S3Exception $exception): string
    {
        $awsErrorCode = $exception->getAwsErrorCode();
        $statusCode = $exception->getStatusCode();

        return match($awsErrorCode) {
            'AccessDenied', 'Forbidden' => 'permission_denied',
            'NoSuchBucket', 'NoSuchKey' => 'storage_verification_failed',
            'RequestTimeout', 'ServiceUnavailable' => 'timeout',
            'NetworkingError' => 'network_error',
            default => match($statusCode) {
                403 => 'permission_denied',
                404 => 'storage_verification_failed',
                408, 503, 504 => 'timeout',
                500, 502 => 'network_error',
                default => 'storage_deletion_failed'
            }
        };
    }
}
