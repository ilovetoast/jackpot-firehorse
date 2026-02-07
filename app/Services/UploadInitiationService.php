<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Exceptions\BucketNotProvisionedException;
use App\Exceptions\BucketProvisioningNotAllowedException;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\TenantBucketService;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for initiating upload sessions.
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
 * This contract MUST be maintained across all services that interact with temporary uploads.
 *
 * RATE LIMITING:
 * - Current: Hard batch size limit (max 100 files per batch request)
 * - Future consideration: May want to implement max concurrent UploadSessions per tenant
 *   to prevent resource exhaustion, but this is not implemented now.
 */
class UploadInitiationService
{
    /**
     * Default expiration time for pre-signed URLs (in minutes).
     */
    protected const DEFAULT_EXPIRATION_MINUTES = 60;

    /**
     * File size threshold for multipart uploads (in bytes).
     * Files larger than this will use multipart upload.
     */
    protected const MULTIPART_THRESHOLD = 100 * 1024 * 1024; // 100 MB

    /**
     * Default chunk size for multipart uploads (in bytes).
     * 
     * Phase 2.6: Updated to 10MB to match MultipartUploadService::DEFAULT_CHUNK_SIZE.
     * This value is returned in the initiate response for informational purposes.
     * Actual chunk size is determined by MultipartUploadService when /multipart/init is called.
     */
    protected const DEFAULT_CHUNK_SIZE = 10 * 1024 * 1024; // 10 MB (matches MultipartUploadService)

    /**
     * Create a new instance.
     */
    public function __construct(
        protected PlanService $planService,
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Initiate an upload session.
     *
     * @param Tenant $tenant
     * @param Brand|null $brand
     * @param string $fileName
     * @param int $fileSize
     * @param string|null $mimeType
     * @param string|null $clientReference Optional client reference UUID for frontend mapping
     * @return array{upload_session_id: string, client_reference: string|null, upload_type: string, upload_url: string|null, multipart_upload_id: string|null, chunk_size: int|null, expires_at: string}
     * @throws PlanLimitExceededException
     * @throws \Exception
     */
    public function initiate(
        Tenant $tenant,
        ?Brand $brand,
        string $fileName,
        int $fileSize,
        ?string $mimeType = null,
        ?string $clientReference = null
    ): array {
        // Validate plan limits
        $this->validatePlanLimits($tenant, $fileSize);

        // [DIAGNOSTIC] Trace tenant + bucket context before resolve (read-only)
        Log::info('[UPLOAD_BUCKET_TRACE]', [
            'env' => app()->environment(),
            'tenant_id' => $tenant->id ?? null,
            'tenant_slug' => $tenant->slug ?? null,
            'brand_id' => $brand?->id ?? null,
            'brand_slug' => $brand?->slug ?? null,
            'request_path' => request()->path(),
            'user_id' => auth()->id(),
        ]);

        // Get or provision storage bucket
        $bucket = $this->getOrProvisionBucket($tenant);

        // Determine upload type
        $uploadType = $this->determineUploadType($fileSize);

        // Calculate expiration time
        $expiresAt = now()->addMinutes(self::DEFAULT_EXPIRATION_MINUTES);

        // Create upload session (represents upload attempt, not resulting asset)
        // One UploadSession per file - each file gets its own session
        $uploadSession = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand?->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::INITIATING,
            'type' => $uploadType,
            'expected_size' => $fileSize,
            'uploaded_size' => null, // Will be updated as upload progresses
            'expires_at' => $expiresAt,
            'failure_reason' => null,
            'client_reference' => $clientReference, // Optional client reference for frontend mapping
            'last_activity_at' => now(), // Initialize activity tracking for abandoned session detection
        ]);

        // Generate S3 path using immutable contract: temp/uploads/{upload_session_id}/original
        // Path is deterministic and based solely on upload_session_id
        $path = $this->generateTempUploadPath($tenant, $brand, $uploadSession->id);

