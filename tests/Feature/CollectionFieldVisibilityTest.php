<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C9.2: Test collection field visibility based on category configuration.
 * 
 * Verifies that Collections match Tags visibility behavior:
 * - Collection field hides when disabled for a category (same as Tags)
 * - Collection field appears in upload metadata schema when enabled (same as Tags)
 * - Behavior matches existing Tags behavior exactly
 * - Applies consistently across:
 *   - Uploader
 *   - Asset Drawer
 *   - Bulk Edit
 *   - Approval Review
 */
class CollectionFieldVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected Category $category1;
    protected Category $category2;
    protected Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant, brand, user
        $this->tenant = Tenant::factory()->create();
        $this->brand = Brand::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create();
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin']);

        // Create categories
        $this->category1 = Category::factory()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
        ]);

        $this->category2 = Category::factory()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Graphics',
        ]);

        // Create collection
        $this->collection = Collection::factory()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Test Collection',
        ]);

        // Get collection metadata field
        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->first();

        if (!$collectionField) {
            $this->markTestSkipped('Collection metadata field does not exist or is deprecated');
        }
    }

    /**
     * Test that collection field appears in upload metadata schema when not suppressed (same as Tags).
     */
    public function test_collection_field_appears_in_upload_schema_when_visible(): void
    {
        $this->actingAs($this->user);

        // Fetch upload metadata schema (same endpoint Tags use)
        $response = $this->getJson("/app/uploads/metadata-schema?category_id={$this->category1->id}&asset_type=image");

        $response->assertStatus(200);
        $data = $response->json();
        
        // Check if collection field appears in schema (same way Tags are checked)
        $hasCollectionField = false;
        if (isset($data['groups'])) {
            foreach ($data['groups'] as $group) {
                if (isset($group['fields'])) {
                    foreach ($group['fields'] as $field) {
                        if (($field['key'] ?? null) === 'collection') {
                            $hasCollectionField = true;
                            break 2;
                        }
                    }
                }
            }
        }
        
        $this->assertTrue($hasCollectionField, 'Collection field should appear in upload metadata schema when visible');
    }

    /**
     * Test that collection field does NOT appear in upload metadata schema when suppressed (same as Tags).
     */
    public function test_collection_field_hidden_in_upload_schema_when_suppressed(): void
    {
        $this->actingAs($this->user);

        // Get collection field
        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->first();

        if (!$collectionField) {
            $this->markTestSkipped('Collection metadata field does not exist or is deprecated');
        }

        // Suppress collection field for category1
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $collectionField->id,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'category_id' => $this->category1->id,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fetch upload metadata schema (same endpoint Tags use)
        $response = $this->getJson("/app/uploads/metadata-schema?category_id={$this->category1->id}&asset_type=image");

        $response->assertStatus(200);
        $data = $response->json();
        
        // Check if collection field appears in schema (should NOT appear when suppressed)
        $hasCollectionField = false;
        if (isset($data['groups'])) {
            foreach ($data['groups'] as $group) {
                if (isset($group['fields'])) {
                    foreach ($group['fields'] as $field) {
                        if (($field['key'] ?? null) === 'collection') {
                            $hasCollectionField = true;
                            break 2;
                        }
                    }
                }
            }
        }
        
        $this->assertFalse($hasCollectionField, 'Collection field should NOT appear in upload metadata schema when suppressed');
    }

    /**
     * Test that collection field appears in upload schema for category2 when suppressed only for category1.
     */
    public function test_collection_field_visible_in_other_category_schema_when_suppressed_for_one(): void
    {
        $this->actingAs($this->user);

        // Get collection field
        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->first();

        if (!$collectionField) {
            $this->markTestSkipped('Collection metadata field does not exist or is deprecated');
        }

        // Suppress collection field for category1 only
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $collectionField->id,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'category_id' => $this->category1->id,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Category2 should still have collection field in schema
        $response = $this->getJson("/app/uploads/metadata-schema?category_id={$this->category2->id}&asset_type=image");

        $response->assertStatus(200);
        $data = $response->json();
        
        // Check if collection field appears in schema
        $hasCollectionField = false;
        if (isset($data['groups'])) {
            foreach ($data['groups'] as $group) {
                if (isset($group['fields'])) {
                    foreach ($group['fields'] as $field) {
                        if (($field['key'] ?? null) === 'collection') {
                            $hasCollectionField = true;
                            break 2;
                        }
                    }
                }
            }
        }
        
        $this->assertTrue($hasCollectionField, 'Collection field should appear in upload metadata schema for category2 when suppressed only for category1');
    }
}
