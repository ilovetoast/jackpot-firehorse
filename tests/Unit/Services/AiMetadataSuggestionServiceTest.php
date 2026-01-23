<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AiMetadataSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AI Metadata Suggestion Service Test
 *
 * Tests suggestion generation without auto-applying values.
 * Suggestions are ephemeral and stored in asset.metadata['_ai_suggestions'].
 */
class AiMetadataSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AiMetadataSuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $confidenceService = new \App\Services\AiMetadataConfidenceService();
        $usageService = new \App\Services\AiUsageService();
        $this->service = new AiMetadataSuggestionService($confidenceService, $usageService);
    }

    /**
     * Test: High-confidence AI values generate suggestions
     */
    public function test_high_confidence_generates_suggestions(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.95, // Above 0.90 threshold
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        $this->assertArrayHasKey('test_field', $suggestions);
        $this->assertEquals('option1', $suggestions['test_field']['value']);
        $this->assertEquals(0.95, $suggestions['test_field']['confidence']);
    }

    /**
     * Test: Low-confidence values do not generate suggestions
     */
    public function test_low_confidence_does_not_generate_suggestions(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.85, // Below 0.90 threshold
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        $this->assertEmpty($suggestions);
    }

    /**
     * Test: Only empty fields get suggestions
     */
    public function test_only_empty_fields_get_suggestions(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');
        $this->createFieldOption($field->id, 'option2');

        // Set existing value for field
        \DB::table('asset_metadata')->insert([
            'asset_id' => $asset->id,
            'metadata_field_id' => $field->id,
            'value_json' => json_encode('option2'),
            'source' => 'user',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // Field is not empty - should not generate suggestion
        $this->assertEmpty($suggestions);
    }

    /**
     * Test: Only eligible fields (non-system, user-owned) get suggestions
     */
    public function test_only_eligible_fields_get_suggestions(): void
    {
        $asset = $this->createAsset();

        // Create automatic-only field (not eligible)
        $automaticField = $this->createSelectField('auto_field', $asset->tenant_id, [
            'population_mode' => 'automatic',
            'is_user_editable' => false,
        ]);
        $this->createFieldOption($automaticField->id, 'option1');

        // Create non-user-editable field (not eligible)
        $systemField = $this->createSelectField('system_field', $asset->tenant_id, [
            'is_user_editable' => false,
        ]);
        $this->createFieldOption($systemField->id, 'option1');

        $aiMetadataValues = [
            'auto_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
            'system_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // Neither field should generate suggestions (not eligible)
        $this->assertEmpty($suggestions);
    }

    /**
     * Test: Only allowed values (from options) generate suggestions
     */
    public function test_only_allowed_values_generate_suggestions(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');
        // Note: 'option2' is NOT in options

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option2', // Not in allowed options
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // Value not in options - should not generate suggestion
        $this->assertEmpty($suggestions);
    }

    /**
     * Test: Suggestions stored in asset.metadata['_ai_suggestions']
     */
    public function test_suggestions_stored_in_metadata(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateAndStoreSuggestions($asset, $aiMetadataValues);

        $asset->refresh();
        $storedSuggestions = $asset->metadata['_ai_suggestions'] ?? [];

        $this->assertArrayHasKey('test_field', $storedSuggestions);
        $this->assertEquals('option1', $storedSuggestions['test_field']['value']);
        $this->assertEquals(0.95, $storedSuggestions['test_field']['confidence']);
    }

    /**
     * Test: Deterministic behavior (same input = same output)
     */
    public function test_deterministic_behavior(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];

        $suggestions1 = $this->service->generateSuggestions($asset, $aiMetadataValues);
        $suggestions2 = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // Same input should produce same output
        $this->assertEquals($suggestions1, $suggestions2);
    }

    /**
     * Test: Multiselect fields validate all values
     */
    public function test_multiselect_fields_validate_all_values(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id, ['type' => 'multiselect']);
        $this->createFieldOption($field->id, 'option1');
        $this->createFieldOption($field->id, 'option2');
        // Note: 'option3' is NOT in options

        // All values allowed
        $aiMetadataValues1 = [
            'test_field' => [
                'value' => ['option1', 'option2'],
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];
        $suggestions1 = $this->service->generateSuggestions($asset, $aiMetadataValues1);
        $this->assertArrayHasKey('test_field', $suggestions1);

        // One value not allowed
        $aiMetadataValues2 = [
            'test_field' => [
                'value' => ['option1', 'option3'], // option3 not in options
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];
        $suggestions2 = $this->service->generateSuggestions($asset, $aiMetadataValues2);
        $this->assertEmpty($suggestions2);
    }

    /**
     * Test: Clear suggestions removes from metadata
     */
    public function test_clear_suggestions(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];

        $this->service->generateAndStoreSuggestions($asset, $aiMetadataValues);
        $asset->refresh();
        $this->assertArrayHasKey('_ai_suggestions', $asset->metadata);

        $this->service->clearSuggestions($asset);
        $asset->refresh();
        $this->assertArrayNotHasKey('_ai_suggestions', $asset->metadata ?? []);
    }

    /**
     * Test: Get suggestions returns stored suggestions
     */
    public function test_get_suggestions(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];

        $this->service->generateAndStoreSuggestions($asset, $aiMetadataValues);
        $asset->refresh();

        $suggestions = $this->service->getSuggestions($asset);
        $this->assertArrayHasKey('test_field', $suggestions);
        $this->assertEquals('option1', $suggestions['test_field']['value']);
    }

    /**
     * Test: Missing confidence is discarded silently
     */
    public function test_missing_confidence_is_discarded(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                // Missing confidence
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // Missing confidence should be discarded silently
        $this->assertEmpty($suggestions);
    }

    /**
     * Test: Null confidence is discarded silently
     */
    public function test_null_confidence_is_discarded(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => null, // Explicitly null
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // Null confidence should be discarded silently
        $this->assertEmpty($suggestions);
    }

    /**
     * Test: Non-numeric confidence is discarded silently
     */
    public function test_non_numeric_confidence_is_discarded(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 'invalid', // Non-numeric
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // Non-numeric confidence should be discarded silently
        $this->assertEmpty($suggestions);
    }

    /**
     * Test: Confidence exactly at threshold (0.90) is allowed
     */
    public function test_confidence_at_threshold_is_allowed(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.90, // Exactly at threshold
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // At threshold should be allowed
        $this->assertArrayHasKey('test_field', $suggestions);
        $this->assertEquals(0.90, $suggestions['test_field']['confidence']);
    }

    /**
     * Test: Confidence just below threshold (0.899) is discarded
     */
    public function test_confidence_just_below_threshold_is_discarded(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.899, // Just below 0.90
                'source' => 'ai',
            ],
        ];

        $suggestions = $this->service->generateSuggestions($asset, $aiMetadataValues);

        // Just below threshold should be discarded
        $this->assertEmpty($suggestions);
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
     * Helper: Create select field for testing
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
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        $fieldId = DB::table('metadata_fields')->insertGetId($fieldData);

        return (object) array_merge($fieldData, ['id' => $fieldId]);
    }

    /**
     * Helper: Create field option for testing
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

    /**
     * Test: Dismissed suggestions do not reappear
     */
    public function test_dismissed_suggestions_do_not_reappear(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');

        $aiMetadataValues = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];

        // Generate suggestions - should appear
        $suggestions1 = $this->service->generateSuggestions($asset, $aiMetadataValues);
        $this->assertArrayHasKey('test_field', $suggestions1);

        // Record dismissal
        $this->service->recordDismissal($asset, 'test_field', 'option1');
        $asset->refresh();

        // Verify it's marked as dismissed
        $this->assertTrue($this->service->isSuggestionDismissed($asset, 'test_field', 'option1'));

        // Generate suggestions again - should NOT appear (dismissed)
        $suggestions2 = $this->service->generateSuggestions($asset, $aiMetadataValues);
        $this->assertArrayNotHasKey('test_field', $suggestions2);
    }

    /**
     * Test: New suggestion with different value is allowed after dismissal
     */
    public function test_new_suggestion_with_different_value_allowed(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id);
        $this->createFieldOption($field->id, 'option1');
        $this->createFieldOption($field->id, 'option2');

        // Dismiss option1
        $this->service->recordDismissal($asset, 'test_field', 'option1');
        $asset->refresh();

        // Generate suggestion with option1 - should NOT appear (dismissed)
        $aiMetadataValues1 = [
            'test_field' => [
                'value' => 'option1',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];
        $suggestions1 = $this->service->generateSuggestions($asset, $aiMetadataValues1);
        $this->assertArrayNotHasKey('test_field', $suggestions1);

        // Generate suggestion with option2 - SHOULD appear (different value)
        $aiMetadataValues2 = [
            'test_field' => [
                'value' => 'option2',
                'confidence' => 0.95,
                'source' => 'ai',
            ],
        ];
        $suggestions2 = $this->service->generateSuggestions($asset, $aiMetadataValues2);
        $this->assertArrayHasKey('test_field', $suggestions2);
        $this->assertEquals('option2', $suggestions2['test_field']['value']);
    }

    /**
     * Test: Multiselect values are properly normalized for dismissal tracking
     */
    public function test_multiselect_dismissal_tracking(): void
    {
        $asset = $this->createAsset();
        $field = $this->createSelectField('test_field', $asset->tenant_id, ['type' => 'multiselect']);
        $this->createFieldOption($field->id, 'option1');
        $this->createFieldOption($field->id, 'option2');

        // Dismiss [option1, option2]
        $this->service->recordDismissal($asset, 'test_field', ['option1', 'option2']);
        $asset->refresh();

        // Verify [option2, option1] is also considered dismissed (order shouldn't matter)
        $this->assertTrue($this->service->isSuggestionDismissed($asset, 'test_field', ['option2', 'option1']));

        // But [option1] alone should NOT be dismissed
        $this->assertFalse($this->service->isSuggestionDismissed($asset, 'test_field', ['option1']));
    }
}
