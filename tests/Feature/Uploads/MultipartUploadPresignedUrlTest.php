<?php

namespace Tests\Feature\Uploads;

use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Feature test for multipart presigned URL support.
 *
 * This test validates the multipart upload presigned URL generation endpoint
 * using REAL AWS S3 (no mocks). It ensures:
 * - Presigned URLs are generated correctly
 * - UploadPart operations succeed
 * - IAM permissions are correctly configured
 * - The endpoint integrates properly with S3
 *
 * REQUIREMENTS:
 * - Real AWS credentials must be configured in .env
 * - AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be set
 * - AWS_BUCKET must exist and be accessible
 * - STORAGE_PROVISION_STRATEGY=shared
 *
 * This test is tagged as @group s3 and @group integration to allow
 * filtering tests that require external services.
 *
 * TODO — S3 Integration Tests (DB-backed):
 * 
 * Multipart upload presigned URL flow is validated manually and via AWS IAM Policy Simulator.
 * Automated Laravel integration tests are deferred until a dedicated test database is configured.
 * This avoids running destructive migrations against the development database.
 * 
 * Revisit once .env.testing and isolated test DB are in place.
 */
#[Group('aws')]
#[Group('s3')]
#[Group('integration')]
class MultipartUploadPresignedUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * S3 client instance for cleanup operations.
     */
    protected ?S3Client $s3Client = null;

    /**
     * Test entities created during test setup.
     */
    protected ?Tenant $tenant = null;
    protected ?User $user = null;
    protected ?Brand $brand = null;
    protected ?StorageBucket $bucket = null;
    protected ?UploadSession $uploadSession = null;
    protected ?string $multipartUploadId = null;

    /**
     * Create S3 client instance.
     */
    protected function createS3Client(): S3Client
    {
        if ($this->s3Client === null) {
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

            $this->s3Client = new S3Client($config);
        }

        return $this->s3Client;
    }

    /**
     * Test multipart presigned URL generation and upload.
     *
     * This test performs a complete flow:
     * 1. Creates test data (tenant, user, brand, storage bucket)
     * 2. Creates an UploadSession in multipart mode
     * 3. Initiates a multipart upload in S3 to get a valid multipart_upload_id
     * 4. Calls the presigned URL endpoint
     * 5. Uploads a small binary payload to the presigned URL
     * 6. Verifies the upload succeeded (HTTP 200 from S3)
     * 7. Cleans up by aborting the multipart upload
     */
    public function test_multipart_presigned_url_generation_and_upload(): void
    {
        try {
            // Step 1: Create tenant, user, and brand
            $this->setUpTestData();

            // Step 2: Create storage bucket for tenant
            $this->createStorageBucket();

            // Step 3: Create UploadSession and initiate multipart upload in S3
            $this->createUploadSessionWithMultipartUpload();

            // Step 4: Authenticate as user and set tenant context
            $this->authenticateAndSetTenantContext();

            // Step 5: Call the multipart part URL endpoint
            $response = $this->postJson(
                route('uploads.multipart-part-url', ['uploadSession' => $this->uploadSession->id]),
                [
                    'part_number' => 1,
                ]
            );

            // Step 6: Assert response structure
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'upload_session_id',
                    'multipart_upload_id',
                    'part_number',
                    'upload_url',
                    'expires_in',
                ]);

            $responseData = $response->json();

            // Assert specific values
            $this->assertEquals($this->uploadSession->id, $responseData['upload_session_id']);
            $this->assertEquals($this->multipartUploadId, $responseData['multipart_upload_id']);
            $this->assertEquals(1, $responseData['part_number']);
            $this->assertEquals(900, $responseData['expires_in']); // 15 minutes
            $this->assertNotEmpty($responseData['upload_url']);
            $this->assertStringStartsWith('http', $responseData['upload_url']);

            // Step 7: Upload a small binary payload (1KB random bytes) to the presigned URL
            $payload = random_bytes(1024); // 1KB of random binary data

            $uploadResponse = Http::put($responseData['upload_url'], $payload);

            // Step 8: Assert HTTP 200 from S3
            $this->assertEquals(200, $uploadResponse->status(), 'S3 upload should return HTTP 200');

            // Verify ETag is present in response (indicates successful upload)
            $this->assertNotEmpty($uploadResponse->header('ETag'), 'S3 should return ETag header');

            // Step 9: Verify no database state was modified by the test
            // (UploadSession should still be in INITIATING state)
            $this->uploadSession->refresh();
            $this->assertEquals(UploadStatus::INITIATING, $this->uploadSession->status);
        } finally {
            // Step 10: Cleanup - abort multipart upload using AWS SDK
            // This must run even if test fails to prevent orphaned multipart uploads
            $this->cleanupMultipartUpload();
        }
    }

    /**
     * Set up test data: tenant, user, and brand.
     */
    protected function setUpTestData(): void
    {
        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'timezone' => 'UTC',
        ]);

        // Create user
        $this->user = User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        // Attach user to tenant
        $this->tenant->users()->attach($this->user->id, ['role' => 'owner']);

        // Get default brand (created automatically when tenant is created)
        $this->brand = $this->tenant->defaultBrand;
        $this->assertNotNull($this->brand, 'Tenant should have a default brand');
    }

    /**
     * Create storage bucket for the tenant.
     */
    protected function createStorageBucket(): void
    {
        $bucketName = config('filesystems.disks.s3.bucket');
        $this->assertNotEmpty($bucketName, 'AWS_BUCKET must be configured in .env');

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => $bucketName,
            'status' => StorageBucketStatus::ACTIVE,
            'region' => config('filesystems.disks.s3.region', 'us-east-1'),
        ]);
    }

    /**
     * Create UploadSession and initiate multipart upload in S3.
     *
     * This method:
     * 1. Creates an UploadSession record in CHUNKED (multipart) mode
     * 2. Initiates a multipart upload in S3 to get a valid multipart_upload_id
     * 3. Stores the multipart_upload_id in the UploadSession
     */
    protected function createUploadSessionWithMultipartUpload(): void
    {
        $s3Client = $this->createS3Client();

        // Create UploadSession in INITIATING status with CHUNKED type
        $this->uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::INITIATING,
            'type' => UploadType::CHUNKED,
            'expected_size' => 1024, // 1KB test file
            'uploaded_size' => null,
            'expires_at' => now()->addHours(1),
            'failure_reason' => null,
            'last_activity_at' => now(),
        ]);

        // Generate temp upload path using immutable contract
        $path = "temp/uploads/{$this->uploadSession->id}/original";

        // Initiate multipart upload in S3 to get a valid multipart_upload_id
        $multipartUploadResult = $s3Client->createMultipartUpload([
            'Bucket' => $this->bucket->name,
            'Key' => $path,
            'ContentType' => 'application/octet-stream',
        ]);

        $this->multipartUploadId = $multipartUploadResult['UploadId'];
        $this->assertNotEmpty($this->multipartUploadId, 'Multipart upload ID should be generated');

        // Store multipart_upload_id in UploadSession
        $this->uploadSession->update([
            'multipart_upload_id' => $this->multipartUploadId,
        ]);
    }

    /**
     * Authenticate as user and set tenant context.
     *
     * This simulates the authentication and tenant resolution
     * that happens in production via middleware.
     */
    protected function authenticateAndSetTenantContext(): void
    {
        // Authenticate as the user
        $this->actingAs($this->user);

        // Set tenant context in session (mimics ResolveTenant middleware)
        session(['tenant_id' => $this->tenant->id]);

        // Bind tenant to container (mimics middleware behavior)
        app()->instance('tenant', $this->tenant);
    }

    /**
     * Cleanup: Abort multipart upload using AWS SDK.
     *
     * This method runs in the finally block to ensure cleanup
     * even if the test fails. This prevents orphaned multipart
     * uploads from accumulating in S3.
     */
    protected function cleanupMultipartUpload(): void
    {
        if ($this->multipartUploadId && $this->bucket && $this->uploadSession) {
            try {
                $s3Client = $this->createS3Client();
                $path = "temp/uploads/{$this->uploadSession->id}/original";

                $s3Client->abortMultipartUpload([
                    'Bucket' => $this->bucket->name,
                    'Key' => $path,
                    'UploadId' => $this->multipartUploadId,
                ]);
            } catch (\Exception $e) {
                // Log but don't fail test on cleanup errors
                // This is best-effort cleanup
                \Log::warning('Failed to abort multipart upload during test cleanup', [
                    'error' => $e->getMessage(),
                    'upload_session_id' => $this->uploadSession->id,
                    'multipart_upload_id' => $this->multipartUploadId,
                ]);
            }
        }
    }
}
