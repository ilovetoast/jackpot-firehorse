<?php

namespace App\Services;

use App\Enums\StorageBucketStatus;
use App\Exceptions\BucketNotProvisionedException;
use App\Exceptions\BucketProvisioningNotAllowedException;
use App\Models\StorageBucket;
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
 * Resolves and provisions S3 buckets for tenants.
 *
 * RULES:
 * - Web requests must NEVER create buckets. Use resolveActiveBucketOrFail or getOrProvisionBucket (staging/prod = resolve only).
 * - Bucket creation ONLY in console or queued jobs via provisionBucket().
 * - Local/testing: getOrProvisionBucket may provision synchronously.
 * - Staging/production: buckets must already exist; missing bucket = clear error.
 */
class TenantBucketService
{
    public function __construct(
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $this->s3Client ?? $this->createS3Client();
    }

    /**
     * Resolve the ACTIVE StorageBucket for a tenant (read-only, never provisions).
     *
     * @throws BucketNotProvisionedException If no active bucket exists (message instructs to run tenants:ensure-buckets)
     */
    public function resolveActiveBucketOrFail(Tenant $tenant): StorageBucket
    {
        $expectedName = $this->getExpectedBucketName($tenant);

        // [DIAGNOSTIC] Table stats and resolve attempt (read-only)
        Log::info('[STORAGE_BUCKET_TABLE_STATS]', [
            'total' => StorageBucket::count(),
            'active' => StorageBucket::where('status', StorageBucketStatus::ACTIVE)->count(),
        ]);
        Log::info('[BUCKET_RESOLVE_ATTEMPT]', [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'expected_bucket' => $expectedName,
            'env' => app()->environment(),
        ]);

        $bucket = StorageBucket::where('tenant_id', $tenant->id)
            ->where('name', $expectedName)
            ->where('status', StorageBucketStatus::ACTIVE)
            ->first();

        if ($bucket) {
            Log::info('[BUCKET_RESOLVE_SUCCESS]', [
                'tenant_id' => $tenant->id,
                'bucket_id' => $bucket->id,
                'bucket_name' => $bucket->name,
                'status' => $bucket->status?->value ?? (string) $bucket->status,
            ]);

            return $bucket;
        }

        Log::error('[BUCKET_RESOLVE_FAILED]', [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'expected_bucket' => $expectedName,
            'env' => app()->environment(),
        ]);

        throw new BucketNotProvisionedException($tenant->id);
    }

    /**
     * Provision bucket infrastructure (CreateBucket, CORS, versioning, etc.).
     * MUST NOT be called from web requests in staging/production.
     * Guard runs before any AWS client is constructed (provisioner is only resolved after guard passes).
     *
     * @throws BucketProvisioningNotAllowedException If called from web in staging/production (guard)
     */
    public function provisionBucket(Tenant $tenant): StorageBucket
    {
        // Guard FIRST: no AWS SDK usage until this passes. Queue workers run in console.
        if (! $this->isProvisioningAllowed() && $this->isStagingOrProduction()) {
            throw new BucketProvisioningNotAllowedException(config('app.env'));
        }

        return app(CompanyStorageProvisioner::class)->provision($tenant);
    }

    /**
     * Get bucket for upload/asset flows. Local/testing: resolve or provision. Staging/production: resolve only (never provisions).
     */
    public function getOrProvisionBucket(Tenant $tenant): StorageBucket
    {
        if ($this->isLocalOrTesting()) {
            $bucket = $this->resolveActiveBucketOrFailIfExists($tenant);
            if ($bucket) {
                return $bucket;
            }

            return $this->provisionBucket($tenant);
        }

        return $this->resolveActiveBucketOrFail($tenant);
    }

    /**
     * Get the expected bucket name for a tenant (no DB lookup).
     * Local: shared bucket config. Staging/Production: per-tenant generated name.
     */
    public function getBucketName(Tenant $tenant): string
    {
        return $this->getExpectedBucketName($tenant);
    }

    /**
     * Resolve ACTIVE bucket if it exists (no throw). Used by getOrProvisionBucket in local/testing.
     */
    protected function resolveActiveBucketOrFailIfExists(Tenant $tenant): ?StorageBucket
    {
        $expectedName = $this->getExpectedBucketName($tenant);

        return StorageBucket::where('tenant_id', $tenant->id)
            ->where('name', $expectedName)
            ->where('status', StorageBucketStatus::ACTIVE)
            ->first();
    }

    /**
     * Expected bucket name for tenant (no DB). Local: shared_bucket. Staging/Production: generated name.
     */
    protected function getExpectedBucketName(Tenant $tenant): string
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

    protected function isLocalOrTesting(): bool
    {
        return in_array(config('app.env'), ['local', 'testing'], true);
    }

    protected function isStagingOrProduction(): bool
    {
        return in_array(config('app.env'), ['staging', 'production'], true);
    }

    /**
     * Provisioning allowed only in console (includes queue workers) or local/testing.
     */
    protected function isProvisioningAllowed(): bool
    {
        if (app()->runningInConsole()) {
            return true;
        }

        return $this->isLocalOrTesting();
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

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }
}
