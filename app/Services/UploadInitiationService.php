<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\CompanyStorageProvisioner;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     */
    protected const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB

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
     * @return array{upload_session_id: string, upload_type: string, upload_url: string|null, multipart_upload_id: string|null, chunk_size: int|null, expires_at: string}
     * @throws PlanLimitExceededException
     * @throws \Exception
     */
    public function initiate(
        Tenant $tenant,
        ?Brand $brand,
        string $fileName,
        int $fileSize,
        ?string $mimeType = null
    ): array {
        // Validate plan limits
        $this->validatePlanLimits($tenant, $fileSize);

        // Get or provision storage bucket
        $bucket = $this->getOrProvisionBucket($tenant);

        // Determine upload type
        $uploadType = $this->determineUploadType($fileSize);

        // Calculate expiration time
        $expiresAt = now()->addMinutes(self::DEFAULT_EXPIRATION_MINUTES);

        // Create upload session (represents upload attempt, not resulting asset)
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
        ]);

        // Generate S3 path using upload_session_id (deterministic, temporary path for upload)
        $path = $this->generateTempUploadPath($tenant, $brand, $uploadSession->id);

        // Generate signed URLs
        if ($uploadType === UploadType::DIRECT) {
            $uploadUrl = $this->generateDirectUploadUrl($bucket, $path, $mimeType, $expiresAt);
            $multipartUploadId = null;
            $chunkSize = null;
        } else {
            // Multipart upload initiation
            $multipartUpload = $this->initiateMultipartUpload($bucket, $path, $mimeType);
            $uploadUrl = null; // No single URL for multipart
            $multipartUploadId = $multipartUpload['UploadId'];
            $chunkSize = self::DEFAULT_CHUNK_SIZE;

            // Store multipart upload info (temporary, will be cleaned up after completion)
            // Note: We can't store this in UploadSession metadata anymore, so we'll handle it differently
            // For now, the multipart upload ID is returned to the client
        }

        Log::info('Upload session initiated', [
            'upload_session_id' => $uploadSession->id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand?->id,
            'expected_size' => $fileSize,
            'upload_type' => $uploadType->value,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return [
            'upload_session_id' => $uploadSession->id,
            'upload_type' => $uploadType->value,
            'upload_url' => $uploadUrl,
            'multipart_upload_id' => $multipartUploadId,
            'chunk_size' => $chunkSize,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
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
        $maxUploadSize = $this->planService->getMaxUploadSize($tenant);

        if ($fileSize > $maxUploadSize) {
            throw new PlanLimitExceededException(
                'upload_size',
                $fileSize,
                $maxUploadSize,
                "File size ({$fileSize} bytes) exceeds maximum upload size ({$maxUploadSize} bytes) for your plan."
            );
        }
    }

    /**
     * Get or provision storage bucket for tenant.
     *
     * @param Tenant $tenant
     * @return StorageBucket
     * @throws \Exception
     */
    protected function getOrProvisionBucket(Tenant $tenant): StorageBucket
    {
        $bucket = StorageBucket::where('tenant_id', $tenant->id)
            ->where('status', \App\Enums\StorageBucketStatus::ACTIVE)
            ->first();

        if (!$bucket) {
            // Provision bucket (this will queue the job)
            $provisioner = app(CompanyStorageProvisioner::class);
            $bucket = $provisioner->provision($tenant);

            // If bucket is still provisioning, wait a bit or throw
            if ($bucket->status === \App\Enums\StorageBucketStatus::PROVISIONING) {
                throw new \RuntimeException('Storage bucket is being provisioned. Please try again in a few moments.');
            }
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
     * Generate temporary S3 path for upload (deterministic based on upload_session_id).
     *
     * @param Tenant $tenant
     * @param Brand|null $brand
     * @param string $uploadSessionId
     * @return string
     */
    protected function generateTempUploadPath(Tenant $tenant, ?Brand $brand, string $uploadSessionId): string
    {
        $basePath = "uploads/{$tenant->id}";

        if ($brand) {
            $basePath .= "/{$brand->id}";
        }

        // Use upload_session_id as the path (deterministic, temporary)
        return "{$basePath}/{$uploadSessionId}";
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