        // Generate signed URLs
        if ($uploadType === UploadType::DIRECT) {
            $uploadUrl = $this->generateDirectUploadUrl($bucket, $path, $mimeType, $expiresAt);
            $multipartUploadId = null;
            $chunkSize = null;
        } else {
            // Phase 2.6: Multipart uploads enabled
            // Frontend will call /multipart/init after session creation
            // Return null values - frontend handles multipart initiation
            $uploadUrl = null;
            $multipartUploadId = null;
            $chunkSize = self::DEFAULT_CHUNK_SIZE; // 10MB default (matches MultipartUploadService)
        }

        Log::info('[Upload Lifecycle] Upload session initiated', [
            'upload_session_id' => $uploadSession->id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand?->id,
            'expected_size' => $fileSize,
            'upload_type' => $uploadType->value,
            'expires_at' => $expiresAt->toIso8601String(),
            'client_reference' => $clientReference,
            'lifecycle_stage' => 'initiated',
        ]);

        return [
            'upload_session_id' => $uploadSession->id,
            'client_reference' => $clientReference, // Return for frontend mapping
            'upload_session_status' => $uploadSession->status->value, // Current status (should be "initiating")
            'upload_type' => $uploadType->value,
            'upload_url' => $uploadUrl,
            'multipart_upload_id' => $multipartUploadId,
            'chunk_size' => $chunkSize,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Initiate an upload session in replace mode.
     * 
     * Phase J.3.1: File-only replacement for rejected contributor assets
     * 
     * Creates an upload session with mode='replace' and asset_id set.
     * The upload will replace the file of the existing asset without
     * modifying metadata or creating a new asset.
     * 
     * @param Tenant $tenant
     * @param Brand $brand
     * @param Asset $asset The asset whose file will be replaced
     * @param string $fileName
     * @param int $fileSize
     * @param string|null $mimeType
     * @param string|null $clientReference Optional client reference UUID for frontend mapping
     * @return array{upload_session_id: string, client_reference: string|null, upload_type: string, upload_url: string|null, multipart_upload_id: string|null, chunk_size: int|null, expires_at: string}
     * @throws PlanLimitExceededException
     * @throws \Exception
     */
    public function initiateReplace(
        Tenant $tenant,
        Brand $brand,
        \App\Models\Asset $asset,
        string $fileName,
        int $fileSize,
        ?string $mimeType = null,
        ?string $clientReference = null
    ): array {
        // Validate plan limits
        $this->validatePlanLimits($tenant, $fileSize);

        // [DIAGNOSTIC] Trace tenant + bucket context before resolve (read-only)
        Log::info('[UPLOAD_BUCKET_TRACE]', [
            'env' => app()->environment(),
            'tenant_id' => $tenant->id ?? null,
            'tenant_slug' => $tenant->slug ?? null,
            'brand_id' => $brand->id ?? null,
            'brand_slug' => $brand->slug ?? null,
            'request_path' => request()->path(),
            'user_id' => auth()->id(),
        ]);

        // Get or provision storage bucket
        $bucket = $this->getOrProvisionBucket($tenant);

        // Determine upload type
        $uploadType = $this->determineUploadType($fileSize);

        // Calculate expiration time
        $expiresAt = now()->addMinutes(self::DEFAULT_EXPIRATION_MINUTES);

        // Create upload session in replace mode
        $uploadSession = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::INITIATING,
            'type' => $uploadType,
            'mode' => 'replace', // Phase J.3.1: Replace mode
            'asset_id' => $asset->id, // Phase J.3.1: Asset being replaced
            'expected_size' => $fileSize,
            'uploaded_size' => null,
            'expires_at' => $expiresAt,
            'failure_reason' => null,
            'client_reference' => $clientReference,
            'last_activity_at' => now(),
        ]);

        // Generate S3 path using immutable contract: temp/uploads/{upload_session_id}/original
        $path = $this->generateTempUploadPath($tenant, $brand, $uploadSession->id);

        // Generate signed URLs
        if ($uploadType === UploadType::DIRECT) {
            $uploadUrl = $this->generateDirectUploadUrl($bucket, $path, $mimeType, $expiresAt);
            $multipartUploadId = null;
            $chunkSize = null;
        } else {
            $uploadUrl = null;
            $multipartUploadId = null;
            $chunkSize = self::DEFAULT_CHUNK_SIZE;
        }

