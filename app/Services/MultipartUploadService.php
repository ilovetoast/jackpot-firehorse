<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Models\UploadSession;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing S3 multipart upload operations.
 *
 * This service handles the complete lifecycle of multipart uploads:
 * - Initiating multipart uploads
 * - Signing part upload URLs
 * - Completing multipart uploads
 * - Aborting multipart uploads
 *
 * All operations are idempotent and safe to retry.
 *
 * MULTIPART STATE STRUCTURE:
 *
 * multipart_state is a JSON field that tracks:
 * {
 *   "initiated_at": "2026-01-10T12:00:00Z",  // ISO 8601 timestamp
 *   "completed_parts": {                      // Map of part_number => etag
 *     "1": "etag1",
 *     "2": "etag2",
 *     ...
 *   },
 *   "status": "initiated|uploading|completed|aborted"
 * }
 *
 * RESUME BEHAVIOR:
 * - Frontend can query completed_parts to determine which parts are already uploaded
 * - Only missing parts need to be uploaded
 * - Automatic resume is supported by checking multipart_state before initiating
 */
class MultipartUploadService
{
    /**
     * Default chunk size for multipart uploads (10MB).
     */
    protected const DEFAULT_CHUNK_SIZE = 10 * 1024 * 1024; // 10 MB

    /**
     * Default expiration time for presigned URLs (in seconds).
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
     * Initiate a multipart upload for an upload session.
     *
     * This method is idempotent - if a multipart upload is already initiated,
     * it returns the existing multipart_upload_id without creating a new one.
     *
     * @param UploadSession $session
     * @return array{
     *   multipart_upload_id: string,
     *   part_size: int,
     *   total_parts: int,
     *   already_initiated: bool
     * }
     * @throws \RuntimeException If session is invalid or initiation fails
     */
    public function initiateMultipartUpload(UploadSession $session): array
    {
        // Validate session state
        $this->validateSessionForMultipart($session);

        // Check if already initiated (idempotent)
        if ($session->multipart_upload_id) {
            $state = $this->getMultipartState($session);
            
            // If already completed or aborted, throw error
            if ($state['status'] === 'completed') {
                throw new \RuntimeException(
                    'Multipart upload is already completed. Cannot re-initiate.'
                );
            }
            
            if ($state['status'] === 'aborted') {
                throw new \RuntimeException(
                    'Multipart upload was aborted. Cannot re-initiate.'
                );
            }

            // Already initiated - return existing state
            Log::info('Multipart upload already initiated (idempotent)', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $session->multipart_upload_id,
                'status' => $state['status'],
            ]);

            return [
                'multipart_upload_id' => $session->multipart_upload_id,
                'part_size' => $session->part_size ?? self::DEFAULT_CHUNK_SIZE,
                'total_parts' => $session->total_parts ?? $this->calculateTotalParts($session->expected_size),
                'already_initiated' => true,
            ];
        }

        // Calculate part size and total parts
        $partSize = self::DEFAULT_CHUNK_SIZE;
        $totalParts = $this->calculateTotalParts($session->expected_size);

