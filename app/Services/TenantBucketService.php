<?php

namespace App\Services;

use App\Models\Tenant;
use App\Services\TenantBucket\EnsureBucketResult;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TenantBucketService
 *
 * Resolves and ensures S3 bucket existence for tenants.
 * - Local: uses shared test bucket (no creation)
 * - Staging/Production: per-tenant buckets with versioning
 *
 * No automatic execution. No observers. No event listeners. No side effects on tenant creation.
 */
class TenantBucketService
{
    public function __construct(
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $this->s3Client ?? $this->createS3Client();
    }

    /**
     * Get the bucket name for a tenant.
     * Local: shared bucket. Staging/Production: per-tenant bucket.
     */
    public function getBucketName(Tenant $tenant): string
    {
        if ($this->isLocal()) {
            $name = config('storage.shared_bucket');
            if (! $name) {
                throw new RuntimeException('Shared bucket not configured. Set AWS_BUCKET in .env for local.');
            }
            return $name;
        }

        return $this->generateBucketName($tenant);
    }

    /**
     * Check if the tenant's bucket exists in S3.
     *
     * @throws RuntimeException when AWS call fails
     */
    public function bucketExists(Tenant $tenant): bool
    {
        $bucketName = $this->getBucketName($tenant);

        try {
            return $this->s3Client->doesBucketExist($bucketName);
        } catch (S3Exception $e) {
            throw new RuntimeException(
                "Failed to check bucket existence: {$bucketName}. {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Ensure the tenant's bucket exists.
     * If exists → no-op (skipped).
     * If missing → create bucket + enable versioning (staging/prod only).
     * Local uses shared bucket; does not create buckets.
     */
    public function ensureBucketExists(Tenant $tenant): EnsureBucketResult
    {
        $bucketName = $this->getBucketName($tenant);

        try {
            $exists = $this->s3Client->doesBucketExist($bucketName);
        } catch (S3Exception $e) {
            Log::error('[TenantBucketService] ensureBucketExists failed: check existence', [
                'tenant_id' => $tenant->id,
                'bucket_name' => $bucketName,
                'error' => $e->getMessage(),
            ]);
            return new EnsureBucketResult(
                EnsureBucketResult::OUTCOME_FAILED,
                $bucketName,
                "Failed to check bucket existence: {$e->getMessage()}"
            );
        }

        if ($exists) {
            Log::info('[TenantBucketService] ensureBucketExists skipped: bucket already exists', [
                'tenant_id' => $tenant->id,
                'bucket_name' => $bucketName,
            ]);
            return new EnsureBucketResult(EnsureBucketResult::OUTCOME_SKIPPED, $bucketName);
        }

        if ($this->isLocal()) {
            Log::warning('[TenantBucketService] ensureBucketExists failed: shared bucket missing (local does not create buckets)', [
                'tenant_id' => $tenant->id,
                'bucket_name' => $bucketName,
            ]);
            return new EnsureBucketResult(
                EnsureBucketResult::OUTCOME_FAILED,
                $bucketName,
                'Shared bucket does not exist. Create it manually for local development.'
            );
        }

        try {
            $this->createBucket($bucketName);
            $this->enableVersioning($bucketName);
            Log::info('[TenantBucketService] ensureBucketExists created bucket', [
                'tenant_id' => $tenant->id,
                'bucket_name' => $bucketName,
            ]);
            return new EnsureBucketResult(EnsureBucketResult::OUTCOME_CREATED, $bucketName);
        } catch (S3Exception $e) {
            Log::error('[TenantBucketService] ensureBucketExists failed: create or configure', [
                'tenant_id' => $tenant->id,
                'bucket_name' => $bucketName,
                'error' => $e->getMessage(),
            ]);
            return new EnsureBucketResult(
                EnsureBucketResult::OUTCOME_FAILED,
                $bucketName,
                "Failed to create bucket or enable versioning: {$e->getMessage()}"
            );
        } catch (\Throwable $e) {
            Log::error('[TenantBucketService] ensureBucketExists failed: unexpected error', [
                'tenant_id' => $tenant->id,
                'bucket_name' => $bucketName,
                'error' => $e->getMessage(),
            ]);
            return new EnsureBucketResult(
                EnsureBucketResult::OUTCOME_FAILED,
                $bucketName,
                $e->getMessage()
            );
        }
    }

    protected function isLocal(): bool
    {
        return config('app.env') === 'local';
    }

    protected function generateBucketName(Tenant $tenant): string
    {
        $pattern = config('storage.bucket_name_pattern', '{env}-dam-{company_slug}');
        $env = config('app.env', 'local');

        $name = str_replace(
            ['{env}', '{company_id}', '{company_slug}'],
            [$env, $tenant->id, $tenant->slug],
            $pattern
        );

        $name = Str::lower($name);
        $name = preg_replace('/[^a-z0-9-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        if (strlen($name) > 63) {
            $name = substr($name, 0, 63);
            $name = rtrim($name, '-');
        }

        if (strlen($name) < 3) {
            $name = $name . '-' . Str::random(3);
        }

        return $name;
    }

    protected function createBucket(string $bucketName): void
    {
        $region = config('storage.default_region', 'us-east-1');

        $params = [
            'Bucket' => $bucketName,
        ];

        if ($region !== 'us-east-1') {
            $params['CreateBucketConfiguration'] = [
                'LocationConstraint' => $region,
            ];
        }

        $this->s3Client->createBucket($params);
    }

    protected function enableVersioning(string $bucketName): void
    {
        if (! config('storage.bucket_config.versioning', true)) {
            return;
        }

        $result = $this->s3Client->getBucketVersioning(['Bucket' => $bucketName]);
        if ($result->get('Status') === 'Enabled') {
            return;
        }

        $this->s3Client->putBucketVersioning([
            'Bucket' => $bucketName,
            'VersioningConfiguration' => [
                'Status' => 'Enabled',
            ],
        ]);
    }

    protected function createS3Client(): S3Client
    {
        if (! class_exists(S3Client::class)) {
            throw new RuntimeException(
                'AWS SDK for PHP is required. Install via: composer require aws/aws-sdk-php'
            );
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        if (config('filesystems.disks.s3.key') && config('filesystems.disks.s3.secret')) {
            $config['credentials'] = [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ];
        }

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }
}
