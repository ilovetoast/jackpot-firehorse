<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\UploadSession;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Service for retrieving resume metadata for upload sessions.
 *
 * Enables reload-safe, resumable uploads by providing information about
 * already uploaded parts for multipart uploads.
 *
 * EDGE CASE: Multipart Part Size Drift
 *
 * If the chunk_size changes between upload sessions (e.g., due to configuration changes),
 * the existing uploaded parts may have been uploaded with a different chunk size.
 * This creates an invalid state because:
 * - Parts were uploaded with old chunk_size (e.g., 5MB)
 * - New resume attempts expect different chunk_size (e.g., 10MB)
 * - Mixing different chunk sizes in a multipart upload will result in a corrupted file
 *
 * When chunk_size changes:
 * - Resume is invalid and will produce a corrupted file if continued
 * - Frontend MUST detect chunk_size mismatch and restart the upload from the beginning
 * - Frontend should abort the old multipart upload in S3 to clean up orphaned parts
 * - Resume should NOT proceed if chunk_size differs from original upload
 *
 * FUTURE ENHANCEMENT (Optional - not required now):
 * - Store the original chunk_size used during initiation in the database
 * - Backend validation: Compare expected chunk_size from initiation with current DEFAULT_CHUNK_SIZE
 * - Backend validation: Return can_resume = false with error message if chunk_size mismatch detected
 * - Backend validation: Automatically abort the multipart upload if chunk_size has changed
 *
 * For now, this is documented but not enforced at the backend level.
 * Frontend must handle chunk_size validation and restart upload if mismatch detected.
 */
class ResumeMetadataService
{
    /**
     * Default chunk size for multipart uploads (in bytes).
     * Must match UploadInitiationService::DEFAULT_CHUNK_SIZE
     *
     * FUTURE CONSIDERATION (Multipart Part Size Drift):
     * If chunk size ever changes between resumes (e.g., system configuration change),
     * resume becomes invalid because existing parts have different sizes.
     * Future implementation should:
     * - Compare expected chunk_size from initiation with current DEFAULT_CHUNK_SIZE
     * - If mismatch detected, set can_resume = false and provide error message
     * - Frontend should restart upload from beginning if chunk size changed
     * Not implemented now - chunk size is currently constant.
     */
    protected const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB

