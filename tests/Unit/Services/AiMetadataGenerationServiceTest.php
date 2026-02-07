<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AiMetadataGenerationService;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\TenantBucketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * AI Metadata Generation Service Test
 *
 * Tests AI metadata generation service that creates candidates from OpenAI Vision API.
 */
class AiMetadataGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AiMetadataGenerationService $service;
    protected $mockProvider;

    protected $mockBucketService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockProvider = Mockery::mock(AIProviderInterface::class);
        $this->mockBucketService = Mockery::mock(TenantBucketService::class);
        $this->mockBucketService->shouldReceive('getObjectContents')
            ->andReturn('fake-image-bytes-for-ai-test');

        $this->service = new AiMetadataGenerationService(
            $this->mockProvider,
            null,
            $this->mockBucketService
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test: Generates metadata successfully with valid response
     */
    public function test_generates_metadata_successfully(): void
    {
        // Mock provider response
        $this->mockProvider->shouldReceive('analyzeImage')
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

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->with(1000, 100, 'gpt-4o-mini')
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');
        $this->createFieldOption($field->id, 'portrait');

        $results = $this->service->generateMetadata($asset);

        $this->assertEquals(1, $results['candidates_created']);
        $this->assertGreaterThan(0, $results['cost']);
        $this->assertContains('photo_type', $results['fields_processed']);

        // Verify candidate created
        $candidate = DB::table('asset_metadata_candidates')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $field->id)
            ->where('producer', 'ai')
            ->first();

        $this->assertNotNull($candidate);
        $this->assertEquals(0.95, $candidate->confidence);
        $this->assertEquals('landscape', json_decode($candidate->value_json, true));
    }

    /**
     * Test: Skips when no eligible fields
     */
    public function test_skips_when_no_eligible_fields(): void
    {
        $asset = $this->createAssetWithCategory();

        $results = $this->service->generateMetadata($asset);

        $this->assertEquals(0, $results['candidates_created']);
        $this->assertEquals(0.0, $results['cost']);
        $this->assertEmpty($results['fields_processed']);
    }

    /**
     * Test: Skips when category missing
     */
    public function test_skips_when_category_missing(): void
    {
        $asset = $this->createAsset(); // No category

        $results = $this->service->generateMetadata($asset);

        $this->assertEquals(0, $results['candidates_created']);
    }

    /**
     * Test: Only processes ai_eligible fields
     */
    public function test_only_processes_ai_eligible_fields(): void
    {
        // Mock provider response
        $this->mockProvider->shouldReceive('analyzeImage')
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

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        
        // Create ai_eligible field
        $eligibleField = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($eligibleField->id, 'landscape');

        // Create non-ai_eligible field
        $nonEligibleField = $this->createSelectField('other_field', $asset->tenant_id, [
            'ai_eligible' => false,
        ]);
        $this->createFieldOption($nonEligibleField->id, 'value1');

        $results = $this->service->generateMetadata($asset);

        // Should only process ai_eligible field
        $this->assertContains('photo_type', $results['fields_processed']);
        $this->assertNotContains('other_field', $results['fields_processed']);
    }

    /**
     * Test: Respects category enablement
     */
    public function test_respects_category_enablement(): void
    {
        $asset = $this->createAssetWithCategory();
        $categoryId = $asset->metadata['category_id'];
        
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');

        // Suppress field for category
        DB::table('metadata_field_category_visibility')->insert([
            'metadata_field_id' => $field->id,
            'category_id' => $categoryId,
            'tenant_id' => $asset->tenant_id,
            'is_suppressed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $results = $this->service->generateMetadata($asset);

        // Should skip suppressed field
        $this->assertEquals(0, $results['candidates_created']);
    }

    /**
     * Test: Filters low confidence values
     */
    public function test_filters_low_confidence_values(): void
    {
        // Mock provider response with low confidence
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'photo_type' => [
                        'value' => 'landscape',
                        'confidence' => 0.85, // Below 0.90 threshold
                    ],
                ]),
                'tokens_in' => 1000,
                'tokens_out' => 100,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');

        $results = $this->service->generateMetadata($asset);

        // Low confidence should be filtered out
        $this->assertEquals(0, $results['candidates_created']);
    }

    /**
     * Test: Validates field values against options
     */
    public function test_validates_field_values_against_options(): void
    {
        // Mock provider response with invalid value
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'photo_type' => [
                        'value' => 'invalid_value', // Not in options
                        'confidence' => 0.95,
                    ],
                ]),
                'tokens_in' => 1000,
                'tokens_out' => 100,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape'); // Only 'landscape' is allowed

        $results = $this->service->generateMetadata($asset);

        // Invalid value should be filtered out
        $this->assertEquals(0, $results['candidates_created']);
    }

    /**
     * Test: Throws when AI image fetch fails before provider call
     */
    public function test_throws_when_image_fetch_fails(): void
    {
        $mockBucket = Mockery::mock(TenantBucketService::class);
        $mockBucket->shouldReceive('getObjectContents')
            ->once()
            ->andThrow(new \RuntimeException('S3 fetch failed'));

        $service = new AiMetadataGenerationService($this->mockProvider, null, $mockBucket);
        $asset = $this->createAssetWithCategory();
        $this->createAiEligibleField('photo_type', $asset->tenant_id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('AI image fetch failed before provider call');
        $service->generateMetadata($asset);
    }

    /**
     * Test: Handles API failure gracefully
     */
    public function test_handles_api_failure_gracefully(): void
    {
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andThrow(new \Exception('API error'));

        $asset = $this->createAssetWithCategory();
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');

        $this->expectException(\Exception::class);
        $this->service->generateMetadata($asset);
    }

    /**
     * Test: Handles invalid JSON response
     */
    public function test_handles_invalid_json_response(): void
    {
        // Mock provider response with invalid JSON
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => 'invalid json',
                'tokens_in' => 1000,
                'tokens_out' => 100,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');

        $results = $this->service->generateMetadata($asset);

        // Invalid JSON should result in no candidates
        $this->assertEquals(0, $results['candidates_created']);
    }

    /**
     * Test: Processes multiple fields in single call
     */
    public function test_processes_multiple_fields_in_single_call(): void
    {
        // Mock provider response with multiple fields
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'photo_type' => [
                        'value' => 'landscape',
                        'confidence' => 0.95,
                    ],
                    'usage_rights' => [
                        'value' => 'editorial',
                        'confidence' => 0.92,
                    ],
                ]),
                'tokens_in' => 1000,
                'tokens_out' => 100,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        
        $field1 = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field1->id, 'landscape');
        
        $field2 = $this->createAiEligibleField('usage_rights', $asset->tenant_id);
        $this->createFieldOption($field2->id, 'editorial');

        $results = $this->service->generateMetadata($asset);

        $this->assertEquals(2, $results['candidates_created']);
        $this->assertContains('photo_type', $results['fields_processed']);
        $this->assertContains('usage_rights', $results['fields_processed']);
    }

    /**
     * Helper: Create asset with category
     */
    protected function createAssetWithCategory(): Asset
    {
        $asset = $this->createAsset();
        
        $category = \App\Models\Category::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_type' => \App\Enums\AssetType::ASSET,
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_system' => false,
        ]);

        $asset->metadata = array_merge($asset->metadata ?? [], [
            'category_id' => $category->id,
            'thumbnails' => [
                'medium' => ['path' => 'assets/test/medium.webp'],
            ],
        ]);
        $asset->thumbnail_status = \App\Enums\ThumbnailStatus::COMPLETED;
        $asset->save();

        return $asset;
    }

    /**
     * Helper: Create asset for testing
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
        return $this->createSelectField($key, $tenantId, array_merge([
            'ai_eligible' => true,
        ], $overrides));
    }

    /**
     * Helper: Create select field
     */
    protected function createSelectField(string $key, int $tenantId, array $overrides = []): \stdClass
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
            'ai_eligible' => false,
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
