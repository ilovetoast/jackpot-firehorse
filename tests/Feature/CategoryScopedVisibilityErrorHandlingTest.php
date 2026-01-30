<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C9.2: Test error handling for category-scoped visibility settings.
 * 
 * Verifies that the system handles errors gracefully:
 * - Invalid category_id returns proper error
 * - Missing field returns proper error
 * - Permission errors are handled
 * - Database errors are handled gracefully
 * - Frontend receives proper error messages
 */
class CategoryScopedVisibilityErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected User $viewerUser; // User without permissions
    protected Category $category;
    protected int $testFieldId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant, brand, users
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $this->user = User::factory()->create();
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin']);
        
        $this->viewerUser = User::factory()->create();
        $this->viewerUser->brands()->attach($this->brand->id, ['role' => 'viewer']);

        // Create category
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);

        // Create a test metadata field
        $this->testFieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test that invalid category_id returns proper error.
     */
    public function test_invalid_category_id_returns_error(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => false,
            'category_id' => 99999, // Non-existent category
        ]);

        $response->assertStatus(404)
            ->assertJsonStructure(['error']);
        
        $responseData = $response->json();
        $this->assertStringContainsString('Category not found', $responseData['error']);
    }

    /**
     * Test that missing field_id returns proper error.
     */
    public function test_missing_field_returns_error(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson("/app/api/tenant/metadata/fields/99999/visibility", [
            'show_on_upload' => false,
            'category_id' => $this->category->id,
        ]);

        $response->assertStatus(404)
            ->assertJsonStructure(['error']);
        
        $responseData = $response->json();
        $this->assertStringContainsString('Field not found', $responseData['error']);
    }

    /**
     * Test that unauthorized user cannot set visibility.
     */
    public function test_unauthorized_user_cannot_set_visibility(): void
    {
        $this->actingAs($this->viewerUser);

        $response = $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => false,
            'category_id' => $this->category->id,
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test that category from different brand returns error.
     */
    public function test_category_from_different_brand_returns_error(): void
    {
        $this->actingAs($this->user);

        // Create another brand and category
        $otherBrand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);
        $otherCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);

        $response = $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => false,
            'category_id' => $otherCategory->id,
        ]);

        // Should fail because category belongs to different brand
        $response->assertStatus(404)
            ->assertJsonStructure(['error']);
    }

    /**
     * Test that invalid boolean values are handled.
     */
    public function test_invalid_boolean_values_are_handled(): void
    {
        $this->actingAs($this->user);

        // Test with string "true" (should be converted)
        $response = $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => 'true', // String instead of boolean
            'category_id' => $this->category->id,
        ]);

        // Should succeed (filter_var handles string "true")
        $response->assertStatus(200);

        // Verify it was saved correctly
        $record = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $this->testFieldId)
            ->where('category_id', $this->category->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertFalse((bool) $record->is_upload_hidden, 'String "true" should be converted to boolean true');
    }

    /**
     * Test that missing category_id saves at tenant level (backward compatibility).
     */
    public function test_missing_category_id_saves_at_tenant_level(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => false,
            // No category_id - should save at tenant level
        ]);

        $response->assertStatus(200);

        // Verify tenant-level record exists
        $record = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $this->testFieldId)
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->first();

        $this->assertNotNull($record, 'Should create tenant-level visibility record');
        $this->assertTrue((bool) $record->is_upload_hidden);
    }

    /**
     * Test that updating existing record works correctly.
     */
    public function test_updating_existing_record_works(): void
    {
        $this->actingAs($this->user);

        // Create initial record
        $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => false,
            'category_id' => $this->category->id,
        ])->assertStatus(200);

        // Update to true
        $response = $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => true,
            'category_id' => $this->category->id,
        ]);

        $response->assertStatus(200);

        // Verify only one record exists (updated, not duplicated)
        $count = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $this->testFieldId)
            ->where('category_id', $this->category->id)
            ->count();

        $this->assertEquals(1, $count, 'Should have only one record (updated, not duplicated)');

        // Verify value is updated
        $record = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $this->testFieldId)
            ->where('category_id', $this->category->id)
            ->first();

        $this->assertFalse((bool) $record->is_upload_hidden, 'is_upload_hidden should be false (show_on_upload = true)');
    }

    /**
     * Test that partial updates (only one flag) don't reset other flags.
     */
    public function test_partial_updates_preserve_other_flags(): void
    {
        $this->actingAs($this->user);

        // Set all flags
        $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => false,
            'show_on_edit' => false,
            'show_in_filters' => false,
            'category_id' => $this->category->id,
        ])->assertStatus(200);

        // Update only upload
        $this->postJson("/app/api/tenant/metadata/fields/{$this->testFieldId}/visibility", [
            'show_on_upload' => true,
            'category_id' => $this->category->id,
        ])->assertStatus(200);

        // Verify other flags are preserved
        $record = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $this->testFieldId)
            ->where('category_id', $this->category->id)
            ->first();

        $this->assertFalse((bool) $record->is_upload_hidden, 'is_upload_hidden should be false (show_on_upload = true)');
        $this->assertTrue((bool) $record->is_edit_hidden, 'is_edit_hidden should still be true (preserved)');
        $this->assertTrue((bool) $record->is_filter_hidden, 'is_filter_hidden should still be true (preserved)');
    }
}
