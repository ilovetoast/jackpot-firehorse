<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Events\UploadCleanupAttempted;
use App\Events\UploadCleanupCompleted;
use App\Events\UploadCleanupFailed;
use App\Models\UploadSession;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Service for cleaning up expired and terminal upload sessions.
 *
 * SAFETY RULES (DO NOT VIOLATE):
 * - Never delete or modify real assets
 * - Never trust S3 as source of truth for domain state
 * - Never block on storage failures (best-effort only)
 * - Never throw exceptions (log warnings only)
 * - Only cleanup temporary uploads: temp/uploads/{upload_session_id}/original
 *
 * This service handles cleanup of:
 * - Expired upload sessions (expires_at < now)
 * - Terminal upload sessions (FAILED, CANCELLED)
 * - Temporary upload objects in S3
 * - Multipart uploads that need to be aborted
 */
class UploadCleanupService
{
    /**
     * Default threshold for cleanup (in hours).
     * Only sessions expired/terminal for this duration are cleaned up.
     */
    protected const DEFAULT_CLEANUP_THRESHOLD_HOURS = 1;

    /**
     * Create a new instance.
     */
    public function __construct(
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Cleanup expired and terminal upload sessions.
     *
     * Finds UploadSessions that are:
     * - Terminal (FAILED, CANCELLED)
     * - Or expired (expires_at < now)
     *
     * Attempts best-effort cleanup of:
     * - Temporary upload objects in S3
     * - Multipart uploads (abort)
     *
     * @param int|null $thresholdHours Optional threshold in hours (defaults to DEFAULT_CLEANUP_THRESHOLD_HOURS)
     * @return array{
     *   attempted: int,
     *   completed: int,
     *   failed: int,
     *   sessions: array<string, array{status: string, reason: string|null}>
     * }
     */
    public function cleanupExpiredAndTerminal(?int $thresholdHours = null): array
    {
        $threshold = $thresholdHours ?? self::DEFAULT_CLEANUP_THRESHOLD_HOURS;
        $cutoffTime = now()->subHours($threshold);

        $results = [
            'attempted' => 0,
            'completed' => 0,
            'failed' => 0,
            'sessions' => [],
        ];

        // Find terminal sessions (FAILED, CANCELLED)
        $terminalSessions = UploadSession::whereIn('status', [UploadStatus::FAILED, UploadStatus::CANCELLED])
            ->where('updated_at', '<', $cutoffTime) // Only cleanup sessions that have been terminal for threshold duration
            ->get();

        // Find expired sessions (expires_at < now)
        $expiredSessions = UploadSession::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('updated_at', '<', $cutoffTime) // Only cleanup sessions expired for threshold duration
            ->whereNotIn('status', [UploadStatus::FAILED, UploadStatus::CANCELLED]) // Avoid duplicates
            ->get();

        // Combine and deduplicate
        $allSessions = $terminalSessions->merge($expiredSessions)->unique('id');

        foreach ($allSessions as $session) {
            $results['attempted']++;

            // Determine cleanup reason
            $reason = $this->determineCleanupReason($session);

            try {
                $cleanupResult = $this->cleanupSession($session, $reason);
                
                if ($cleanupResult['success']) {
                    $results['completed']++;
                    $results['sessions'][$session->id] = [
                        'status' => 'completed',
                        'reason' => $reason,
                    ];
                } else {
                    $results['failed']++;
                    $results['sessions'][$session->id] = [
                        'status' => 'failed',
                        'reason' => $reason,
                        'error' => $cleanupResult['error'] ?? 'Unknown error',
                    ];
                }

                // Optionally update last_cleanup_attempt_at for observability
                // This helps track when cleanup was attempted (useful for audit/debugging)
                try {
                    $session->update(['last_cleanup_attempt_at' => now()]);
                } catch (\Exception $e) {
                    // Don't fail cleanup if timestamp update fails - this is optional
                    Log::debug('Failed to update last_cleanup_attempt_at', [
                        'upload_session_id' => $session->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e) {
                // Never throw - cleanup is best-effort
                $results['failed']++;
                $results['sessions'][$session->id] = [
                    'status' => 'failed',
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ];

                Log::warning('Cleanup session failed with exception', [
                    'upload_session_id' => $session->id,
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Upload cleanup completed', [
            'attempted' => $results['attempted'],
            'completed' => $results['completed'],
            'failed' => $results['failed'],
            'threshold_hours' => $threshold,
        ]);

        return $results;
    }

    /**
     * Cleanup a single upload session.
     *
     * @param UploadSession $uploadSession
     * @param string $reason Reason for cleanup
     * @return array{success: bool, error: string|null, multipartAborted: bool}
     */
    protected function cleanupSession(UploadSession $uploadSession, string $reason): array
    {
        $bucket = $uploadSession->storageBucket;
        if (!$bucket) {
            $error = 'Storage bucket not found for upload session';
            Log::warning($error, ['upload_session_id' => $uploadSession->id]);
            
            event(new UploadCleanupFailed(
                $uploadSession,
                $reason,
                '',
                '',
                $error
            ));

            return ['success' => false, 'error' => $error, 'multipartAborted' => false];
        }

        // Generate temp upload path using immutable contract
        $objectKeyPrefix = $this->generateTempUploadPath($uploadSession->id);
        
        // Emit attempted event
        event(new UploadCleanupAttempted(
            $uploadSession,
            $reason,
            $bucket->name,
            $objectKeyPrefix
        ));

        $multipartAborted = false;
        $errors = [];

        // Cleanup temporary upload object (best-effort)
        $tempObjectDeleted = $this->deleteTempUploadObject($bucket->name, $objectKeyPrefix);
        if (!$tempObjectDeleted['success']) {
            $errors[] = $tempObjectDeleted['error'];
        }

        // Abort multipart upload if it exists (best-effort)
        if ($uploadSession->multipart_upload_id) {
            $multipartResult = $this->abortMultipartUpload(
                $bucket->name,
                $objectKeyPrefix,
                $uploadSession->multipart_upload_id
            );
            if ($multipartResult['success']) {
                $multipartAborted = true;
            } else {
                $errors[] = $multipartResult['error'];
            }
        }

        // If at least one cleanup operation succeeded, consider it successful
        // Failures are logged but don't prevent success if partial cleanup worked
        $success = $tempObjectDeleted['success'] || $multipartAborted;
        $error = !empty($errors) ? implode('; ', $errors) : null;

        if ($success) {
            event(new UploadCleanupCompleted(
                $uploadSession,
                $reason,
                $bucket->name,
                $objectKeyPrefix,
                $multipartAborted
            ));
        } else {
            event(new UploadCleanupFailed(
                $uploadSession,
                $reason,
                $bucket->name,
                $objectKeyPrefix,
                $error ?? 'All cleanup operations failed'
            ));
        }

        return [
            'success' => $success,
            'error' => $error,
            'multipartAborted' => $multipartAborted,
        ];
    }

    /**
     * Determine cleanup reason for a session.
     *
     * @param UploadSession $uploadSession
     * @return string
     */
    protected function determineCleanupReason(UploadSession $uploadSession): string
    {
        if ($uploadSession->expires_at && $uploadSession->expires_at->isPast()) {
            return 'expired';
        }

        if ($uploadSession->status === UploadStatus::FAILED) {
            return 'failed';
        }

        if ($uploadSession->status === UploadStatus::CANCELLED) {
            return 'cancelled';
        }

        return 'terminal';
    }

    /**
     * Delete temporary upload object from S3 (best-effort).
     *
     * @param string $bucket
     * @param string $objectKey
     * @return array{success: bool, error: string|null}
     */
    protected function deleteTempUploadObject(string $bucket, string $objectKey): array
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $objectKey,
            ]);

            Log::debug('Deleted temp upload object', [
                'bucket' => $bucket,
                'key' => $objectKey,
            ]);

            return ['success' => true, 'error' => null];
        } catch (S3Exception $e) {
            // Don't throw - best-effort cleanup
            $error = "Failed to delete temp upload object: {$e->getMessage()}";
            
            Log::warning($error, [
                'bucket' => $bucket,
                'key' => $objectKey,
                'error_code' => $e->getAwsErrorCode(),
            ]);

            return ['success' => false, 'error' => $error];
        } catch (\Exception $e) {
            $error = "Unexpected error deleting temp upload object: {$e->getMessage()}";
            
            Log::warning($error, [
                'bucket' => $bucket,
                'key' => $objectKey,
            ]);

            return ['success' => false, 'error' => $error];
        }
    }

    /**
     * Abort multipart upload in S3 (best-effort).
     *
     * @param string $bucket
     * @param string $key
     * @param string $uploadId
     * @return array{success: bool, error: string|null}
     */
    protected function abortMultipartUpload(string $bucket, string $key, string $uploadId): array
    {
        try {
            $this->s3Client->abortMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);

            Log::debug('Aborted multipart upload', [
                'bucket' => $bucket,
                'key' => $key,
                'upload_id' => $uploadId,
            ]);

            return ['success' => true, 'error' => null];
        } catch (S3Exception $e) {
            // Check if upload doesn't exist (already aborted/completed) - this is fine
            if ($e->getAwsErrorCode() === 'NoSuchUpload') {
                Log::debug('Multipart upload not found (already aborted/completed)', [
                    'bucket' => $bucket,
                    'key' => $key,
                    'upload_id' => $uploadId,
                ]);

                return ['success' => true, 'error' => null]; // Consider success if already gone
            }

            // Don't throw - best-effort cleanup
            $error = "Failed to abort multipart upload: {$e->getMessage()}";
            
            Log::warning($error, [
                'bucket' => $bucket,
                'key' => $key,
                'upload_id' => $uploadId,
                'error_code' => $e->getAwsErrorCode(),
            ]);

            return ['success' => false, 'error' => $error];
        } catch (\Exception $e) {
            $error = "Unexpected error aborting multipart upload: {$e->getMessage()}";
            
            Log::warning($error, [
                'bucket' => $bucket,
                'key' => $key,
                'upload_id' => $uploadId,
            ]);

            return ['success' => false, 'error' => $error];
        }
    }

    /**
     * Generate temporary upload path using immutable contract.
     *
     * IMMUTABLE CONTRACT: temp/uploads/{upload_session_id}/original
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
                'AWS SDK for PHP is required for upload cleanup. ' .
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
