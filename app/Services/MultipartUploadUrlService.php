<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Models\UploadSession;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating presigned URLs for multipart upload parts.
 *
 * This service generates secure, time-limited presigned URLs that allow
 * the frontend to upload individual parts of a multipart upload directly to S3.
 *
 * SAFETY RULES:
 * - Never modifies UploadSession state
 * - Never performs database writes
 * - Side-effect free (pure function)
 * - Enforces immutable temp upload path contract
 * - Validates UploadSession state before generating URLs
 *
 * TEMPORARY UPLOAD PATH CONTRACT (IMMUTABLE):
 *
 * All multipart upload parts must upload to:
 *   temp/uploads/{upload_session_id}/original
 *
 * This path format is:
 *   - Deterministic: Same upload_session_id always produces the same path
 *   - Never reused: Each upload_session_id is a unique UUID
 *   - Safe to delete independently: Temp uploads are separate from asset storage
 *
 * This contract MUST match UploadInitiationService::generateTempUploadPath() exactly.
 */
class MultipartUploadUrlService
{
    /**
     * Default expiration time for presigned URLs (in seconds).
     * 15 minutes provides sufficient time for part upload while maintaining security.
     */
    protected const DEFAULT_EXPIRATION_SECONDS = 900; // 15 minutes

    /**
     * Create a new instance.
     */
    public function __construct(
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Generate a presigned URL for uploading a single multipart upload part.
     *
     * This method validates the UploadSession state and generates a secure,
     * time-limited presigned URL for uploading a specific part to S3.
     *
     * @param UploadSession $uploadSession
     * @param int $partNumber The part number (1-indexed)
     * @return array{
     *   upload_session_id: string,
     *   multipart_upload_id: string,
     *   part_number: int,
     *   upload_url: string,
     *   expires_in: int
     * }
     * @throws \RuntimeException If UploadSession is invalid or cannot generate URL
     */
    public function generatePartUploadUrl(UploadSession $uploadSession, int $partNumber): array
    {
        // Validate UploadSession state
        $this->validateUploadSession($uploadSession);

        // Validate part number
        if ($partNumber < 1) {
            throw new \RuntimeException('Part number must be greater than or equal to 1');
        }

        // Ensure multipart_upload_id exists
        if (!$uploadSession->multipart_upload_id) {
            throw new \RuntimeException(
                'Upload session does not have a multipart upload ID. ' .
                'Multipart upload must be initiated before generating part URLs.'
            );
        }

        $bucket = $uploadSession->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session');
        }

        // Generate temp upload path using immutable contract
        // Path MUST be: temp/uploads/{upload_session_id}/original
        $path = $this->generateTempUploadPath($uploadSession->id);

        // Generate presigned URL for UploadPart operation
        try {
            $command = $this->s3Client->getCommand('UploadPart', [
                'Bucket' => $bucket->name,
                'Key' => $path,
                'UploadId' => $uploadSession->multipart_upload_id,
                'PartNumber' => $partNumber,
            ]);

            $presignedRequest = $this->s3Client->createPresignedRequest(
                $command,
                '+' . self::DEFAULT_EXPIRATION_SECONDS . ' seconds'
            );

            $presignedUrl = (string) $presignedRequest->getUri();

            Log::info('Generated presigned URL for multipart upload part', [
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
                'part_number' => $partNumber,
                'tenant_id' => $uploadSession->tenant_id,
                'bucket' => $bucket->name,
                'path' => $path,
                'expires_in_seconds' => self::DEFAULT_EXPIRATION_SECONDS,
            ]);

            return [
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
                'part_number' => $partNumber,
                'upload_url' => $presignedUrl,
                'expires_in' => self::DEFAULT_EXPIRATION_SECONDS,
            ];
        } catch (S3Exception $e) {
            Log::error('Failed to generate presigned URL for multipart upload part', [
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
                'part_number' => $partNumber,
                'bucket' => $bucket->name,
                'path' => $path,
                'error' => $e->getMessage(),
                'error_code' => $e->getAwsErrorCode(),
            ]);

            throw new \RuntimeException(
                "Failed to generate presigned URL for multipart upload part: {$e->getMessage()}",
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error generating presigned URL for multipart upload part', [
                'upload_session_id' => $uploadSession->id,
                'part_number' => $partNumber,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "Unexpected error generating presigned URL: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Validate UploadSession is in a valid state for generating part URLs.
     *
     * @param UploadSession $uploadSession
     * @return void
     * @throws \RuntimeException If UploadSession is invalid
     */
    protected function validateUploadSession(UploadSession $uploadSession): void
    {
        // Check if session is terminal (cannot generate URLs for terminal sessions)
        if ($uploadSession->isTerminal()) {
            throw new \RuntimeException(
                "Upload session is in terminal state ({$uploadSession->status->value}) and cannot generate part URLs. " .
                "Terminal states: COMPLETED, FAILED, CANCELLED."
            );
        }

        // Check if session is expired
        if ($uploadSession->expires_at && $uploadSession->expires_at->isPast()) {
            throw new \RuntimeException(
                'Upload session has expired and cannot generate part URLs. ' .
                'Expiration time: ' . $uploadSession->expires_at->toIso8601String()
            );
        }

        // Check if session can transition (validate state is resumable)
        // INITIATING and UPLOADING are the only valid states for part URL generation
        $validStates = [UploadStatus::INITIATING, UploadStatus::UPLOADING];
        if (!in_array($uploadSession->status, $validStates)) {
            throw new \RuntimeException(
                "Upload session is in invalid state ({$uploadSession->status->value}) for generating part URLs. " .
                "Valid states: INITIATING, UPLOADING."
            );
        }
    }

    /**
     * Generate temporary upload path using immutable contract.
     *
     * IMMUTABLE CONTRACT - This path format must never change:
     *
     * Temporary upload objects must live at:
     *   temp/uploads/{upload_session_id}/original
     *
     * This path is:
     *   - Deterministic: Same upload_session_id always produces the same path
     *   - Never reused: Each upload_session_id is a unique UUID, ensuring path uniqueness
     *   - Safe to delete independently: Temp uploads are separate from final asset storage
     *
     * This path MUST match UploadInitiationService::generateTempUploadPath() exactly.
     *
     * @param string $uploadSessionId The unique upload session UUID
     * @return string S3 key path: temp/uploads/{upload_session_id}/original
     */
    protected function generateTempUploadPath(string $uploadSessionId): string
    {
        // IMMUTABLE: This path format must never change
        // Path is deterministic and based solely on upload_session_id
        // MUST match UploadInitiationService::generateTempUploadPath() exactly
        return "temp/uploads/{$uploadSessionId}/original";
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
                'AWS SDK for PHP is required for multipart upload URL generation. ' .
                'Install it via: composer require aws/aws-sdk-php'
            );
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }
}
