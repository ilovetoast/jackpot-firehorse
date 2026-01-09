<?php

namespace App\Services;

use App\Events\UploadCleanupFailed;
use App\Models\UploadSession;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Service for cleaning up orphaned multipart uploads in S3.
 *
 * SAFETY RULES (DO NOT VIOLATE):
 * - Never delete or modify real assets
 * - Never trust S3 as source of truth for domain state
 * - Never block on storage failures (best-effort only)
 * - Never throw exceptions (log warnings only)
 * - Only cleanup multipart uploads under temp/uploads/ prefix
 * - Only abort multipart uploads, never delete permanent assets
 *
 * This service handles:
 * - Orphaned multipart uploads (upload_session_id no longer exists in DB)
 * - Stale multipart uploads older than threshold (e.g., 24 hours)
 * - Pagination-aware scanning of large buckets
 */
class MultipartCleanupService
{
    /**
     * Default threshold for orphaned multipart upload cleanup (in hours).
     * Multipart uploads older than this are considered stale and can be aborted.
     */
    protected const DEFAULT_ORPHAN_THRESHOLD_HOURS = 24;

    /**
     * Maximum number of multipart uploads to process per run.
     * Prevents long-running cleanup jobs from blocking the system.
     */
    protected const MAX_MULTIPART_UPLOADS_PER_RUN = 100;

