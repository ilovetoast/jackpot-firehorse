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
 * Tag UI Consistency Tests
 *
 * Phase J.2.8: Tests for unified tag UI system
 */
class TagUIConsistencyTest extends TestCase
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
            'permissions' => json_encode(['assets.view', 'assets.manage', 'assets.tags.create', 'assets.tags.delete'])
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
     * Test: Tags field is available as system metadata field
     */
    public function test_tags_field_exists_as_system_field(): void
    {
        $tagsField = DB::table('metadata_fields')
            ->where('key', 'tags')
            ->where('scope', 'system')
            ->first();

        $this->assertNotNull($tagsField, 'Tags field should exist as system metadata field');
        $this->assertEquals('multiselect', $tagsField->type);
        $this->assertTrue((bool) $tagsField->is_filterable);
        $this->assertTrue((bool) $tagsField->show_in_filters);
    }

    /**
     * Test: Tags field can be marked as primary filter
     */
    public function test_tags_field_can_be_primary_filter(): void
    {
        // Update tags field to be primary
        DB::table('metadata_fields')
            ->where('key', 'tags')
            ->update(['is_primary' => true]);

        $tagsField = DB::table('metadata_fields')
            ->where('key', 'tags')
            ->first();

        $this->assertTrue((bool) $tagsField->is_primary);
    }

    /**
     * Test: Tags appear in metadata schema resolution
     */
    public function test_tags_appear_in_metadata_schema(): void
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
     * Test: Tag autocomplete endpoints work for unified components
     */
    public function test_tag_autocomplete_endpoints_work(): void
    {
        // Create some test tags
        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'photography',
                'source' => 'manual',
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'product-shot',
                'source' => 'ai',
                'created_at' => now(),
            ],
        ]);

        $this->actingAs($this->user);

        // Test asset-specific autocomplete (for TagInputUnified in asset mode)
        $response = $this->get("/api/assets/{$this->asset->id}/tags/autocomplete?q=photo");
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('suggestions', $data);
        
        // Should find photography
        $tags = array_column($data['suggestions'], 'tag');
        $this->assertContains('photography', $tags);

        // Test tenant-wide autocomplete (for TagInputUnified in upload/filter mode)
        $response = $this->get("/api/tenants/{$this->tenant->id}/tags/autocomplete?q=prod");
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('suggestions', $data);
        
        // Should find product-shot
        $tags = array_column($data['suggestions'], 'tag');
        $this->assertContains('product-shot', $tags);
    }

    /**
     * Test: Tag filtering works with unified filter system
     */
    public function test_tag_filtering_integration_with_metadata_system(): void
    {
        // Create tags
        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'photography',
                'source' => 'manual',
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
        $metadataResolver = app(\App\Services\MetadataSchemaResolver::class);
        
        // Get metadata schema (should include tags field)
        $schema = $metadataResolver->resolve($this->tenant->id, null, null, 'image');
        
        // Test filtering through the unified system
        $query = Asset::where('tenant_id', $this->tenant->id);
        $filters = ['tags' => ['in' => ['photography']]];
        
        $metadataFilterService->applyFilters($query, $filters, $schema);
        $results = $query->pluck('id')->toArray();
        
        $this->assertContains($this->asset->id, $results);
        $this->assertNotContains($assetWithoutTags->id, $results);
    }

    /**
     * Test: Tag CRUD operations work through unified system
     */
    public function test_tag_crud_operations_work(): void
    {
        $this->actingAs($this->user);

        // Test tag creation (used by TagInputUnified in asset mode)
        $response = $this->post("/api/assets/{$this->asset->id}/tags", [
            'tag' => 'test-tag'
        ]);
        
        $response->assertOk();
        
        // Verify tag was created
        $tag = DB::table('asset_tags')
            ->where('asset_id', $this->asset->id)
            ->where('tag', 'test-tag')
            ->first();
            
        $this->assertNotNull($tag);
        $this->assertEquals('manual', $tag->source);

        // Test tag listing (used by TagListUnified in full mode)
        $response = $this->get("/api/assets/{$this->asset->id}/tags");
        $response->assertOk();
        $data = $response->json();
        
        $this->assertArrayHasKey('tags', $data);
        $this->assertCount(1, $data['tags']);
        $this->assertEquals('test-tag', $data['tags'][0]['tag']);

        // Test tag removal (used by TagListUnified)
        $tagId = $data['tags'][0]['id'];
        $response = $this->delete("/api/assets/{$this->asset->id}/tags/{$tagId}");
        $response->assertOk();
        
        // Verify tag was removed
        $tag = DB::table('asset_tags')->where('id', $tagId)->first();
        $this->assertNull($tag);
    }

    /**
     * Test: Permissions are respected in unified system
     */
    public function test_permissions_respected_in_unified_system(): void
    {
        // Create user without tag permissions
        $limitedUser = User::factory()->create();
        $limitedUser->tenants()->attach($this->tenant->id, [
            'permissions' => json_encode(['assets.view']) // No tag permissions
        ]);

        $this->actingAs($limitedUser);

        // Should be able to view tags
        $response = $this->get("/api/assets/{$this->asset->id}/tags");
        $response->assertOk();

        // Should NOT be able to create tags
        $response = $this->post("/api/assets/{$this->asset->id}/tags", [
            'tag' => 'unauthorized-tag'
        ]);
        $response->assertStatus(403);

        // Should NOT be able to access autocomplete
        $response = $this->get("/api/assets/{$this->asset->id}/tags/autocomplete?q=test");
        $response->assertStatus(403);
    }

    /**
     * Test: Tenant isolation maintained in unified system
     */
    public function test_tenant_isolation_in_unified_system(): void
    {
        // Create another tenant with its own tags
        $otherTenant = Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
        ]);

        $otherAsset = Asset::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        DB::table('asset_tags')->insert([
            'asset_id' => $otherAsset->id,
            'tag' => 'other-tenant-tag',
            'source' => 'manual',
            'created_at' => now(),
        ]);

        $this->actingAs($this->user);

        // Autocomplete should not return tags from other tenant
        $response = $this->get("/api/tenants/{$this->tenant->id}/tags/autocomplete?q=other");
        $response->assertOk();
        $data = $response->json();
        
        $tags = array_column($data['suggestions'], 'tag');
        $this->assertNotContains('other-tenant-tag', $tags);
    }
}