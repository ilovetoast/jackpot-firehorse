<?php

namespace App\Services;

use App\Enums\StorageBucketStatus;
use App\Exceptions\BucketProvisioningNotAllowedException;
use App\Models\StorageBucket;
use App\Models\Tenant;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompanyStorageProvisioner
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
     * Provision storage for a company (tenant).
     *
     * This method is idempotent and safe to retry. If the bucket already exists,
     * it will verify and update its configuration rather than failing.
     *
     * MUST NOT be called from web requests in staging/production (use TenantBucketService::provisionBucket which guards).
     *
     * @param Tenant $tenant
     * @return StorageBucket
     * @throws \Exception
     */
    public function provision(Tenant $tenant): StorageBucket
    {
        if (! app()->runningInConsole() && ! in_array(app()->environment(), ['local', 'testing'], true)) {
            throw new BucketProvisioningNotAllowedException(app()->environment());
        }

        $strategy = config('storage.provision_strategy', 'shared');

        return match ($strategy) {
            'per_company' => $this->provisionPerCompany($tenant),
            'shared' => $this->provisionShared($tenant),
            default => throw new \InvalidArgumentException("Unknown provision strategy: {$strategy}"),
        };
    }

    /**
     * Provision a dedicated bucket per company (production only).
     *
     * @param Tenant $tenant
     * @return StorageBucket
     * @throws \Exception
     */
    protected function provisionPerCompany(Tenant $tenant): StorageBucket
    {
        $expectedName = $this->generateBucketName($tenant);

        // Find existing bucket that matches current per_company naming (not a stale shared bucket)
        $existingBucket = StorageBucket::where('tenant_id', $tenant->id)
            ->where('name', $expectedName)
            ->where('status', '!=', StorageBucketStatus::DELETING)
            ->first();

        if ($existingBucket) {
            if ($existingBucket->status === StorageBucketStatus::ACTIVE) {
                $this->verifyBucketConfiguration($existingBucket->name);
                return $existingBucket;
            }

            if ($existingBucket->status === StorageBucketStatus::PROVISIONING) {
                $bucketName = $existingBucket->name;
                $region = $existingBucket->region;
            } else {
                $existingBucket->update(['status' => StorageBucketStatus::PROVISIONING]);
                $bucketName = $existingBucket->name;
                $region = $existingBucket->region;
            }
        } else {
            // No bucket with expected name (e.g. tenant had old shared bucket record). Create new one.
            $bucketName = $expectedName;
            $region = config('storage.default_region', 'us-east-1');

            $existingBucket = StorageBucket::create([
                'tenant_id' => $tenant->id,
                'name' => $bucketName,
                'status' => StorageBucketStatus::PROVISIONING,
                'region' => $region,
            ]);
        }

        try {
            // Check if bucket exists in S3
            $bucketExists = $this->bucketExists($bucketName);

            if (!$bucketExists) {
                // Create bucket
                $this->createBucket($bucketName, $region);
            }

            // Enable versioning (idempotent)
            $this->enableVersioning($bucketName);

            // Enable encryption (idempotent)
            $this->enableEncryption($bucketName);

            // Apply lifecycle rules (idempotent)
            $this->applyLifecycleRules($bucketName);

            // Apply CORS for browser presigned uploads (idempotent)
            $this->applyCors($bucketName);

            // Update status to active
            $existingBucket->update(['status' => StorageBucketStatus::ACTIVE]);

            return $existingBucket;
        } catch (S3Exception $e) {
            Log::error('Failed to provision storage bucket', [
                'tenant_id' => $tenant->id,
                'bucket_name' => $bucketName,
                'error' => $e->getMessage(),
                'code' => $e->getAwsErrorCode(),
            ]);

            // Keep bucket in PROVISIONING state on failure (safe to retry)
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error provisioning storage bucket', [
                'tenant_id' => $tenant->id,
                'bucket_name' => $bucketName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Provision using shared bucket (local/staging).
     *
     * @param Tenant $tenant
     * @return StorageBucket
     * @throws \Exception
     */
    protected function provisionShared(Tenant $tenant): StorageBucket
    {
        $sharedBucketName = config('storage.shared_bucket');

        if (!$sharedBucketName) {
            throw new \RuntimeException('Shared bucket name not configured. Set AWS_BUCKET in .env');
        }

        // Check if bucket record already exists
        $existingBucket = StorageBucket::where('tenant_id', $tenant->id)
            ->where('name', $sharedBucketName)
            ->where('status', '!=', StorageBucketStatus::DELETING)
            ->first();

        if ($existingBucket) {
            // Idempotency: If active, return existing
            if ($existingBucket->status === StorageBucketStatus::ACTIVE) {
                return $existingBucket;
            }

            // Update status to active (shared bucket is always active)
            $existingBucket->update(['status' => StorageBucketStatus::ACTIVE]);
            return $existingBucket;
        }

        // Create new bucket record for shared bucket
        $region = config('storage.default_region', 'us-east-1');

        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => $sharedBucketName,
            'status' => StorageBucketStatus::ACTIVE, // Shared bucket is immediately active
            'region' => $region,
        ]);

        return $bucket;
    }

    /**
     * Generate bucket name for a company.
     *
     * @param Tenant $tenant
     * @return string
     */
    protected function generateBucketName(Tenant $tenant): string
    {
        $pattern = config('storage.bucket_name_pattern', '{env}-dam-{company_slug}');
        $env = config('app.env', 'local');

        $name = str_replace(
            ['{env}', '{company_id}', '{company_slug}'],
            [$env, $tenant->id, $tenant->slug],
            $pattern
        );

        // Ensure bucket name is valid for S3
        // S3 bucket names must be lowercase, 3-63 characters, alphanumeric and hyphens only
        $name = Str::lower($name);
        $name = preg_replace('/[^a-z0-9-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        // Ensure length
        if (strlen($name) > 63) {
            $name = substr($name, 0, 63);
            $name = rtrim($name, '-');
        }

        if (strlen($name) < 3) {
            $name = $name . '-' . Str::random(3);
        }

        return $name;
    }

    /**
     * Check if bucket exists in S3.
     *
     * @param string $bucketName
     * @return bool
     */
    protected function bucketExists(string $bucketName): bool
    {
        try {
            return $this->s3Client->doesBucketExist($bucketName);
        } catch (S3Exception $e) {
            Log::warning('Error checking bucket existence', [
                'bucket_name' => $bucketName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create S3 bucket.
     *
     * @param string $bucketName
     * @param string $region
     * @return void
     * @throws S3Exception
     */
    protected function createBucket(string $bucketName, string $region): void
    {
        $params = [
            'Bucket' => $bucketName,
        ];

        // us-east-1 is the default region and doesn't need LocationConstraint
        if ($region !== 'us-east-1') {
            $params['CreateBucketConfiguration'] = [
                'LocationConstraint' => $region,
            ];
        }

        $this->s3Client->createBucket($params);
    }

    /**
     * Enable versioning on bucket (idempotent).
     *
     * @param string $bucketName
     * @return void
     * @throws S3Exception
     */
    protected function enableVersioning(string $bucketName): void
    {
        if (!config('storage.bucket_config.versioning', true)) {
            return;
        }

        // Get current versioning status
        $result = $this->s3Client->getBucketVersioning(['Bucket' => $bucketName]);
        $status = $result->get('Status');

        // Only enable if not already enabled (idempotent)
        if ($status !== 'Enabled') {
            $this->s3Client->putBucketVersioning([
                'Bucket' => $bucketName,
                'VersioningConfiguration' => [
                    'Status' => 'Enabled',
                ],
            ]);
        }
    }

    /**
     * Enable encryption on bucket (idempotent).
     *
     * @param string $bucketName
     * @return void
     * @throws S3Exception
     */
    protected function enableEncryption(string $bucketName): void
    {
        $encryption = config('storage.bucket_config.encryption', 'AES256');

        if (!$encryption) {
            return;
        }

        $encryptionConfig = [
            'Bucket' => $bucketName,
            'ServerSideEncryptionConfiguration' => [
                'Rules' => [
                    [
                        'ApplyServerSideEncryptionByDefault' => [
                            'SSEAlgorithm' => $encryption,
                        ],
                    ],
                ],
            ],
        ];

        // Add KMS key if specified
        if ($encryption === 'aws:kms') {
            $kmsKeyId = config('storage.bucket_config.kms_key_id');
            if ($kmsKeyId) {
                $encryptionConfig['ServerSideEncryptionConfiguration']['Rules'][0]['ApplyServerSideEncryptionByDefault']['KMSMasterKeyID'] = $kmsKeyId;
            }
        }

        // Put encryption configuration (idempotent - can be called multiple times)
        $this->s3Client->putBucketEncryption($encryptionConfig);
    }

    /**
     * Apply lifecycle rules to bucket (idempotent).
     *
     * @param string $bucketName
     * @return void
     * @throws S3Exception
     */
    protected function applyLifecycleRules(string $bucketName): void
    {
        $lifecycleRules = config('storage.bucket_config.lifecycle_rules');

        if (!$lifecycleRules || empty($lifecycleRules)) {
            return;
        }

        // Put lifecycle configuration (idempotent - replaces existing rules)
        $this->s3Client->putBucketLifecycleConfiguration([
            'Bucket' => $bucketName,
            'LifecycleConfiguration' => [
                'Rules' => $lifecycleRules,
            ],
        ]);
    }

    /**
     * Apply CORS to bucket for browser presigned uploads (idempotent).
     * Uses config storage.cors_allowed_origins (defaults to APP_URL origin).
     * IAM: s3:PutBucketCORS required.
     *
     * @param string $bucketName
     * @return void
     * @throws S3Exception
     */
    protected function applyCors(string $bucketName): void
    {
        $origins = config('storage.cors_allowed_origins', []);

        if (empty($origins)) {
            return;
        }

        $this->s3Client->putBucketCors([
            'Bucket' => $bucketName,
            'CORSConfiguration' => [
                'CORSRules' => [
                    [
                        'AllowedHeaders' => ['*'],
                        'AllowedMethods' => ['GET', 'PUT', 'POST', 'HEAD'],
                        'AllowedOrigins' => $origins,
                        'ExposeHeaders' => ['ETag'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Verify bucket configuration matches expected settings (idempotent).
     * Ensures CORS is applied so existing buckets get correct origins (e.g. after APP_URL change).
     *
     * @param string $bucketName
     * @return void
     */
    protected function verifyBucketConfiguration(string $bucketName): void
    {
        $this->applyCors($bucketName);
    }

    /**
     * Create S3 client instance.
     *
     * @return S3Client
     * @throws \RuntimeException If AWS SDK is not installed
     */
    protected function createS3Client(): S3Client
    {
        // Check if AWS SDK is available
        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException(
                'AWS SDK for PHP is required for bucket provisioning. ' .
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