    /**
     * Create a new instance.
     */
    public function __construct(
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Cleanup orphaned multipart uploads in S3.
     *
     * Lists all multipart uploads in the bucket and identifies uploads whose
     * upload_session_id no longer exists in the database or are older than threshold.
     *
     * Rules:
     * - S3 is scanned carefully (pagination aware)
     * - Abort is best-effort
     * - Never delete permanent asset objects
     * - Only processes multipart uploads under temp/uploads/ prefix
     *
     * @param string $bucketName Bucket name to scan
     * @param int|null $thresholdHours Optional threshold in hours (defaults to DEFAULT_ORPHAN_THRESHOLD_HOURS)
     * @return array{
     *   scanned: int,
     *   orphaned: int,
     *   aborted: int,
     *   failed: int,
     *   uploads: array<int, array{upload_id: string, key: string, reason: string, status: string}>
     * }
     */
    public function cleanupOrphanedMultipartUploads(string $bucketName, ?int $thresholdHours = null): array
    {
        $threshold = $thresholdHours ?? self::DEFAULT_ORPHAN_THRESHOLD_HOURS;
        $cutoffTime = now()->subHours($threshold);

        $results = [
            'scanned' => 0,
            'orphaned' => 0,
            'aborted' => 0,
            'failed' => 0,
            'uploads' => [],
        ];

        try {
            // List all multipart uploads (paginated)
            $paginator = $this->s3Client->getPaginator('ListMultipartUploads', [
                'Bucket' => $bucketName,
                'Prefix' => 'temp/uploads/', // Only scan temp uploads - never touch permanent assets
            ]);

            foreach ($paginator as $page) {
                $uploads = $page->get('Uploads') ?? [];

                foreach ($uploads as $upload) {
                    $results['scanned']++;

                    // Stop if we've processed too many (prevent long-running jobs)
                    if ($results['scanned'] > self::MAX_MULTIPART_UPLOADS_PER_RUN) {
                        Log::info('Reached max multipart uploads per run limit', [
                            'bucket' => $bucketName,
                            'max_limit' => self::MAX_MULTIPART_UPLOADS_PER_RUN,
                        ]);
                        break 2; // Break out of both loops
                    }

                    $key = $upload['Key'];
                    $uploadId = $upload['UploadId'];
                    $initiated = new \DateTime($upload['Initiated']);

                    // Extract upload_session_id from key: temp/uploads/{upload_session_id}/original
                    $uploadSessionId = $this->extractUploadSessionIdFromKey($key);

                    if (!$uploadSessionId) {
                        // Key doesn't match expected pattern - skip it
                        Log::debug('Skipping multipart upload with unexpected key pattern', [
                            'bucket' => $bucketName,
                            'key' => $key,
                            'upload_id' => $uploadId,
                        ]);
                        continue;
                    }

                    // Check if upload is orphaned (session doesn't exist) or stale (older than threshold)
                    $shouldAbort = false;
                    $reason = '';

                    // Only process uploads older than threshold
                    if ($initiated >= $cutoffTime) {
                        continue; // Skip recent uploads
                    }

                    // Upload is older than threshold - check if session still exists
                    $session = UploadSession::find($uploadSessionId);

                    if (!$session) {
                        // Session doesn't exist - orphaned
                        $shouldAbort = true;
                        $reason = 'orphaned';
                        $results['orphaned']++;
                    } elseif ($session->isTerminal()) {
                        // Session exists but is terminal
                        if ($session->multipart_upload_id !== $uploadId) {
                            // Multipart upload ID doesn't match - stale multipart from previous attempt
                            $shouldAbort = true;
                            $reason = 'stale_multipart_id';
                            $results['orphaned']++;
                        } else {
                            // Session is terminal and multipart ID matches - safe to abort
                            $shouldAbort = true;
                            $reason = 'terminal_session';
                            $results['orphaned']++;
                        }
                    } elseif ($session->expires_at && $session->expires_at->isPast()) {
                        // Session exists but is expired
                        $shouldAbort = true;
                        $reason = 'expired_session';
                        $results['orphaned']++;
                    }
                    // If session exists, is not terminal, and not expired, keep it (may be active)

                    if ($shouldAbort) {
                        $abortResult = $this->abortMultipartUpload($bucketName, $key, $uploadId, $uploadSessionId, $reason);

                        if ($abortResult['success']) {
                            $results['aborted']++;
                            $results['uploads'][] = [
                                'upload_id' => $uploadId,
                                'key' => $key,
                                'reason' => $reason,
                                'status' => 'aborted',
                            ];
                        } else {
                            $results['failed']++;
                            $results['uploads'][] = [
                                'upload_id' => $uploadId,
                                'key' => $key,
                                'reason' => $reason,
                                'status' => 'failed',
                                'error' => $abortResult['error'],
                            ];
                        }
                    }
                }
            }
        } catch (S3Exception $e) {
            // Don't throw - best-effort cleanup
            Log::error('Failed to list multipart uploads for cleanup', [
                'bucket' => $bucketName,
                'error' => $e->getMessage(),
                'error_code' => $e->getAwsErrorCode(),
            ]);

            return $results; // Return partial results
        } catch (\Exception $e) {
            // Don't throw - best-effort cleanup
            Log::error('Unexpected error during multipart upload cleanup', [
                'bucket' => $bucketName,
                'error' => $e->getMessage(),
            ]);

            return $results; // Return partial results
        }

        Log::info('Multipart upload cleanup completed', [
            'bucket' => $bucketName,
            'scanned' => $results['scanned'],
            'orphaned' => $results['orphaned'],
            'aborted' => $results['aborted'],
            'failed' => $results['failed'],
            'threshold_hours' => $threshold,
        ]);

        return $results;
    }

    /**
     * Extract upload_session_id from S3 key.
     *
     * Expected key format: temp/uploads/{upload_session_id}/original
     *
     * @param string $key S3 object key
     * @return string|null Upload session ID or null if pattern doesn't match
     */
    protected function extractUploadSessionIdFromKey(string $key): ?string
    {
        // Match pattern: temp/uploads/{uuid}/original
        if (preg_match('#^temp/uploads/([a-f0-9\-]{36})/original$#i', $key, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Abort multipart upload in S3 (best-effort).
     *
     * @param string $bucket
     * @param string $key
     * @param string $uploadId
     * @param string|null $uploadSessionId Optional upload session ID for logging
     * @param string $reason Reason for abort
     * @return array{success: bool, error: string|null}
     */
    protected function abortMultipartUpload(
        string $bucket,
        string $key,
        string $uploadId,
        ?string $uploadSessionId = null,
        string $reason = 'orphaned'
    ): array {
        try {
            $this->s3Client->abortMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);

            Log::info('Aborted orphaned multipart upload', [
                'bucket' => $bucket,
                'key' => $key,
                'upload_id' => $uploadId,
                'upload_session_id' => $uploadSessionId,
                'reason' => $reason,
            ]);

            // Emit audit event for orphaned multipart cleanup
            // Note: uploadSession may be null for truly orphaned uploads
            $uploadSession = $uploadSessionId ? UploadSession::find($uploadSessionId) : null;
            
            event(new UploadCleanupFailed(
                $uploadSession,
                $reason,
                $bucket,
                $key,
                "Orphaned multipart upload aborted: {$reason}"
            ));

            return ['success' => true, 'error' => null];
        } catch (S3Exception $e) {
            // Check if upload doesn't exist (already aborted/completed) - this is fine
            if ($e->getAwsErrorCode() === 'NoSuchUpload') {
                Log::debug('Multipart upload not found (already aborted/completed)', [
                    'bucket' => $bucket,
                    'key' => $key,
                    'upload_id' => $uploadId,
                    'upload_session_id' => $uploadSessionId,
                ]);

                return ['success' => true, 'error' => null]; // Consider success if already gone
            }

            // Don't throw - best-effort cleanup
            $error = "Failed to abort multipart upload: {$e->getMessage()}";
            
            Log::warning($error, [
                'bucket' => $bucket,
                'key' => $key,
                'upload_id' => $uploadId,
                'upload_session_id' => $uploadSessionId,
                'reason' => $reason,
                'error_code' => $e->getAwsErrorCode(),
            ]);

            return ['success' => false, 'error' => $error];
        } catch (\Exception $e) {
            $error = "Unexpected error aborting multipart upload: {$e->getMessage()}";
            
            Log::warning($error, [
                'bucket' => $bucket,
                'key' => $key,
                'upload_id' => $uploadId,
                'upload_session_id' => $uploadSessionId,
                'reason' => $reason,
            ]);

            return ['success' => false, 'error' => $error];
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
                'AWS SDK for PHP is required for multipart cleanup. ' .
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
