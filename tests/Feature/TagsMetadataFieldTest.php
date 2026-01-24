<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tags Metadata Field Tests
 *
 * Phase J.2.7: Tests for Tags as metadata field registration and filtering
 */
class TagsMetadataFieldTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        // Create user
        $this->user = User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        $this->user->tenants()->attach($this->tenant->id, [
            'permissions' => json_encode(['assets.view', 'assets.manage'])
        ]);

        // Create asset
        $brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $storageBucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $this->asset = Asset::create([
            'tenant_id' => $this->tenant->id,
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

        // Set up app context
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $brand);
    }

    /**
     * Test: Tags field is registered as system metadata field
     */
    public function test_tags_field_registered_as_system_metadata(): void
    {
        // Check that tags field exists in metadata_fields table
        $tagsField = DB::table('metadata_fields')
            ->where('key', 'tags')
            ->where('scope', 'system')
            ->first();

        $this->assertNotNull($tagsField, 'Tags field should be registered');
        $this->assertEquals('Tags', $tagsField->system_label);
        $this->assertEquals('multiselect', $tagsField->type);
        $this->assertEquals('all', $tagsField->applies_to);
        $this->assertEquals('general', $tagsField->group_key);
        $this->assertTrue((bool) $tagsField->is_filterable);
        $this->assertTrue((bool) $tagsField->show_in_filters);
        $this->assertTrue((bool) $tagsField->is_user_editable);
    }

    /**
     * Test: Tags field appears in metadata schema resolution
     */
    public function test_tags_field_in_metadata_schema(): void
    {
        $metadataResolver = app(\App\Services\MetadataSchemaResolver::class);
        
        $schema = $metadataResolver->resolve(
            $this->tenant->id,
            null, // brand_id
            null, // category_id
            'image' // asset_type
        );

        // Find tags field in schema
        $tagsField = collect($schema['fields'] ?? [])->firstWhere('key', 'tags');
        
        $this->assertNotNull($tagsField, 'Tags field should appear in metadata schema');
        $this->assertEquals('Tags', $tagsField['label']);
        $this->assertEquals('multiselect', $tagsField['type']);
        $this->assertTrue($tagsField['is_filterable']);
    }

    /**
     * Test: Tags filtering works through MetadataFilterService
     */
    public function test_tags_filtering_in_asset_queries(): void
    {
        // Create some test tags for the asset
        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'photography',
                'source' => 'manual',
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'product',
                'source' => 'ai',
                'created_at' => now(),
            ],
        ]);

        // Create another asset without tags
        $assetWithoutTags = Asset::factory()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->asset->brand_id,
            'upload_session_id' => $this->asset->upload_session_id,
            'storage_bucket_id' => $this->asset->storage_bucket_id,
        ]);

        $metadataFilterService = app(\App\Services\MetadataFilterService::class);
        
        // Test 1: Filter by single tag
        $query = Asset::where('tenant_id', $this->tenant->id);
        $filters = ['tags' => ['in' => ['photography']]];
        $schema = ['fields' => []]; // Empty schema since tags are handled specially
        
        $metadataFilterService->applyFilters($query, $filters, $schema);
        $results = $query->pluck('id')->toArray();
        
        $this->assertContains($this->asset->id, $results);
        $this->assertNotContains($assetWithoutTags->id, $results);

        // Test 2: Filter by multiple tags (any)
        $query2 = Asset::where('tenant_id', $this->tenant->id);
        $filters2 = ['tags' => ['in' => ['photography', 'marketing']]];
        
        $metadataFilterService->applyFilters($query2, $filters2, $schema);
        $results2 = $query2->pluck('id')->toArray();
        
        $this->assertContains($this->asset->id, $results2); // Has photography
        $this->assertNotContains($assetWithoutTags->id, $results2); // Has no tags

        // Test 3: Filter assets with no tags
        $query3 = Asset::where('tenant_id', $this->tenant->id);
        $filters3 = ['tags' => ['empty' => true]];
        
        $metadataFilterService->applyFilters($query3, $filters3, $schema);
        $results3 = $query3->pluck('id')->toArray();
        
        $this->assertNotContains($this->asset->id, $results3); // Has tags
        $this->assertContains($assetWithoutTags->id, $results3); // Has no tags
    }

    /**
     * Test: Tags filtering with ALL operator
     */
    public function test_tags_filtering_all_operator(): void
    {
        // Create asset with multiple tags
        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'photography',
                'source' => 'manual',
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'product',
                'source' => 'manual',
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'marketing',
                'source' => 'manual',
                'created_at' => now(),
            ],
        ]);

        // Create another asset with partial tags
        $partialAsset = Asset::factory()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->asset->brand_id,
            'upload_session_id' => $this->asset->upload_session_id,
            'storage_bucket_id' => $this->asset->storage_bucket_id,
        ]);

        DB::table('asset_tags')->insert([
            'asset_id' => $partialAsset->id,
            'tag' => 'photography',
            'source' => 'manual',
            'created_at' => now(),
        ]);

        $metadataFilterService = app(\App\Services\MetadataFilterService::class);
        
        // Test: Must have ALL specified tags
        $query = Asset::where('tenant_id', $this->tenant->id);
        $filters = ['tags' => ['all' => ['photography', 'product']]];
        $schema = ['fields' => []];
        
        $metadataFilterService->applyFilters($query, $filters, $schema);
        $results = $query->pluck('id')->toArray();
        
        $this->assertContains($this->asset->id, $results); // Has both tags
        $this->assertNotContains($partialAsset->id, $results); // Only has photography
    }

    /**
     * Test: Tags filtering with contains operator
     */
    public function test_tags_filtering_contains_operator(): void
    {
        // Create tags with different names
        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'high-resolution',
                'source' => 'manual',
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'product-shot',
                'source' => 'manual',
                'created_at' => now(),
            ],
        ]);

        $metadataFilterService = app(\App\Services\MetadataFilterService::class);
        
        // Test: Search for tags containing "resolution"
        $query = Asset::where('tenant_id', $this->tenant->id);
        $filters = ['tags' => ['contains' => 'resolution']];
        $schema = ['fields' => []];
        
        $metadataFilterService->applyFilters($query, $filters, $schema);
        $results = $query->pluck('id')->toArray();
        
        $this->assertContains($this->asset->id, $results);
    }

    /**
     * Test: Tenant isolation for tags filtering
     */
    public function test_tags_filtering_tenant_isolation(): void
    {
        // Create another tenant and asset
        $otherTenant = Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
        ]);

        $otherAsset = Asset::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        // Add tag to other tenant's asset
        DB::table('asset_tags')->insert([
            'asset_id' => $otherAsset->id,
            'tag' => 'photography',
            'source' => 'manual',
            'created_at' => now(),
        ]);

        // Add same tag to our asset
        DB::table('asset_tags')->insert([
            'asset_id' => $this->asset->id,
            'tag' => 'photography',
            'source' => 'manual',
            'created_at' => now(),
        ]);

        $metadataFilterService = app(\App\Services\MetadataFilterService::class);
        
        // Filter for our tenant only
        $query = Asset::where('tenant_id', $this->tenant->id); // Important: tenant scoping
        $filters = ['tags' => ['in' => ['photography']]];
        $schema = ['fields' => []];
        
        $metadataFilterService->applyFilters($query, $filters, $schema);
        $results = $query->pluck('id')->toArray();
        
        $this->assertContains($this->asset->id, $results);
        $this->assertNotContains($otherAsset->id, $results); // Other tenant's asset excluded
    }

    /**
     * Test: Integration with existing metadata filters
     */
    public function test_tags_with_other_metadata_filters(): void
    {
        // Create a regular metadata field and value
        $campaignFieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_campaign',
            'system_label' => 'Test Campaign',
            'type' => 'text',
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'group_key' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add metadata to asset
        DB::table('asset_metadata')->insert([
            'asset_id' => $this->asset->id,
            'metadata_field_id' => $campaignFieldId,
            'value_json' => json_encode('summer2024'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add tag to asset
        DB::table('asset_tags')->insert([
            'asset_id' => $this->asset->id,
            'tag' => 'photography',
            'source' => 'manual',
            'created_at' => now(),
        ]);

        $metadataFilterService = app(\App\Services\MetadataFilterService::class);
        
        // Create schema with the test field
        $schema = [
            'fields' => [
                [
                    'id' => $campaignFieldId,
                    'key' => 'test_campaign',
                    'type' => 'text',
                    'is_filterable' => true,
                ]
            ]
        ];
        
        // Test: Combine tags filter with metadata filter
        $query = Asset::where('tenant_id', $this->tenant->id);
        $filters = [
            'tags' => ['in' => ['photography']],
            'test_campaign' => ['equals' => 'summer2024']
        ];
        
        $metadataFilterService->applyFilters($query, $filters, $schema);
        $results = $query->pluck('id')->toArray();
        
        $this->assertContains($this->asset->id, $results); // Matches both filters
    }
}