    /**
     * Create a new instance.
     */
    public function __construct(
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Get resume metadata for an upload session.
     *
     * This method queries S3 to determine what parts have already been uploaded,
     * enabling the frontend to resume uploads from where they left off.
     *
     * @param UploadSession $uploadSession
     * @return array{
     *   upload_session_status: string,
     *   upload_type: string,
     *   multipart_upload_id: string|null,
     *   already_uploaded_parts: array<int, array{PartNumber: int, ETag: string, Size: int}>|null,
     *   chunk_size: int|null,
     *   expires_at: string|null,
     *   is_expired: bool,
     *   can_resume: bool,
     *   error: string|null,
     *   uploaded_size: int,
     *   expected_size: int,
     *   s3_object_exists: bool
     * }
     */
    public function getResumeMetadata(UploadSession $uploadSession): array
    {
        // Refresh to get latest state
        $uploadSession->refresh();

        // Update last_activity_at when resume metadata is requested
        // This indicates the client is still actively working on the upload
        // and prevents the session from being marked as abandoned
        if (!$uploadSession->isTerminal()) {
            $uploadSession->update(['last_activity_at' => now()]);
            $uploadSession->refresh();
        }

        $result = [
            'upload_session_status' => $uploadSession->status->value,
            'upload_type' => $uploadSession->type->value,
            'multipart_upload_id' => $uploadSession->multipart_upload_id,
            'already_uploaded_parts' => null,
            'chunk_size' => null,
            'part_size' => $uploadSession->part_size, // Phase 2.6: Part size for multipart uploads
            'total_parts' => $uploadSession->total_parts, // Phase 2.6: Total parts for multipart uploads
            'multipart_state' => $uploadSession->multipart_state, // Phase 2.6: Multipart state (includes completed_parts)
            'expires_at' => $uploadSession->expires_at?->toIso8601String(),
            'is_expired' => $this->isExpired($uploadSession),
            'can_resume' => false,
            'error' => null,
            'uploaded_size' => $uploadSession->uploaded_size ?? 0,
            'expected_size' => $uploadSession->expected_size ?? 0,
            's3_object_exists' => false,
        ];

        // Check if session can be resumed
        $canResume = $this->canResume($uploadSession);
        $result['can_resume'] = $canResume;

        if (!$canResume) {
            $result['error'] = $this->getResumeBlockedReason($uploadSession);
            Log::info('Resume metadata requested but resume is blocked', [
                'upload_session_id' => $uploadSession->id,
                'status' => $uploadSession->status->value,
                'reason' => $result['error'],
            ]);
            return $result;
        }

            // Phase 2.6: For multipart uploads, use multipart_state.completed_parts if available
            // Fallback to S3 query for backward compatibility
            if ($uploadSession->type === UploadType::CHUNKED && $uploadSession->multipart_upload_id) {
                try {
                    // Phase 2.6: Prefer multipart_state.completed_parts from database
                    // This is more reliable than querying S3 and matches what frontend expects
                    $multipartState = $uploadSession->multipart_state ?? [];
                    $completedPartsFromState = $multipartState['completed_parts'] ?? [];
                    
                    // Convert multipart_state format to already_uploaded_parts format for backward compatibility
                    $uploadedParts = [];
                    if (!empty($completedPartsFromState)) {
                        foreach ($completedPartsFromState as $partNumber => $etag) {
                            // Calculate part size (use part_size from session or default)
                            $partSize = $uploadSession->part_size ?? self::DEFAULT_CHUNK_SIZE;
                            $start = ((int)$partNumber - 1) * $partSize;
                            $end = min($start + $partSize, $uploadSession->expected_size);
                            $size = $end - $start;
                            
                            $uploadedParts[] = [
                                'PartNumber' => (int)$partNumber,
                                'ETag' => $etag,
                                'Size' => $size,
                            ];
                        }
                    } else {
                        // Fallback: Query S3 for uploaded parts (backward compatibility)
                        $uploadedParts = $this->getUploadedParts($uploadSession);
                    }
                    
                    $result['already_uploaded_parts'] = $uploadedParts;
                    $result['chunk_size'] = $uploadSession->part_size ?? self::DEFAULT_CHUNK_SIZE;
                    
                    // Calculate actual uploaded size from S3 parts (more accurate than DB field)
                    $calculatedUploadedSize = array_sum(array_column($uploadedParts, 'Size'));
                    if ($calculatedUploadedSize > 0) {
                        $result['uploaded_size'] = $calculatedUploadedSize;
                    }
                    
                    // Check if S3 object exists (indicates multipart upload has been finalized)
                    try {
                        $bucket = $uploadSession->storageBucket;
                        if ($bucket) {
                            $path = $this->generateTempUploadPath($uploadSession);
                            $objectExists = $this->s3Client->doesObjectExist($bucket->name, $path);
                            $result['s3_object_exists'] = $objectExists;
                            
                            // If object exists, multipart upload is finalized and complete
                            if ($objectExists && $calculatedUploadedSize >= $uploadSession->expected_size) {
                                $result['uploaded_size'] = $uploadSession->expected_size ?? $calculatedUploadedSize;
                            }
                        }
                    } catch (\Exception $s3CheckError) {
                        // If S3 check fails, assume object doesn't exist yet
                        $result['s3_object_exists'] = false;
                        Log::debug('Failed to check S3 object existence for multipart upload', [
                            'upload_session_id' => $uploadSession->id,
                            'error' => $s3CheckError->getMessage(),
                        ]);
                    }

                    // EDGE CASE: Multipart Part Size Drift
                    // If chunk_size ever changes between resumes (e.g., configuration change),
                    // resume is invalid and will produce a corrupted file if continued.
                    // Frontend MUST detect chunk_size mismatch and restart the upload from beginning.
                    // Backend validation not implemented now - documented for future consideration.

                    Log::info('Resume metadata retrieved successfully', [
                        'upload_session_id' => $uploadSession->id,
                        'multipart_upload_id' => $uploadSession->multipart_upload_id,
                        'parts_count' => count($uploadedParts),
                        'chunk_size' => self::DEFAULT_CHUNK_SIZE,
                        'calculated_uploaded_size' => $calculatedUploadedSize,
                        's3_object_exists' => $result['s3_object_exists'] ?? false,
                    ]);
                } catch (\Exception $e) {
                Log::error('Failed to retrieve uploaded parts for resume', [
                    'upload_session_id' => $uploadSession->id,
                    'multipart_upload_id' => $uploadSession->multipart_upload_id,
                    'error' => $e->getMessage(),
                ]);

                $result['can_resume'] = false;
                $result['error'] = 'Failed to query S3 for uploaded parts: ' . $e->getMessage();
            }
        } else {
            // Direct uploads don't have parts to query
            $result['chunk_size'] = null;
            
            // For direct uploads, check if S3 object exists to determine if upload is complete
            try {
                $bucket = $uploadSession->storageBucket;
                if ($bucket) {
                    $path = $this->generateTempUploadPath($uploadSession);
                    $objectExists = $this->s3Client->doesObjectExist($bucket->name, $path);
                    $result['s3_object_exists'] = $objectExists;
                    
                    if ($objectExists) {
                        // If object exists, uploaded_size equals expected_size
                        $result['uploaded_size'] = $uploadSession->expected_size ?? 0;
                    }
                }
            } catch (\Exception $e) {
                // If S3 check fails, assume object doesn't exist yet
                $result['s3_object_exists'] = false;
                Log::debug('Failed to check S3 object existence for direct upload', [
                    'upload_session_id' => $uploadSession->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Check if an upload session can be resumed.
     *
     * Resume is allowed for:
     * - INITIATING sessions (upload hasn't started yet, but session is ready)
     * - UPLOADING sessions (upload in progress, can resume from last uploaded part)
     *
     * Resume is NOT allowed for:
     * - Terminal states (COMPLETED, FAILED, CANCELLED)
     * - Expired sessions (expires_at has passed)
     *
     * @param UploadSession $uploadSession
     * @return bool
     */
    protected function canResume(UploadSession $uploadSession): bool
    {
        // Cannot resume terminal states
        if ($uploadSession->isTerminal()) {
            return false;
        }

        // Cannot resume expired sessions
        if ($this->isExpired($uploadSession)) {
            return false;
        }

        // Can resume INITIATING or UPLOADING sessions
        // INITIATING: Session created, ready to start upload
        // UPLOADING: Upload in progress, can resume from uploaded parts
        return in_array($uploadSession->status, [UploadStatus::INITIATING, UploadStatus::UPLOADING]);
    }

    /**
     * Get reason why resume is blocked (for error messages).
     *
     * @param UploadSession $uploadSession
     * @return string
     */
    protected function getResumeBlockedReason(UploadSession $uploadSession): string
    {
        if ($this->isExpired($uploadSession)) {
            return 'Upload session has expired and cannot be resumed.';
        }

        if ($uploadSession->status === UploadStatus::COMPLETED) {
            return 'Upload session is already completed and cannot be resumed.';
        }

        if ($uploadSession->status === UploadStatus::FAILED) {
            return 'Upload session has failed and cannot be resumed. Please initiate a new upload.';
        }

        if ($uploadSession->status === UploadStatus::CANCELLED) {
            return 'Upload session was cancelled and cannot be resumed.';
        }

        return "Upload session is in status '{$uploadSession->status->value}' and cannot be resumed.";
    }

    /**
     * Check if upload session is expired.
     *
     * @param UploadSession $uploadSession
     * @return bool
     */
    protected function isExpired(UploadSession $uploadSession): bool
    {
        return $uploadSession->expires_at && $uploadSession->expires_at->isPast();
    }

    /**
     * Query S3 for already uploaded parts in a multipart upload.
     *
     * @param UploadSession $uploadSession
     * @return array<int, array{PartNumber: int, ETag: string, Size: int}>
     * @throws \RuntimeException If S3 query fails
     */
    protected function getUploadedParts(UploadSession $uploadSession): array
    {
        if (!$uploadSession->multipart_upload_id) {
            throw new \RuntimeException('Multipart upload ID is required to query uploaded parts.');
        }

        $bucket = $uploadSession->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session.');
        }

        $path = $this->generateTempUploadPath($uploadSession);

        try {
            // List parts that have already been uploaded for this multipart upload
            $partsList = $this->s3Client->listParts([
                'Bucket' => $bucket->name,
                'Key' => $path,
                'UploadId' => $uploadSession->multipart_upload_id,
            ]);

            $parts = [];
            foreach ($partsList->get('Parts') ?? [] as $part) {
                $parts[] = [
                    'PartNumber' => (int) $part['PartNumber'],
                    'ETag' => $part['ETag'],
                    'Size' => (int) $part['Size'],
                ];
            }

            // Sort by part number for consistent ordering
            usort($parts, fn($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

            return $parts;
        } catch (S3Exception $e) {
            // Check if multipart upload doesn't exist (was aborted or completed)
            if ($e->getAwsErrorCode() === 'NoSuchUpload') {
                Log::warning('Multipart upload not found in S3 - may have been aborted', [
                    'upload_session_id' => $uploadSession->id,
                    'multipart_upload_id' => $uploadSession->multipart_upload_id,
                    'bucket' => $bucket->name,
                    'path' => $path,
                ]);

                // If multipart upload doesn't exist, return empty array
                // Frontend will need to start fresh
                return [];
            }

            throw new \RuntimeException(
                "Failed to query S3 for uploaded parts: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Generate temporary upload path for S3 (matches UploadInitiationService contract).
     *
     * IMMUTABLE CONTRACT: temp/uploads/{upload_session_id}/original
     *
     * @param UploadSession $uploadSession
     * @return string
     */
    protected function generateTempUploadPath(UploadSession $uploadSession): string
    {
        return "temp/uploads/{$uploadSession->id}/original";
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
                'AWS SDK for PHP is required for resume metadata. ' .
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
