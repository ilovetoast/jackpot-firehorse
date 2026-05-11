<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AiMetadataGenerationService;
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
                    'fields' => [
                        'photo_type' => [
                            'value' => 'landscape',
                            'confidence' => 0.95,
                        ],
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
        $this->assertEquals(0, $results['tags_created']);
        $this->assertFalse($results['tag_inference_attempted']);
        $this->assertSame('vision_skipped_no_inputs', $results['ai_tag_inference_status'] ?? null);
        $this->assertEquals(0.0, $results['cost']);
        $this->assertEmpty($results['fields_processed']);
    }

    /**
     * When only the Tags field is AI-eligible (no options), still run vision and create tag candidates.
     */
    public function test_runs_tag_inference_when_no_structured_fields(): void
    {
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'tags' => [
                        ['value' => 'vibrant', 'confidence' => 0.95],
                    ],
                ]),
                'tokens_in' => 100,
                'tokens_out' => 50,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->with(100, 50, 'gpt-4o-mini')
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $results = $this->service->generateMetadata($asset);

        $this->assertEquals(0, $results['candidates_created']);
        $this->assertGreaterThanOrEqual(1, $results['tags_created']);
        $this->assertTrue($results['tag_inference_attempted']);
        $this->assertSame('attempted_ok', $results['ai_tag_inference_status'] ?? null);
        $this->assertEmpty($results['fields_processed']);

        $row = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('vibrant', $row->tag);
    }

    /**
     * Vision often emits vague tags like "model" / "fashion" on packaging and sell sheets — strip at ingest.
     */
    public function test_blocklisted_vision_tags_model_fashion_are_not_persisted(): void
    {
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'tags' => [
                        ['value' => 'model', 'confidence' => 0.95],
                        ['value' => 'fashion', 'confidence' => 0.95],
                        ['value' => 'bourbon bottle', 'confidence' => 0.95],
                    ],
                ]),
                'tokens_in' => 100,
                'tokens_out' => 50,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $results = $this->service->generateMetadata($asset);

        $this->assertSame(1, $results['tags_created']);
        $tags = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->pluck('tag')
            ->all();
        $this->assertSame(['bourbon bottle'], $tags);
        $this->assertSame(2, $results['tag_parse_stats']['rejected_blocklist_count'] ?? null);
    }

    /**
     * Upload-time _skip_ai_tagging must not persist tag candidates; structured field candidates still run.
     */
    public function test_skip_ai_tagging_upload_suppresses_tag_candidates_when_structured_fields_run(): void
    {
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'fields' => [
                        'photo_type' => [
                            'value' => 'landscape',
                            'confidence' => 0.95,
                        ],
                    ],
                    'tags' => [
                        ['value' => 'should-not-persist', 'confidence' => 0.99],
                    ],
                ]),
                'tokens_in' => 100,
                'tokens_out' => 50,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $meta = $asset->metadata ?? [];
        $meta['_skip_ai_tagging'] = true;
        $asset->update(['metadata' => $meta]);

        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'landscape');
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $results = $this->service->generateMetadata($asset->fresh());

        $this->assertEquals(1, $results['candidates_created']);
        $this->assertEquals(0, $results['tags_created']);
        $this->assertFalse($results['tag_inference_attempted']);
        $this->assertSame('skipped_upload_opt_out', $results['ai_tag_inference_status'] ?? null);

        $this->assertEquals(0, DB::table('asset_tag_candidates')->where('asset_id', $asset->id)->count());
    }

    /**
     * Upload-time _skip_ai_metadata must still create tag candidates when tagging is on (vision runs tags-only).
     * Regression: ProcessAssetJob used to skip AiMetadataGenerationJob entirely when metadata was off — no tags at all.
     */
    public function test_skip_ai_metadata_upload_still_creates_tag_candidates(): void
    {
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'fields' => [
                        'photo_type' => [
                            'value' => 'studio',
                            'confidence' => 0.95,
                        ],
                    ],
                    'tags' => [
                        ['value' => 'from-vision', 'confidence' => 0.96],
                    ],
                ]),
                'tokens_in' => 100,
                'tokens_out' => 50,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $meta = $asset->metadata ?? [];
        $meta['_skip_ai_metadata'] = true;
        $meta['_skip_ai_tagging'] = false;
        $asset->update(['metadata' => $meta]);

        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'studio');
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $results = $this->service->generateMetadata($asset->fresh());

        $this->assertEquals(0, $results['candidates_created']);
        $this->assertGreaterThanOrEqual(1, $results['tags_created']);
        $this->assertTrue($results['tag_inference_attempted']);
        $this->assertSame('attempted_ok', $results['ai_tag_inference_status'] ?? null);
        $this->assertEmpty($results['fields_processed']);

        $this->assertEquals(0, DB::table('asset_metadata_candidates')->where('asset_id', $asset->id)->count());
        $row = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('from-vision', $row->tag);
    }

    /**
     * Test: Skips when category missing
     */
    public function test_skips_when_category_missing(): void
    {
        $asset = $this->createAsset(); // No category

        $results = $this->service->generateMetadata($asset);

        $this->assertEquals(0, $results['candidates_created']);
        $this->assertEquals(0, $results['tags_created']);
        $this->assertFalse($results['tag_inference_attempted']);
        $this->assertSame('vision_skipped_no_inputs', $results['ai_tag_inference_status'] ?? null);
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
                    'fields' => [
                        'photo_type' => [
                            'value' => 'landscape',
                            'confidence' => 0.95,
                        ],
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
                    'fields' => [
                        'photo_type' => [
                            'value' => 'landscape',
                            'confidence' => 0.80, // Below default min (0.90)
                        ],
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
                    'fields' => [
                        'photo_type' => [
                            'value' => 'invalid_value', // Not in options
                            'confidence' => 0.95,
                        ],
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
                    'fields' => [
                        'photo_type' => [
                            'value' => 'landscape',
                            'confidence' => 0.95,
                        ],
                        'usage_rights' => [
                            'value' => 'editorial',
                            'confidence' => 0.92,
                        ],
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
     * UI screenshot classification: photo-world tags from the model are dropped; interface tags persist.
     */
    public function test_ui_screenshot_sanitizer_rejects_studio_lighting_tags(): void
    {
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'detected_asset_type' => 'ui_screenshot',
                    'tags' => [
                        ['value' => 'lighting setup', 'confidence' => 0.95],
                        ['value' => 'studio background', 'confidence' => 0.95],
                        ['value' => 'screenshot', 'confidence' => 0.96],
                        ['value' => 'user interface', 'confidence' => 0.96],
                        ['value' => 'form', 'confidence' => 0.96],
                        ['value' => 'input field', 'confidence' => 0.96],
                    ],
                ]),
                'tokens_in' => 100,
                'tokens_out' => 50,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $results = $this->service->generateMetadata($asset);

        $this->assertGreaterThanOrEqual(2, (int) ($results['tag_parse_stats']['rejected_sanitizer_count'] ?? 0));
        $tags = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
        $this->assertContains('screenshot', $tags);
        $this->assertContains('user interface', $tags);
        $this->assertContains('form', $tags);
        $this->assertContains('input field', $tags);
        $this->assertNotContains('lighting setup', $tags);
        $this->assertNotContains('studio background', $tags);
    }

    /**
     * Product-style classification must not drop legitimate studio-related tags.
     */
    public function test_product_photo_does_not_apply_ui_screenshot_photo_tag_rejections(): void
    {
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'detected_asset_type' => 'product_photo',
                    'tags' => [
                        ['value' => 'studio lighting', 'confidence' => 0.95],
                        ['value' => 'indoor', 'confidence' => 0.95],
                        ['value' => 'bottle', 'confidence' => 0.95],
                    ],
                ]),
                'tokens_in' => 100,
                'tokens_out' => 50,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $results = $this->service->generateMetadata($asset);

        $tags = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
        $this->assertContains('studio lighting', $tags);
        $this->assertContains('indoor', $tags);
        $this->assertContains('bottle', $tags);
        $this->assertSame(0, (int) ($results['tag_parse_stats']['rejected_sanitizer_count'] ?? -1));
    }

    /**
     * Tags with more than three words after canonicalization are rejected.
     */
    public function test_sanitizer_rejects_tags_over_three_words(): void
    {
        $this->mockProvider->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'detected_asset_type' => 'document',
                    'tags' => [
                        ['value' => 'one two three four', 'confidence' => 0.99],
                        ['value' => 'valid short phrase', 'confidence' => 0.99],
                    ],
                ]),
                'tokens_in' => 100,
                'tokens_out' => 50,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);

        $this->mockProvider->shouldReceive('calculateCost')
            ->once()
            ->andReturn(0.001);

        $asset = $this->createAssetWithCategory();
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $this->service->generateMetadata($asset);

        $tags = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->pluck('tag')
            ->all();
        $this->assertContains('valid short phrase', $tags);
        $this->assertNotContains('one two three four', $tags);
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
