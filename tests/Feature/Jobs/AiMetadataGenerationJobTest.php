<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\PlanLimitExceededException;
use App\Jobs\AiMetadataGenerationJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AiUsageService;
use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * AI Metadata Generation Job Test
 *
 * Tests the job that orchestrates AI metadata generation.
 */
class AiMetadataGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock AI provider
        $mockProvider = Mockery::mock(AIProviderInterface::class);
        $this->app->instance(AIProviderInterface::class, $mockProvider);

        // Mock TenantBucketService - service fetches image internally via S3/IAM
        $mockBucket = Mockery::mock(\App\Services\TenantBucketService::class);
        $mockBucket->shouldReceive('getObjectContents')->andReturn('fake-image-bytes');
        $this->app->instance(\App\Services\TenantBucketService::class, $mockBucket);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Get the mocked provider instance.
     */
    protected function getMockProvider(): \Mockery\MockInterface
    {
        return app(AIProviderInterface::class);
    }

    /**
     * Test: Job skips when plan limit exceeded
     */
    public function test_skips_when_plan_limit_exceeded(): void
    {
        Queue::fake();

        $tenant = $this->createTenantWithPlanLimit(0); // At limit
        $asset = $this->createAssetWithCategory($tenant);

        $job = new AiMetadataGenerationJob($asset->id);
        
        try {
            $job->handle(
                app(\App\Services\AiMetadataGenerationService::class),
                app(AiUsageService::class)
            );
        } catch (PlanLimitExceededException $e) {
            // Expected
        }

        // Verify asset marked as skipped
        $asset->refresh();
        $this->assertTrue($asset->metadata['_ai_metadata_skipped'] ?? false);
        $this->assertEquals('plan_limit_exceeded', $asset->metadata['_ai_metadata_skip_reason'] ?? null);
    }

    /**
     * Test: Job skips when no thumbnail
     */
    public function test_skips_when_no_thumbnail(): void
    {
        $asset = $this->createAssetWithCategory();
        // Remove thumbnail path so waitForThumbnail returns false
        $metadata = $asset->metadata ?? [];
        unset($metadata['thumbnails'], $metadata['preview_thumbnails']);
        $asset->metadata = $metadata;
        $asset->save();

        $job = new AiMetadataGenerationJob($asset->id);
        $job->handle(
            app(\App\Services\AiMetadataGenerationService::class),
            app(AiUsageService::class)
        );

        // Should complete without error (graceful skip)
        $this->assertTrue(true);
    }

    /**
     * Test: Job skips when no category
     */
    public function test_skips_when_no_category(): void
    {
        $asset = $this->createAsset();
        // Add thumbnail path so waitForThumbnail passes, but no category
        $asset->metadata = array_merge($asset->metadata ?? [], [
            'thumbnails' => ['medium' => ['path' => 'assets/test/medium.webp']],
        ]);
        $asset->thumbnail_status = \App\Enums\ThumbnailStatus::COMPLETED;
        $asset->save();

        $job = new AiMetadataGenerationJob($asset->id);
        $job->handle(
            app(\App\Services\AiMetadataGenerationService::class),
            app(AiUsageService::class)
        );

        // Should complete without error (graceful skip)
        $this->assertTrue(true);
    }

    /**
     * Test: Auto-generation runs once
     */
    public function test_auto_generation_runs_once(): void
    {
        $mockProvider = $this->getMockProvider();
        
        // Mock provider for first run
        $mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'photo_type' => [
                        'value' => 'landscape',
                        'confidence' => 0.95,
                    ],
                ]),
                'tokens_in' => 1000,
                'tokens_out' => 100,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');

        // First run
        $job1 = new AiMetadataGenerationJob($asset->id, isManualRerun: false);
        $job1->handle(
            app(\App\Services\AiMetadataGenerationService::class),
            app(AiUsageService::class)
        );

        $asset->refresh();
        $this->assertNotNull($asset->metadata['_ai_metadata_generated_at'] ?? null);

        // Second run (should skip - provider should not be called)
        $job2 = new AiMetadataGenerationJob($asset->id, isManualRerun: false);
        $job2->handle(
            app(\App\Services\AiMetadataGenerationService::class),
            app(AiUsageService::class)
        );

        // Should have skipped (no exception thrown)
        $this->assertTrue(true);
    }

    /**
     * Test: Manual rerun overrides timestamp check
     */
    public function test_manual_rerun_overrides_timestamp_check(): void
    {
        $mockProvider = $this->getMockProvider();
        
        // Mock provider for manual rerun
        $mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'photo_type' => [
                        'value' => 'landscape',
                        'confidence' => 0.95,
                    ],
                ]),
                'tokens_in' => 1000,
                'tokens_out' => 100,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $asset->metadata = array_merge($asset->metadata ?? [], [
            '_ai_metadata_generated_at' => now()->subDay()->toIso8601String(),
        ]);
        $asset->save();

        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');

        // Manual rerun should proceed
        $job = new AiMetadataGenerationJob($asset->id, isManualRerun: true);
        $job->handle(
            app(\App\Services\AiMetadataGenerationService::class),
            app(AiUsageService::class)
        );

        $asset->refresh();
        // Timestamp should be updated
        $this->assertNotNull($asset->metadata['_ai_metadata_generated_at'] ?? null);
    }

    /**
     * Test: Handles API failure gracefully
     */
    public function test_handles_api_failure_gracefully(): void
    {
        $mockProvider = $this->getMockProvider();
        
        // Mock provider to throw exception
        $mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andThrow(new \Exception('API error'));

        $asset = $this->createAssetWithCategory();
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');

        $job = new AiMetadataGenerationJob($asset->id);
        
        // Should not throw - graceful failure
        $job->handle(
            app(\App\Services\AiMetadataGenerationService::class),
            app(AiUsageService::class)
        );

        $asset->refresh();
        // Should be marked as failed
        $this->assertTrue($asset->metadata['_ai_metadata_failed'] ?? false);
    }

    /**
     * Helper: Create tenant with plan limit
     */
    protected function createTenantWithPlanLimit(int $limit): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Set usage to limit
        if ($limit > 0) {
            DB::table('ai_usage')->insert([
                'tenant_id' => $tenant->id,
                'feature' => 'tagging',
                'usage_date' => now()->toDateString(),
                'call_count' => $limit,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $tenant;
    }

    /**
     * Helper: Create asset with category
     */
    protected function createAssetWithCategory(?Tenant $tenant = null): Asset
    {
        if (!$tenant) {
            $tenant = Tenant::firstOrCreate(['id' => 1], [
                'name' => 'Test Tenant',
                'slug' => 'test-tenant',
            ]);
        }

        $brand = Brand::firstOrCreate(['id' => 1, 'tenant_id' => $tenant->id], [
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $storageBucket = StorageBucket::firstOrCreate(['id' => 1, 'tenant_id' => $tenant->id], [
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $category = Category::firstOrCreate([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'slug' => 'test-category',
        ], [
            'asset_type' => \App\Enums\AssetType::ASSET,
            'name' => 'Test Category',
            'is_system' => false,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path.jpg',
            'metadata' => [
                'category_id' => $category->id,
                'thumbnails' => ['medium' => ['path' => 'assets/test/medium.webp']],
            ],
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
        ]);

        return $asset;
    }

    /**
     * Helper: Create asset
     */
    protected function createAsset(): Asset
    {
        $tenant = Tenant::firstOrCreate(['id' => 1], [
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $brand = Brand::firstOrCreate(['id' => 1, 'tenant_id' => $tenant->id], [
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $storageBucket = StorageBucket::firstOrCreate(['id' => 1, 'tenant_id' => $tenant->id], [
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path.jpg',
            'metadata' => [],
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
        ]);
    }

    /**
     * Helper: Create ai_eligible field
     */
    protected function createAiEligibleField(string $key, int $tenantId, array $overrides = []): \stdClass
    {
        $fieldData = array_merge([
            'key' => $key,
            'system_label' => ucfirst($key),
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'tenant',
            'tenant_id' => $tenantId,
            'is_user_editable' => true,
            'population_mode' => 'manual',
            'is_filterable' => true,
            'ai_eligible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        $fieldId = DB::table('metadata_fields')->insertGetId($fieldData);

        return (object) array_merge($fieldData, ['id' => $fieldId]);
    }

    /**
     * Helper: Create field option
     */
    protected function createFieldOption(int $fieldId, string $value): \stdClass
    {
        $optionId = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => $value,
            'system_label' => ucfirst($value),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) [
            'id' => $optionId,
            'metadata_field_id' => $fieldId,
            'value' => $value,
            'system_label' => ucfirst($value),
        ];
    }
}
