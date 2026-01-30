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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * C9.2: Test collection field visibility based on category configuration.
 *
 * Verifies that Collections match Tags visibility behavior:
 * - Collection field hides when disabled for a category (same as Tags)
 * - Collection field appears in upload metadata schema when enabled (same as Tags)
 * - Edit schema (drawer/quick view) respects Quick View (is_edit_hidden) – no "checked but still visible" bugs
 * - Applies consistently across: Uploader, Asset Drawer, Bulk Edit, Approval Review
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
     * Helper: assert schema response contains (or not) the collection field in any group.
     */
    protected static function schemaHasCollectionField(array $data): bool
    {
        if (!isset($data['groups']) || !is_array($data['groups'])) {
            return false;
        }
        foreach ($data['groups'] as $group) {
            $fields = $group['fields'] ?? [];
            foreach ($fields as $field) {
                if (($field['key'] ?? $field['field_key'] ?? null) === 'collection') {
                    return true;
                }
            }
        }
        return false;
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
        $this->assertTrue(
            self::schemaHasCollectionField($data),
            'Collection field should appear in upload metadata schema when visible'
        );
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
        $this->assertFalse(
            self::schemaHasCollectionField($data),
            'Collection field should NOT appear in upload metadata schema when suppressed'
        );
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
        $this->assertTrue(
            self::schemaHasCollectionField($data),
            'Collection field should appear in upload metadata schema for category2 when suppressed only for category1'
        );
    }

    /**
     * C9.2: Edit schema (drawer/quick view) must NOT include collection when Quick View is unchecked.
     * Prevents "checked but still visible" bug – drawer must respect Metadata Management Quick View.
     */
    public function test_edit_schema_excludes_collection_when_quick_view_unchecked(): void
    {
        $this->actingAs($this->user);

        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->first();

        if (!$collectionField) {
            $this->markTestSkipped('Collection metadata field does not exist or is deprecated');
        }

        $visibilityRow = [
            'metadata_field_id' => $collectionField->id,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'category_id' => $this->category1->id,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
            $visibilityRow['is_edit_hidden'] = true; // Quick View unchecked
        }
        DB::table('metadata_field_visibility')->insert($visibilityRow);

        $response = $this->getJson("/app/uploads/metadata-schema?category_id={$this->category1->id}&asset_type=image&context=edit");
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertFalse(
            self::schemaHasCollectionField($data),
            'Edit schema (drawer) must NOT include collection when Quick View is unchecked (is_edit_hidden=true)'
        );
    }

    /**
     * C9.2: Edit schema (drawer) MUST include collection when Quick View is checked (is_edit_hidden=false or not set).
     */
    public function test_edit_schema_includes_collection_when_quick_view_checked(): void
    {
        $this->actingAs($this->user);

        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->first();

        if (!$collectionField) {
            $this->markTestSkipped('Collection metadata field does not exist or is deprecated');
        }

        $visibilityRow = [
            'metadata_field_id' => $collectionField->id,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'category_id' => $this->category1->id,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
            $visibilityRow['is_edit_hidden'] = false; // Quick View checked
        }
        DB::table('metadata_field_visibility')->insert($visibilityRow);

        $response = $this->getJson("/app/uploads/metadata-schema?category_id={$this->category1->id}&asset_type=image&context=edit");
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertTrue(
            self::schemaHasCollectionField($data),
            'Edit schema (drawer) must include collection when Quick View is checked (is_edit_hidden=false)'
        );
    }

    /**
     * C9.2: Upload and edit schema can differ – collection in upload but not in edit when Quick View off.
     */
    public function test_upload_schema_can_include_collection_while_edit_schema_excludes_it(): void
    {
        $this->actingAs($this->user);

        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->first();

        if (!$collectionField) {
            $this->markTestSkipped('Collection metadata field does not exist or is deprecated');
        }
        if (!Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
            $this->markTestSkipped('metadata_field_visibility.is_edit_hidden column does not exist');
        }

        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $collectionField->id,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'category_id' => $this->category1->id,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'is_edit_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uploadResponse = $this->getJson("/app/uploads/metadata-schema?category_id={$this->category1->id}&asset_type=image&context=upload");
        $uploadResponse->assertStatus(200);
        $this->assertTrue(
            self::schemaHasCollectionField($uploadResponse->json()),
            'Upload schema should include collection when Upload is checked'
        );

        $editResponse = $this->getJson("/app/uploads/metadata-schema?category_id={$this->category1->id}&asset_type=image&context=edit");
        $editResponse->assertStatus(200);
        $this->assertFalse(
            self::schemaHasCollectionField($editResponse->json()),
            'Edit schema (drawer) must NOT include collection when Quick View is unchecked'
        );
    }
}
