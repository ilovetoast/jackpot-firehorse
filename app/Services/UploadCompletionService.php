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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service for completing upload sessions and creating assets.
 *
 * TEMPORARY UPLOAD PATH CONTRACT (IMMUTABLE):
 *
 * All temporary upload objects must live at:
 *   temp/uploads/{upload_session_id}/original
 *
 * This path format is:
 *   - Deterministic: Same upload_session_id always produces the same path
 *   - Never reused: Each upload_session_id is a unique UUID, ensuring path uniqueness
 *   - Safe to delete independently: Temp uploads are separate from final asset storage
 *
 * Why this contract matters:
 *   - Cleanup: Can safely delete temp uploads without affecting assets
 *   - Resume: Can locate partial uploads deterministically for retry/resume
 *   - Isolation: Temporary uploads are clearly separated from permanent asset storage
 *   - Multi-service coordination: Different services can generate the same path independently
 *
 * The path MUST match UploadInitiationService::generateTempUploadPath() exactly.
 * This contract MUST be maintained across all services that interact with temporary uploads.
 */
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
     * This method is IDEMPOTENT - if an asset already exists for this upload session,
     * it returns the existing asset instead of creating a duplicate.
     *
     * This prevents duplicate assets from being created if /assets/upload/complete
     * is called multiple times (e.g., due to network retries or frontend issues).
     *
     * RULES:
     * - Do NOT modify Asset lifecycle or creation logic
     * - Do NOT create assets during resume
     * - Do NOT trust client state blindly
     * - UploadSession remains the source of truth
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
        // Refresh to get latest state
        $uploadSession->refresh();

        // IDEMPOTENT CHECK: If upload session is already COMPLETED, check for existing asset
        if ($uploadSession->status === UploadStatus::COMPLETED) {
            $existingAsset = Asset::where('upload_session_id', $uploadSession->id)->first();
            
            if ($existingAsset) {
                Log::info('Upload completion called but asset already exists (idempotent)', [
                    'upload_session_id' => $uploadSession->id,
                    'asset_id' => $existingAsset->id,
                    'asset_status' => $existingAsset->status->value,
                ]);

                return $existingAsset;
            } else {
                // Status is COMPLETED but no asset found - this is a data inconsistency
                // Log warning but allow completion to proceed to fix the inconsistency
                Log::warning('Upload session marked as COMPLETED but no asset found - recreating asset', [
                    'upload_session_id' => $uploadSession->id,
                ]);

                // Reset status to allow completion (this should be rare)
                // Use force transition to fix data inconsistency
                $uploadSession->update(['status' => UploadStatus::UPLOADING]);
                $uploadSession->refresh();
            }
        }

        // Check for existing asset BEFORE attempting completion (race condition protection)
        // This handles the case where two requests complete simultaneously
        $existingAsset = Asset::where('upload_session_id', $uploadSession->id)->first();
        if ($existingAsset) {
            Log::info('Asset already exists for upload session (race condition detected)', [
                'upload_session_id' => $uploadSession->id,
                'asset_id' => $existingAsset->id,
            ]);

            // Update session status if not already COMPLETED
            if ($uploadSession->status !== UploadStatus::COMPLETED) {
                $uploadSession->update(['status' => UploadStatus::COMPLETED]);
            }

            return $existingAsset;
        }

        // Verify upload session can transition to COMPLETED (using guard method)
        if (!$uploadSession->canTransitionTo(UploadStatus::COMPLETED)) {
            throw new \RuntimeException(
                "Cannot transition upload session from {$uploadSession->status->value} to COMPLETED. " .
                "Upload session must be in INITIATING or UPLOADING status and not expired."
            );
        }

        // Get file info from S3 (never trust client metadata)
        // This is done outside transaction since it's an external operation
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

        // Wrap asset creation and status update in transaction for atomicity
        // This ensures that if asset creation fails, status doesn't change
        // The unique constraint on upload_session_id prevents duplicate assets at DB level
        return DB::transaction(function () use ($uploadSession, $fileInfo, $assetTypeEnum, $storagePath) {
            // Double-check for existing asset inside transaction (final race condition check)
            $existingAsset = Asset::where('upload_session_id', $uploadSession->id)->lockForUpdate()->first();
            if ($existingAsset) {
                Log::info('Asset already exists for upload session (race condition detected in transaction)', [
                    'upload_session_id' => $uploadSession->id,
                    'asset_id' => $existingAsset->id,
                ]);

                // Update session status if not already COMPLETED
                if ($uploadSession->status !== UploadStatus::COMPLETED) {
                    $uploadSession->update(['status' => UploadStatus::COMPLETED]);
                }

                return $existingAsset;
            }

            // Create asset - unique constraint prevents duplicates
            try {
                $asset = Asset::create([
                    'tenant_id' => $uploadSession->tenant_id,
                    'brand_id' => $uploadSession->brand_id,
                    'upload_session_id' => $uploadSession->id, // Unique constraint prevents duplicates
                    'storage_bucket_id' => $uploadSession->storage_bucket_id,
                    'status' => AssetStatus::UPLOADED, // Initial state after upload completion
                    'type' => $assetTypeEnum,
                    'original_filename' => $fileInfo['original_filename'],
                    'mime_type' => $fileInfo['mime_type'],
                    'size_bytes' => $fileInfo['size_bytes'],
                    'storage_root_path' => $storagePath,
                    'metadata' => [],
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle unique constraint violation (duplicate asset detected)
                // Laravel/PDO throws code 23000 for unique constraint violations
                // Also check error message for additional safety
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();
                
                if ($errorCode === 23000 || 
                    $errorCode === '23000' || 
                    str_contains($errorMessage, 'upload_session_id') ||
                    str_contains($errorMessage, 'UNIQUE constraint') ||
                    str_contains($errorMessage, 'Duplicate entry')) {
                    
                    Log::info('Duplicate asset creation prevented by unique constraint (race condition)', [
                        'upload_session_id' => $uploadSession->id,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                    ]);

                    // Fetch the existing asset that was created by the other request
                    $asset = Asset::where('upload_session_id', $uploadSession->id)->firstOrFail();
                } else {
                    // Re-throw if it's a different error
                    Log::error('Unexpected database error during asset creation', [
                        'upload_session_id' => $uploadSession->id,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                    ]);
                    throw $e;
                }
            }

            // Update upload session status and uploaded size (transition already validated above)
            // This is inside the transaction so status only updates if asset creation succeeds
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

            // Emit AssetUploaded event (only emit once, even if called multiple times)
            // The event system should handle duplicate events gracefully
            event(new AssetUploaded($asset));

            return $asset;
        });
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

        // Generate expected S3 key using immutable contract: temp/uploads/{upload_session_id}/original
        // This path is deterministic and based solely on upload_session_id
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
     *
     * IMMUTABLE CONTRACT - This path format must never change:
     *
     * Temporary upload objects must live at:
     *   temp/uploads/{upload_session_id}/original
     *
     * This path is:
     *   - Deterministic: Same upload_session_id always produces the same path
     *   - Never reused: Each upload_session_id is unique (UUID), ensuring path uniqueness
     *   - Safe to delete independently: Temp uploads are separate from final asset storage
     *
     * Why this matters:
     *   - Cleanup: Can safely delete temp uploads without affecting assets
     *   - Resume: Can locate partial uploads deterministically for retry/resume
     *   - Isolation: Temporary uploads are clearly separated from permanent asset storage
     *
     * The path MUST match UploadInitiationService::generateTempUploadPath() exactly.
     *
     * @param UploadSession $uploadSession The upload session
     * @return string S3 key path: temp/uploads/{upload_session_id}/original
     */
    protected function generateTempUploadPath(UploadSession $uploadSession): string
    {
        // IMMUTABLE: This path format must never change
        // Path is deterministic and based solely on upload_session_id
        // MUST match UploadInitiationService::generateTempUploadPath() exactly
        return "temp/uploads/{$uploadSession->id}/original";
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
