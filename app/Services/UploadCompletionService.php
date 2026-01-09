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
     * @param string|null $originalFilename Optional original filename (from client)
     * @param string|null $s3Key Optional S3 key if known, otherwise will be determined
     * @return Asset
     * @throws \Exception
     */
    public function complete(
        UploadSession $uploadSession,
        ?string $assetType = null,
        ?string $originalFilename = null,
        ?string $s3Key = null
    ): Asset {
        // Verify upload session is in valid state
        if ($uploadSession->status !== UploadStatus::INITIATING && $uploadSession->status !== UploadStatus::UPLOADING) {
            throw new \RuntimeException("Upload session is in invalid state: {$uploadSession->status->value}. Cannot complete upload.");
        }

        // Get file info from S3 (never trust client metadata)
        $fileInfo = $this->getFileInfoFromS3($uploadSession, $s3Key);

        // Generate final storage path for asset
        $storagePath = $this->generateStoragePath(
            $uploadSession->tenant_id,
            $uploadSession->brand_id,
            $fileInfo['original_filename'],
            $uploadSession->id
        );

        // Determine asset type (default to ASSET if not provided)
        $assetTypeEnum = $assetType ? AssetType::from($assetType) : AssetType::ASSET;

        // Create asset (clean boundary - asset creation is separate from upload attempt)
        $asset = Asset::create([
            'tenant_id' => $uploadSession->tenant_id,
            'brand_id' => $uploadSession->brand_id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $uploadSession->storage_bucket_id,
            'status' => AssetStatus::UPLOADED, // Initial state after upload completion
            'type' => $assetTypeEnum,
            'original_filename' => $fileInfo['original_filename'],
            'mime_type' => $fileInfo['mime_type'],
            'size_bytes' => $fileInfo['size_bytes'],
            'storage_root_path' => $storagePath,
            'metadata' => [],
        ]);

        // Update upload session status and uploaded size
        $uploadSession->update([
            'status' => UploadStatus::COMPLETED,
            'uploaded_size' => $fileInfo['size_bytes'],
        ]);

        Log::info('Asset created from upload session', [
            'asset_id' => $asset->id,
            'upload_session_id' => $uploadSession->id,
            'tenant_id' => $uploadSession->tenant_id,
            'original_filename' => $asset->original_filename,
            'size_bytes' => $asset->size_bytes,
        ]);

        // Emit AssetUploaded event
        event(new AssetUploaded($asset));

        return $asset;
    }

    /**
     * Get file information from S3.
     * Never trusts client metadata - always verifies storage.
     *
     * @param UploadSession $uploadSession
     * @param string|null $s3Key Optional S3 key if known
     * @return array{original_filename: string, mime_type: string|null, size_bytes: int}
     * @throws \RuntimeException If object does not exist or verification fails
     */
    protected function getFileInfoFromS3(UploadSession $uploadSession, ?string $s3Key = null): array
    {
        $bucket = $uploadSession->storageBucket;

        // Generate expected S3 key (deterministic based on upload session)
        // This is a temporary path used during upload
        if (!$s3Key) {
            $s3Key = $this->generateTempUploadPath($uploadSession);
        }

        try {
            // Verify object exists in S3
            $exists = $this->s3Client->doesObjectExist($bucket->name, $s3Key);

            if (!$exists) {
                Log::error('Upload completion failed: object does not exist in S3', [
                    'upload_session_id' => $uploadSession->id,
                    'bucket' => $bucket->name,
                    's3_key' => $s3Key,
                ]);

                throw new \RuntimeException(
                    "Uploaded object does not exist in S3. Bucket: {$bucket->name}, Key: {$s3Key}"
                );
            }

            // Get object metadata (never trust client metadata)
            $headResult = $this->s3Client->headObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);

            $actualSize = (int) $headResult->get('ContentLength');
            $mimeType = $headResult->get('ContentType');
            $originalFilename = $this->extractFilenameFromMetadata($headResult) ?? 'unknown';

            // Verify file size matches expected size (fail loudly on mismatch)
            if ($actualSize !== $uploadSession->expected_size) {
                Log::error('Upload completion failed: file size mismatch', [
                    'upload_session_id' => $uploadSession->id,
                    'expected_size' => $uploadSession->expected_size,
                    'actual_size' => $actualSize,
                    'bucket' => $bucket->name,
                    's3_key' => $s3Key,
                ]);

                throw new \RuntimeException(
                    "File size mismatch. Expected: {$uploadSession->expected_size} bytes, Actual: {$actualSize} bytes"
                );
            }

            Log::info('S3 object verification successful', [
                'upload_session_id' => $uploadSession->id,
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
                'size_bytes' => $actualSize,
                'mime_type' => $mimeType,
            ]);

            return [
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'size_bytes' => $actualSize,
            ];
        } catch (S3Exception $e) {
            Log::error('S3 verification failed', [
                'upload_session_id' => $uploadSession->id,
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
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
     * Generate temporary upload path for S3 (used during upload).
     * This matches the path generated in UploadInitiationService.
     *
     * @param UploadSession $uploadSession
     * @return string
     */
    protected function generateTempUploadPath(UploadSession $uploadSession): string
    {
        $tenantId = $uploadSession->tenant_id;
        $brandId = $uploadSession->brand_id;
        $sessionId = $uploadSession->id;

        $basePath = "uploads/{$tenantId}";

        if ($brandId) {
            $basePath .= "/{$brandId}";
        }

        return "{$basePath}/{$sessionId}";
    }

    /**
     * Generate final storage path for asset.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param string $originalFilename
     * @param string $uploadSessionId
     * @return string
     */
    protected function generateStoragePath(
        int $tenantId,
        ?int $brandId,
        string $originalFilename,
        string $uploadSessionId
    ): string {
        $basePath = "assets/{$tenantId}";

        if ($brandId) {
            $basePath .= "/{$brandId}";
        }

        // Generate unique filename to avoid conflicts
        $uniqueFileName = \Illuminate\Support\Str::uuid()->toString() . '_' . $originalFilename;

        return "{$basePath}/{$uniqueFileName}";
    }

    /**
     * Extract filename from S3 object metadata.
     *
     * @param \Aws\Result $headResult
     * @return string|null
     */
    protected function extractFilenameFromMetadata(\Aws\Result $headResult): ?string
    {
        // Try to get filename from Content-Disposition header
        $contentDisposition = $headResult->get('ContentDisposition');
        if ($contentDisposition && preg_match('/filename[^;=\n]*=(([\'"]).*?\2|[^;\n]*)/', $contentDisposition, $matches)) {
            return trim($matches[1], '"\'');
        }

        // Try to get from metadata
        $metadata = $headResult->get('Metadata');
        if (isset($metadata['original-filename'])) {
            return $metadata['original-filename'];
        }

        return null;
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