        Log::info('[Upload Lifecycle] Replace file upload session initiated', [
            'upload_session_id' => $uploadSession->id,
            'asset_id' => $asset->id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'expected_size' => $fileSize,
            'upload_type' => $uploadType->value,
            'expires_at' => $expiresAt->toIso8601String(),
            'client_reference' => $clientReference,
            'lifecycle_stage' => 'initiated',
            'mode' => 'replace',
        ]);

        return [
            'upload_session_id' => $uploadSession->id,
            'client_reference' => $clientReference,
            'upload_session_status' => $uploadSession->status->value,
            'upload_type' => $uploadType->value,
            'upload_url' => $uploadUrl,
            'multipart_upload_id' => $multipartUploadId,
            'chunk_size' => $chunkSize,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Initiate multiple upload sessions in parallel (batch upload).
     *
     * Each file is processed in its own transaction to ensure isolation.
     * One file failing must never affect others at the DB level.
     *
     * RATE LIMITING NOTES:
     * - Current: Hard batch size limit (max 100 files per batch)
     * - Future consideration: May want to add max concurrent UploadSessions per tenant
     *   to prevent resource exhaustion, but not implemented now.
     *
     * @param Tenant $tenant
     * @param Brand|null $brand
     * @param array<array{file_name: string, file_size: int, mime_type: string|null, client_reference: string|null}> $files
     * @param string|null $batchReference Optional batch-level correlation ID for grouping/debugging/analytics
     * @return array<array{upload_session_id: string, client_reference: string|null, batch_reference: string|null, upload_session_status: string, upload_type: string, upload_url: string|null, multipart_upload_id: string|null, chunk_size: int|null, expires_at: string, error: string|null}>
     */
    public function initiateBatch(
        Tenant $tenant,
        ?Brand $brand,
        array $files,
        ?string $batchReference = null
    ): array {
        $results = [];

        // [DIAGNOSTIC] Trace tenant + bucket context before resolve (read-only)
        Log::info('[UPLOAD_BUCKET_TRACE]', [
            'env' => app()->environment(),
            'tenant_id' => $tenant->id ?? null,
            'tenant_slug' => $tenant->slug ?? null,
            'brand_id' => $brand?->id ?? null,
            'brand_slug' => $brand?->slug ?? null,
            'request_path' => request()->path(),
            'user_id' => auth()->id(),
        ]);

        // Get or provision bucket once before processing files (shared resource).
        // In staging/production only resolve is used; no provisioning. Semantic exceptions are rethrown unchanged.
        $bucket = null;
        try {
            $bucket = $this->getOrProvisionBucket($tenant);
        } catch (BucketNotProvisionedException|BucketProvisioningNotAllowedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to get or provision bucket for batch upload', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            foreach ($files as $file) {
                $results[] = [
                    'upload_session_id' => null,
                    'client_reference' => $file['client_reference'] ?? null,
                    'batch_reference' => $batchReference,
                    'upload_session_status' => null,
                    'upload_type' => null,
                    'upload_url' => null,
                    'multipart_upload_id' => null,
                    'chunk_size' => null,
                    'expires_at' => null,
                    'error' => 'Storage bucket unavailable.',
                ];
            }
            return $results;
        }

        // Process each file in isolation with its own transaction
        foreach ($files as $file) {
            DB::beginTransaction();
            try {
                // Validate plan limits for this file
                $this->validatePlanLimits($tenant, $file['file_size']);

                // Determine upload type
                $uploadType = $this->determineUploadType($file['file_size']);

                // Calculate expiration time
                $expiresAt = now()->addMinutes(self::DEFAULT_EXPIRATION_MINUTES);

                // Create upload session (DB operation - part of transaction)
                $uploadSession = UploadSession::create([
                    'tenant_id' => $tenant->id,
                    'brand_id' => $brand?->id,
                    'storage_bucket_id' => $bucket->id,
                    'status' => UploadStatus::INITIATING,
                    'type' => $uploadType,
                    'expected_size' => $file['file_size'],
                    'uploaded_size' => null,
                    'expires_at' => $expiresAt,
                    'failure_reason' => null,
                    'client_reference' => $file['client_reference'] ?? null,
                    'last_activity_at' => now(), // Initialize activity tracking for abandoned session detection
                ]);

                // Generate S3 path using immutable contract: temp/uploads/{upload_session_id}/original
                // Path is deterministic and based solely on upload_session_id
                $path = $this->generateTempUploadPath($tenant, $brand, $uploadSession->id);

                // Generate signed URLs (external S3 operation - if this fails, we rollback the DB transaction)
                if ($uploadType === UploadType::DIRECT) {
                    $uploadUrl = $this->generateDirectUploadUrl($bucket, $path, $file['mime_type'] ?? null, $expiresAt);
                    $multipartUploadId = null;
                    $chunkSize = null;
                } else {
                    // Phase 2.6: Multipart uploads enabled
                    // Frontend will call /multipart/init after session creation
                    // Return null values - frontend handles multipart initiation
                    $uploadUrl = null;
                    $multipartUploadId = null;
                    $chunkSize = self::DEFAULT_CHUNK_SIZE; // 10MB default (matches MultipartUploadService)
                }

                // Commit transaction - all DB operations succeeded
                DB::commit();

                // Update multipart_upload_id if it was set (must be done after transaction commit)
                if (isset($multipartUploadId) && $multipartUploadId) {
                    $uploadSession->update([
                        'multipart_upload_id' => $multipartUploadId,
                        'last_activity_at' => now(),
                    ]);
                }

                Log::info('Upload session initiated in batch', [
                    'upload_session_id' => $uploadSession->id,
                    'tenant_id' => $tenant->id,
                    'brand_id' => $brand?->id,
                    'expected_size' => $file['file_size'],
                    'upload_type' => $uploadType->value,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'multipart_upload_id' => $multipartUploadId ?? null,
                ]);

                // Return success result for this file
                $results[] = [
                    'upload_session_id' => $uploadSession->id,
                    'client_reference' => $file['client_reference'] ?? null,
                    'batch_reference' => $batchReference, // Batch-level correlation ID for grouping/debugging/analytics
                    'upload_session_status' => $uploadSession->status->value, // Current status (should be "initiating")
                    'upload_type' => $uploadType->value,
                    'upload_url' => $uploadUrl,
                    'multipart_upload_id' => $multipartUploadId,
                    'chunk_size' => $chunkSize,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                // Rollback transaction for this file - ensures isolation
                DB::rollBack();

                Log::error('Failed to initiate upload session in batch', [
                    'tenant_id' => $tenant->id,
                    'file_name' => $file['file_name'] ?? 'unknown',
                    'client_reference' => $file['client_reference'] ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Return error result for this file (doesn't affect other files)
                $results[] = [
                    'upload_session_id' => null,
                    'client_reference' => $file['client_reference'] ?? null,
                    'batch_reference' => $batchReference, // Batch-level correlation ID even for failed initiations
                    'upload_session_status' => null, // No status for failed initiations
                    'upload_type' => null,
                    'upload_url' => null,
                    'multipart_upload_id' => null,
                    'chunk_size' => null,
                    'expires_at' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Cancel an upload session.
     *
     * This method is idempotent - safe to call multiple times.
     * If the session is already in a terminal state (cancelled, failed, expired, completed),
     * it returns early without throwing errors or reattempting cleanup.
     *
     * @param UploadSession $uploadSession
     * @return bool True if cancellation was performed, false if already in terminal state
     */
    public function cancel(UploadSession $uploadSession): bool
    {
        // Check if already in a terminal state - idempotent behavior
        if ($uploadSession->isTerminal()) {
            Log::info('Upload session cancellation called but already in terminal state (idempotent)', [
                'upload_session_id' => $uploadSession->id,
                'current_status' => $uploadSession->status->value,
                'is_expired' => $uploadSession->expires_at && $uploadSession->expires_at->isPast(),
                'expires_at' => $uploadSession->expires_at?->toIso8601String(),
            ]);
            return false; // Already in terminal state, nothing to do
        }

        // Check if transition to CANCELLED is allowed (using guard method)
        if (!$uploadSession->canTransitionTo(UploadStatus::CANCELLED)) {
            Log::warning('Attempted to cancel upload session but transition is not allowed', [
                'upload_session_id' => $uploadSession->id,
                'current_status' => $uploadSession->status->value,
                'is_expired' => $uploadSession->expires_at && $uploadSession->expires_at->isPast(),
            ]);
            return false; // Can't cancel from this state
        }

        // Update status to cancelled (transition is valid)
        $uploadSession->update([
            'status' => UploadStatus::CANCELLED,
            'failure_reason' => 'Cancelled by user',
        ]);

        // Clean up S3 upload if in progress (best-effort)
        $this->cleanupIncompleteUpload($uploadSession);

        Log::info('Upload session cancelled', [
            'upload_session_id' => $uploadSession->id,
            'client_reference' => $uploadSession->client_reference,
            'tenant_id' => $uploadSession->tenant_id,
        ]);

        return true; // Cancellation was performed
    }

    /**
     * Mark upload session as actively uploading (transition INITIATING → UPLOADING).
     *
     * This should be called when the client actually starts uploading data.
     * Updates last_activity_at to prevent abandoned session detection.
     *
     * @param UploadSession $uploadSession
     * @return bool True if transition was performed, false if already UPLOADING or cannot transition
     */
    public function markAsUploading(UploadSession $uploadSession): bool
    {
        // Refresh to get latest state
        $uploadSession->refresh();

        // Check if already UPLOADING (idempotent)
        if ($uploadSession->status === UploadStatus::UPLOADING) {
            // Still update activity timestamp
            $uploadSession->update(['last_activity_at' => now()]);
            return false; // No transition needed, but activity updated
        }

        // Check if transition to UPLOADING is allowed
        if (!$uploadSession->canTransitionTo(UploadStatus::UPLOADING)) {
            Log::warning('Cannot transition upload session to UPLOADING', [
                'upload_session_id' => $uploadSession->id,
                'current_status' => $uploadSession->status->value,
                'is_expired' => $uploadSession->expires_at && $uploadSession->expires_at->isPast(),
            ]);
            return false;
        }

        // Transition to UPLOADING and update activity
        $uploadSession->update([
            'status' => UploadStatus::UPLOADING,
            'last_activity_at' => now(),
        ]);

        Log::info('[Upload Lifecycle] Upload session marked as UPLOADING', [
            'upload_session_id' => $uploadSession->id,
            'tenant_id' => $uploadSession->tenant_id,
            'lifecycle_stage' => 'uploading',
        ]);

        return true;
    }

    /**
     * Clean up incomplete upload from S3 (best-effort).
     *
     * @param UploadSession $uploadSession
     * @return void
     */
    protected function cleanupIncompleteUpload(UploadSession $uploadSession): void
    {
        $bucket = $uploadSession->storageBucket;
        if (!$bucket) {
            return;
        }

        try {
            $s3Client = $this->createS3Client();
            // Generate deterministic path using upload_session_id (immutable contract)
            $path = $this->generateTempUploadPath(
                $uploadSession->tenant ?? app('tenant'), // Fallback for compatibility
                $uploadSession->brand ?? null, // Fallback for compatibility
                $uploadSession->id
            );

            // Check if object exists and delete it
            if ($s3Client->doesObjectExist($bucket->name, $path)) {
                $s3Client->deleteObject([
                    'Bucket' => $bucket->name,
                    'Key' => $path,
                ]);

                Log::info('Cleaned up incomplete upload from S3', [
                    'upload_session_id' => $uploadSession->id,
                    'bucket' => $bucket->name,
                    'path' => $path,
                ]);
            }

            // For multipart uploads, abort the multipart upload if it exists
            // Note: We'd need to store multipart upload ID somewhere to do this
            // For now, this is best-effort cleanup
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup incomplete upload from S3', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - cleanup is best-effort
        }
    }

    /**
     * Validate plan limits for upload.
     *
     * @param Tenant $tenant
     * @param int $fileSize
     * @return void
     * @throws PlanLimitExceededException
     */
    protected function validatePlanLimits(Tenant $tenant, int $fileSize): void
    {
        // Check individual file size limit
        $maxUploadSize = $this->planService->getMaxUploadSize($tenant);

        if ($fileSize > $maxUploadSize) {
            throw new PlanLimitExceededException(
                'upload_size',
                $fileSize,
                $maxUploadSize,
                "File size ({$fileSize} bytes) exceeds maximum upload size ({$maxUploadSize} bytes) for your plan."
            );
        }

        // Check total storage limit
        $this->planService->enforceStorageLimit($tenant, $fileSize);
    }

    /**
     * Get or provision storage bucket for tenant.
     *
     * Staging/production: resolve only (never provisions; missing bucket throws).
     * Local/testing: resolve or provision synchronously.
     *
     * @param Tenant $tenant
     * @return StorageBucket
     * @throws \Exception
     */
    protected function getOrProvisionBucket(Tenant $tenant): StorageBucket
    {
        $bucket = app(TenantBucketService::class)->getOrProvisionBucket($tenant);

        if ($bucket->status === \App\Enums\StorageBucketStatus::PROVISIONING) {
            throw new \RuntimeException('Storage bucket is being provisioned. Please try again in a few moments.');
        }

        return $bucket;
    }

    /**
     * Determine upload type based on file size.
     *
     * @param int $fileSize
     * @return UploadType
     */
    protected function determineUploadType(int $fileSize): UploadType
    {
        return $fileSize > self::MULTIPART_THRESHOLD
            ? UploadType::CHUNKED
            : UploadType::DIRECT;
    }

    /**
     * Generate temporary S3 path for upload.
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
     * The path MUST be deterministic and based solely on upload_session_id to ensure:
     *   1. Multiple services can generate the same path independently
     *   2. Cleanup jobs can locate temp files without additional metadata
     *   3. Resume/retry logic can find partial uploads reliably
     *
     * @param Tenant $tenant Unused - kept for backward compatibility, path is now session-only
     * @param Brand|null $brand Unused - kept for backward compatibility, path is now session-only
     * @param string $uploadSessionId The unique upload session UUID
     * @return string S3 key path: temp/uploads/{upload_session_id}/original
     */
    protected function generateTempUploadPath(Tenant $tenant, ?Brand $brand, string $uploadSessionId): string
    {
        // IMMUTABLE: This path format must never change
        // Path is deterministic and based solely on upload_session_id
        return "temp/uploads/{$uploadSessionId}/original";
    }

    /**
     * Generate pre-signed PUT URL for direct upload.
     *
     * @param StorageBucket $bucket
     * @param string $path
     * @param string|null $mimeType
     * @param \Illuminate\Support\Carbon $expiresAt
     * @return string
     */
    protected function generateDirectUploadUrl(
        StorageBucket $bucket,
        string $path,
        ?string $mimeType,
        \Illuminate\Support\Carbon $expiresAt
    ): string {
        $params = [
            'Bucket' => $bucket->name,
            'Key' => $path,
        ];

        // Add content type if provided
        if ($mimeType) {
            $params['ContentType'] = $mimeType;
        }

        // Generate pre-signed URL
        $command = $this->s3Client->getCommand('PutObject', $params);
        $presignedRequest = $this->s3Client->createPresignedRequest(
            $command,
            '+' . self::DEFAULT_EXPIRATION_MINUTES . ' minutes'
        );

        return (string) $presignedRequest->getUri();
    }

    /**
     * Initiate multipart upload.
     *
     * @param StorageBucket $bucket
     * @param string $path
     * @param string|null $mimeType
     * @return array{UploadId: string}
     */
    protected function initiateMultipartUpload(
        StorageBucket $bucket,
        string $path,
        ?string $mimeType
    ): array {
        $params = [
            'Bucket' => $bucket->name,
            'Key' => $path,
        ];

        if ($mimeType) {
            $params['ContentType'] = $mimeType;
        }

        $result = $this->s3Client->createMultipartUpload($params);

        return [
            'UploadId' => $result->get('UploadId'),
        ];
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
                'AWS SDK for PHP is required for upload initiation. ' .
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
