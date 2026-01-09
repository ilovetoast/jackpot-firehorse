<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\UploadStatus;
use App\Events\AssetUploaded;
use App\Models\Asset;
use App\Models\UploadSession;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadCompletionService
{
    /**
     * Create a new instance.
     */
    public function __construct(
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Complete an upload session and create an asset.
     *
     * @param UploadSession $uploadSession
     * @param string|null $assetType
     * @return Asset
     * @throws \Exception
     */
    public function complete(UploadSession $uploadSession, ?string $assetType = null): Asset
    {
        // Verify upload session is in valid state
        if ($uploadSession->status !== UploadStatus::INITIATING && $uploadSession->status !== UploadStatus::UPLOADING) {
            throw new \RuntimeException("Upload session is in invalid state: {$uploadSession->status->value}. Cannot complete upload.");
        }

        // Verify object exists in S3
        $this->verifyObjectExists($uploadSession);

        // Determine asset type (default to ASSET if not provided)
        $assetTypeEnum = $assetType ? AssetType::from($assetType) : AssetType::ASSET;

        // Create asset
        $asset = Asset::create([
            'tenant_id' => $uploadSession->tenant_id,
            'brand_id' => $uploadSession->brand_id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $uploadSession->storage_bucket_id,
            'status' => AssetStatus::PENDING, // Initial state after upload
            'type' => $assetTypeEnum,
            'file_name' => $uploadSession->file_name,
            'file_size' => $uploadSession->file_size,
            'mime_type' => $uploadSession->mime_type,
            'path' => $uploadSession->path,
            'metadata' => $uploadSession->metadata ?? [],
        ]);

        // Update upload session status
        $uploadSession->update([
            'status' => UploadStatus::COMPLETED,
        ]);

        Log::info('Asset created from upload session', [
            'asset_id' => $asset->id,
            'upload_session_id' => $uploadSession->id,
            'tenant_id' => $uploadSession->tenant_id,
            'file_name' => $uploadSession->file_name,
            'file_size' => $uploadSession->file_size,
        ]);

        // Emit AssetUploaded event
        event(new AssetUploaded($asset));

        return $asset;
    }

    /**
     * Verify that the uploaded object exists in S3.
     *
     * This method never trusts client metadata - it always verifies storage.
     *
     * @param UploadSession $uploadSession
     * @return void
     * @throws \RuntimeException If object does not exist or verification fails
     */
    protected function verifyObjectExists(UploadSession $uploadSession): void
    {
        $bucket = $uploadSession->storageBucket;
        $path = $uploadSession->path;

        try {
            // Use S3 client to check object existence
            $exists = $this->s3Client->doesObjectExist($bucket->name, $path);

            if (!$exists) {
                Log::error('Upload completion failed: object does not exist in S3', [
                    'upload_session_id' => $uploadSession->id,
                    'bucket' => $bucket->name,
                    'path' => $path,
                ]);

                throw new \RuntimeException(
                    "Uploaded object does not exist in S3. Bucket: {$bucket->name}, Path: {$path}"
                );
            }

            // Get object metadata to verify size (never trust client metadata)
            $headResult = $this->s3Client->headObject([
                'Bucket' => $bucket->name,
                'Key' => $path,
            ]);

            $actualSize = $headResult->get('ContentLength');

            // Verify file size matches expected size (fail loudly on mismatch)
            if ($actualSize !== $uploadSession->file_size) {
                Log::error('Upload completion failed: file size mismatch', [
                    'upload_session_id' => $uploadSession->id,
                    'expected_size' => $uploadSession->file_size,
                    'actual_size' => $actualSize,
                    'bucket' => $bucket->name,
                    'path' => $path,
                ]);

                throw new \RuntimeException(
                    "File size mismatch. Expected: {$uploadSession->file_size} bytes, Actual: {$actualSize} bytes"
                );
            }

            Log::info('S3 object verification successful', [
                'upload_session_id' => $uploadSession->id,
                'bucket' => $bucket->name,
                'path' => $path,
                'file_size' => $actualSize,
            ]);
        } catch (S3Exception $e) {
            Log::error('S3 verification failed', [
                'upload_session_id' => $uploadSession->id,
                'bucket' => $bucket->name,
                'path' => $path,
                'error' => $e->getMessage(),
                'code' => $e->getAwsErrorCode(),
            ]);

            throw new \RuntimeException(
                "Failed to verify uploaded object in S3: {$e->getMessage()}",
                0,
                $e
            );
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
                'AWS SDK for PHP is required for upload completion. ' .
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
}