        // Get bucket and path
        $bucket = $session->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session');
        }

        $path = $this->generateTempUploadPath($session->id);

        // Initiate multipart upload on S3
        try {
            $result = $this->s3Client->createMultipartUpload([
                'Bucket' => $bucket->name,
                'Key' => $path,
            ]);

            $multipartUploadId = $result->get('UploadId');

            // Initialize multipart state
            $multipartState = [
                'initiated_at' => now()->toIso8601String(),
                'completed_parts' => [],
                'status' => 'initiated',
            ];

            // Update session with multipart info
            $session->update([
                'multipart_upload_id' => $multipartUploadId,
                'multipart_state' => $multipartState,
                'part_size' => $partSize,
                'total_parts' => $totalParts,
            ]);

            Log::info('Multipart upload initiated', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $multipartUploadId,
                'part_size' => $partSize,
                'total_parts' => $totalParts,
                'expected_size' => $session->expected_size,
                'bucket' => $bucket->name,
                'path' => $path,
            ]);

            return [
                'multipart_upload_id' => $multipartUploadId,
                'part_size' => $partSize,
                'total_parts' => $totalParts,
                'already_initiated' => false,
            ];
        } catch (S3Exception $e) {
            Log::error('Failed to initiate multipart upload', [
                'upload_session_id' => $session->id,
                'bucket' => $bucket->name,
                'path' => $path,
                'error' => $e->getMessage(),
                'error_code' => $e->getAwsErrorCode(),
            ]);

            // Phase 2.65: Emit upload signal for AI analysis
            $this->emitMultipartErrorSignal($session, 'multipart_init', 's3', $e->getMessage());

            throw new \RuntimeException(
                "Failed to initiate multipart upload: {$e->getMessage()}",
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error initiating multipart upload', [
                'upload_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            // Phase 2.65: Emit upload signal for AI analysis
            $this->emitMultipartErrorSignal($session, 'multipart_init', 'unknown', $e->getMessage());

            throw new \RuntimeException(
                "Unexpected error initiating multipart upload: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Sign a presigned URL for uploading a specific part.
     *
     * @param UploadSession $session
     * @param int $partNumber The part number (1-indexed)
     * @return array{
     *   upload_url: string,
     *   expires_in: int,
     *   part_number: int
     * }
     * @throws \RuntimeException If session is invalid or URL generation fails
     */
    public function signPartUploadUrl(UploadSession $session, int $partNumber): array
    {
        // Validate session state
        $this->validateSessionForMultipart($session);

        // Validate part number
        if ($partNumber < 1) {
            throw new \RuntimeException('Part number must be greater than or equal to 1');
        }

        // Ensure multipart upload is initiated
        if (!$session->multipart_upload_id) {
            throw new \RuntimeException(
                'Multipart upload not initiated. Call initiateMultipartUpload first.'
            );
        }

        $totalParts = $session->total_parts ?? $this->calculateTotalParts($session->expected_size);
        if ($partNumber > $totalParts) {
            throw new \RuntimeException(
                "Part number {$partNumber} exceeds total parts ({$totalParts})"
            );
        }

        $bucket = $session->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session');
        }

        $path = $this->generateTempUploadPath($session->id);

        try {
            $command = $this->s3Client->getCommand('UploadPart', [
                'Bucket' => $bucket->name,
                'Key' => $path,
                'UploadId' => $session->multipart_upload_id,
                'PartNumber' => $partNumber,
            ]);

            $presignedRequest = $this->s3Client->createPresignedRequest(
                $command,
                '+' . self::DEFAULT_EXPIRATION_SECONDS . ' seconds'
            );

            $presignedUrl = (string) $presignedRequest->getUri();

            Log::info('Generated presigned URL for multipart upload part', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $session->multipart_upload_id,
                'part_number' => $partNumber,
                'bucket' => $bucket->name,
                'path' => $path,
                'expires_in_seconds' => self::DEFAULT_EXPIRATION_SECONDS,
            ]);

            return [
                'upload_url' => $presignedUrl,
                'expires_in' => self::DEFAULT_EXPIRATION_SECONDS,
                'part_number' => $partNumber,
            ];
        } catch (S3Exception $e) {
            Log::error('Failed to generate presigned URL for multipart upload part', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $session->multipart_upload_id,
                'part_number' => $partNumber,
                'bucket' => $bucket->name,
                'path' => $path,
                'error' => $e->getMessage(),
                'error_code' => $e->getAwsErrorCode(),
            ]);

            // Phase 2.65: Emit upload signal for AI analysis
            $this->emitMultipartErrorSignal($session, 'multipart_upload_part', 's3', $e->getMessage());

            throw new \RuntimeException(
                "Failed to generate presigned URL for part {$partNumber}: {$e->getMessage()}",
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error generating presigned URL for multipart upload part', [
                'upload_session_id' => $session->id,
                'part_number' => $partNumber,
                'error' => $e->getMessage(),
            ]);

            // Phase 2.65: Emit upload signal for AI analysis
            $this->emitMultipartErrorSignal($session, 'multipart_upload_part', 'unknown', $e->getMessage());

            throw new \RuntimeException(
                "Unexpected error generating presigned URL: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Complete a multipart upload.
     *
     * This method is idempotent - if the upload is already completed,
     * it returns success without re-completing.
     *
     * @param UploadSession $session
     * @param array<int, string> $parts Array of [part_number => etag] pairs
     * @return array{
     *   completed: bool,
     *   already_completed: bool,
     *   etag: string|null
     * }
     * @throws \RuntimeException If session is invalid or completion fails
     */
    public function completeMultipartUpload(UploadSession $session, array $parts): array
    {
        // Validate session state
        $this->validateSessionForMultipart($session);

        // Ensure multipart upload is initiated
        if (!$session->multipart_upload_id) {
            throw new \RuntimeException(
                'Multipart upload not initiated. Cannot complete.'
            );
        }

        $state = $this->getMultipartState($session);

        // Check if already completed (idempotent)
        if ($state['status'] === 'completed') {
            Log::info('Multipart upload already completed (idempotent)', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $session->multipart_upload_id,
            ]);

            return [
                'completed' => true,
                'already_completed' => true,
                'etag' => null, // ETag not available for already-completed uploads
            ];
        }

        // Check if aborted
        if ($state['status'] === 'aborted') {
            throw new \RuntimeException(
                'Multipart upload was aborted. Cannot complete.'
            );
        }

        // Validate parts array
        if (empty($parts)) {
            throw new \RuntimeException('Parts array cannot be empty');
        }

        $bucket = $session->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session');
        }

        $path = $this->generateTempUploadPath($session->id);

        // Format parts for S3 API (array of ['PartNumber' => int, 'ETag' => string])
        $s3Parts = [];
        foreach ($parts as $partNumber => $etag) {
            $s3Parts[] = [
                'PartNumber' => (int) $partNumber,
                'ETag' => $etag,
            ];
        }

        // Sort by part number (required by S3)
        usort($s3Parts, fn($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

        try {
            $result = $this->s3Client->completeMultipartUpload([
                'Bucket' => $bucket->name,
                'Key' => $path,
                'UploadId' => $session->multipart_upload_id,
                'MultipartUpload' => [
                    'Parts' => $s3Parts,
                ],
            ]);

            $etag = $result->get('ETag');

            // Update multipart state to completed
            $state['status'] = 'completed';
            $state['completed_parts'] = $parts; // Store all completed parts
            $state['completed_at'] = now()->toIso8601String();

            $session->update([
                'multipart_state' => $state,
            ]);

            Log::info('Multipart upload completed', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $session->multipart_upload_id,
                'parts_count' => count($parts),
                'etag' => $etag,
                'bucket' => $bucket->name,
                'path' => $path,
            ]);

            return [
                'completed' => true,
                'already_completed' => false,
                'etag' => $etag,
            ];
        } catch (S3Exception $e) {
            Log::error('Failed to complete multipart upload', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $session->multipart_upload_id,
                'parts_count' => count($parts),
                'bucket' => $bucket->name,
                'path' => $path,
                'error' => $e->getMessage(),
                'error_code' => $e->getAwsErrorCode(),
            ]);

            // Phase 2.65: Emit upload signal for AI analysis
            $this->emitMultipartErrorSignal($session, 'multipart_complete', 's3', $e->getMessage());

            throw new \RuntimeException(
                "Failed to complete multipart upload: {$e->getMessage()}",
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error completing multipart upload', [
                'upload_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            // Phase 2.65: Emit upload signal for AI analysis
            $this->emitMultipartErrorSignal($session, 'multipart_complete', 'unknown', $e->getMessage());

            throw new \RuntimeException(
                "Unexpected error completing multipart upload: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Abort a multipart upload.
     *
     * This method safely cleans up both S3 and database state.
     * It is idempotent - safe to call multiple times.
     *
     * @param UploadSession $session
     * @return array{
     *   aborted: bool,
     *   already_aborted: bool
     * }
     * @throws \RuntimeException If abort fails
     */
    public function abortMultipartUpload(UploadSession $session): array
    {
        // Check if multipart upload exists
        if (!$session->multipart_upload_id) {
            // No multipart upload to abort - return success (idempotent)
            Log::info('Abort called but no multipart upload exists (idempotent)', [
                'upload_session_id' => $session->id,
            ]);

            return [
                'aborted' => true,
                'already_aborted' => true,
            ];
        }

        $state = $this->getMultipartState($session);

        // Check if already aborted (idempotent)
        if ($state['status'] === 'aborted') {
            Log::info('Multipart upload already aborted (idempotent)', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $session->multipart_upload_id,
            ]);

            return [
                'aborted' => true,
                'already_aborted' => true,
            ];
        }

        // Check if already completed - cannot abort completed uploads
        if ($state['status'] === 'completed') {
            throw new \RuntimeException(
                'Cannot abort a completed multipart upload.'
            );
        }

        $bucket = $session->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session');
        }

        $path = $this->generateTempUploadPath($session->id);

        try {
            // Abort multipart upload on S3
            $this->s3Client->abortMultipartUpload([
                'Bucket' => $bucket->name,
                'Key' => $path,
                'UploadId' => $session->multipart_upload_id,
            ]);

            // Update multipart state to aborted
            $state['status'] = 'aborted';
            $state['aborted_at'] = now()->toIso8601String();

            // Store multipart_upload_id for logging before clearing
            $multipartUploadId = $session->multipart_upload_id;

            // Clear multipart_upload_id and reset state
            $session->update([
                'multipart_upload_id' => null,
                'multipart_state' => $state,
                'part_size' => null,
                'total_parts' => null,
            ]);

            Log::info('Multipart upload aborted', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $multipartUploadId,
                'bucket' => $bucket->name,
                'path' => $path,
            ]);

            return [
                'aborted' => true,
                'already_aborted' => false,
            ];
        } catch (S3Exception $e) {
            // If upload doesn't exist on S3, still mark as aborted in DB (idempotent)
            if ($e->getAwsErrorCode() === 'NoSuchUpload') {
                Log::info('Multipart upload not found on S3 (already cleaned up)', [
                    'upload_session_id' => $session->id,
                    'multipart_upload_id' => $session->multipart_upload_id,
                ]);

                // Update state to aborted anyway
                $state['status'] = 'aborted';
                $state['aborted_at'] = now()->toIso8601String();

                $session->update([
                    'multipart_upload_id' => null,
                    'multipart_state' => $state,
                    'part_size' => null,
                    'total_parts' => null,
                ]);

                return [
                    'aborted' => true,
                    'already_aborted' => false,
                ];
            }

            Log::error('Failed to abort multipart upload', [
                'upload_session_id' => $session->id,
                'multipart_upload_id' => $session->multipart_upload_id,
                'bucket' => $bucket->name,
                'path' => $path,
                'error' => $e->getMessage(),
                'error_code' => $e->getAwsErrorCode(),
            ]);

            // Phase 2.65: Emit upload signal for AI analysis (abort errors are less critical but still tracked)
            $this->emitMultipartErrorSignal($session, 'multipart_abort', 's3', $e->getMessage());

            throw new \RuntimeException(
                "Failed to abort multipart upload: {$e->getMessage()}",
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error aborting multipart upload', [
                'upload_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            // Phase 2.65: Emit upload signal for AI analysis
            $this->emitMultipartErrorSignal($session, 'multipart_abort', 'unknown', $e->getMessage());

            throw new \RuntimeException(
                "Unexpected error aborting multipart upload: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Validate session is in a valid state for multipart operations.
     *
     * @param UploadSession $session
     * @return void
     * @throws \RuntimeException If session is invalid
     */
    protected function validateSessionForMultipart(UploadSession $session): void
    {
        // Check if session is terminal
        if ($session->isTerminal()) {
            throw new \RuntimeException(
                "Upload session is in terminal state ({$session->status->value}) and cannot perform multipart operations."
            );
        }

        // Check if session is expired
        if ($session->expires_at && $session->expires_at->isPast()) {
            throw new \RuntimeException(
                'Upload session has expired and cannot perform multipart operations. ' .
                'Expiration time: ' . $session->expires_at->toIso8601String()
            );
        }

        // Valid states for multipart operations
        $validStates = [UploadStatus::INITIATING, UploadStatus::UPLOADING];
        if (!in_array($session->status, $validStates)) {
            throw new \RuntimeException(
                "Upload session is in invalid state ({$session->status->value}) for multipart operations. " .
                "Valid states: INITIATING, UPLOADING."
            );
        }
    }

    /**
     * Get multipart state from session, with defaults.
     *
     * @param UploadSession $session
     * @return array{
     *   initiated_at: string|null,
     *   completed_parts: array<int, string>,
     *   status: string
     * }
     */
    protected function getMultipartState(UploadSession $session): array
    {
        $state = $session->multipart_state ?? [];

        return [
            'initiated_at' => $state['initiated_at'] ?? null,
            'completed_parts' => $state['completed_parts'] ?? [],
            'status' => $state['status'] ?? 'initiated',
        ];
    }

    /**
     * Calculate total number of parts for a file size.
     *
     * @param int $fileSize
     * @return int
     */
    protected function calculateTotalParts(int $fileSize): int
    {
        return (int) ceil($fileSize / self::DEFAULT_CHUNK_SIZE);
    }

    /**
     * Generate temporary upload path using immutable contract.
     *
     * IMMUTABLE CONTRACT - This path format must never change:
     *
     * Temporary upload objects must live at:
     *   temp/uploads/{upload_session_id}/original
     *
     * @param string $uploadSessionId
     * @return string
     */
    protected function generateTempUploadPath(string $uploadSessionId): string
    {
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
                'AWS SDK for PHP is required for multipart uploads. ' .
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

    /**
     * Phase 2.65: Emit upload error signal for AI analysis.
     * 
     * Helper method to emit normalized signals from multipart error paths.
     * Signal emission is best-effort and never throws.
     * 
     * @param UploadSession $session Upload session with error
     * @param string $requestPhase Request phase (multipart_init, multipart_upload_part, etc.)
     * @param string $errorType Error type (s3, unknown, etc.)
     * @param string $errorMessage Error message
     * @return void
     */
    protected function emitMultipartErrorSignal(
        UploadSession $session,
        string $requestPhase,
        string $errorType,
        string $errorMessage
    ): void {
        try {
            $signalService = app(\App\Services\UploadSignalService::class);
            
            // Build signal data from upload session
            $signalData = [
                'error_type' => $errorType,
                'request_phase' => $requestPhase,
                'upload_session_id' => $session->id,
                'file_size' => $session->expected_size,
                'message' => $errorMessage,
            ];
            
            // Get tenant from session's brand relationship
            $tenant = $session->brand?->tenant ?? app('tenant');
            
            $signalService->emitErrorSignal($signalData, $tenant);
        } catch (\Exception $e) {
            // Signal emission is best-effort - never throw
            // Silently fail to prevent disrupting upload error handling
            Log::debug('[MultipartUploadService] Signal emission failed (non-critical)', [
                'error' => $e->getMessage(),
                'upload_session_id' => $session->id,
            ]);
        }
    }
}
