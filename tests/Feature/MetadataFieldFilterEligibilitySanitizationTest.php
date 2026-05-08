<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class MetadataFieldFilterEligibilitySanitizationTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    /**
     * @return array{0: \App\Models\Tenant, 1: \App\Models\Brand, 2: \App\Models\User, 3: Category}
     */
    private function tenantBrandUserCategory(): array
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            [
                'name' => 'Filter Elig Co',
                'slug' => 'filter-elig-co',
                'manual_plan_override' => 'starter',
            ],
            ['email' => 'filter-elig@example.com', 'first_name' => 'F', 'last_name' => 'E']
        );
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Clips',
            'slug' => 'clips-filter-test',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        return [$tenant, $brand, $user, $category];
    }

    public function test_store_text_field_sanitizes_show_in_filters_to_false(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandUserCategory();

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson('/app/tenant/metadata/fields', [
                'key' => 'custom__story_notes',
                'system_label' => 'Story Notes',
                'type' => 'text',
                'selectedCategories' => [$category->id],
                'show_in_filters' => true,
                'show_on_upload' => true,
                'show_on_edit' => true,
            ])
            ->assertSuccessful();

        $row = DB::table('metadata_fields')->where('key', 'custom__story_notes')->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->show_in_filters);
    }

    public function test_store_number_field_sanitizes_show_in_filters_to_false(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandUserCategory();

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson('/app/tenant/metadata/fields', [
                'key' => 'custom__score_x',
                'system_label' => 'Score',
                'type' => 'number',
                'selectedCategories' => [$category->id],
                'show_in_filters' => true,
            ])
            ->assertSuccessful();

        $row = DB::table('metadata_fields')->where('key', 'custom__score_x')->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->show_in_filters);
    }

    public function test_store_select_field_preserves_show_in_filters_true(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandUserCategory();

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson('/app/tenant/metadata/fields', [
                'key' => 'custom__clip_kind',
                'system_label' => 'Clip Kind',
                'type' => 'select',
                'selectedCategories' => [$category->id],
                'show_in_filters' => true,
                'options' => [
                    ['value' => 'a_roll', 'label' => 'A-Roll'],
                ],
            ])
            ->assertSuccessful();

        $row = DB::table('metadata_fields')->where('key', 'custom__clip_kind')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->show_in_filters);
    }

    public function test_update_textarea_field_cannot_enable_show_in_filters(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandUserCategory();

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson('/app/tenant/metadata/fields', [
                'key' => 'custom__notes_block',
                'system_label' => 'Notes',
                'type' => 'textarea',
                'selectedCategories' => [$category->id],
                'show_in_filters' => false,
                'options' => [],
            ])
            ->assertSuccessful();

        $fieldId = (int) DB::table('metadata_fields')->where('key', 'custom__notes_block')->value('id');

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->putJson("/app/tenant/metadata/fields/{$fieldId}", [
                'show_in_filters' => true,
            ])
            ->assertSuccessful();

        $row = DB::table('metadata_fields')->where('id', $fieldId)->first();
        $this->assertFalse((bool) $row->show_in_filters);
    }

    public function test_visibility_text_field_sanitizes_filter_and_primary(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandUserCategory();

        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'vis_text_elig',
            'system_label' => 'Vis Text',
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

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson("/app/api/tenant/metadata/fields/{$fieldId}/visibility", [
                'show_in_filters' => true,
                'is_primary' => true,
                'category_id' => $category->id,
            ])
            ->assertSuccessful();

        $rec = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('category_id', $category->id)
            ->first();

        $this->assertNotNull($rec);
        $this->assertTrue((bool) $rec->is_filter_hidden);
        $this->assertFalse((bool) $rec->is_primary);
    }

    public function test_visibility_select_primary_implies_show_in_filters(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandUserCategory();

        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'vis_select_primary',
            'system_label' => 'Vis Select',
            'type' => 'select',
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

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson("/app/api/tenant/metadata/fields/{$fieldId}/visibility", [
                'is_primary' => true,
                'category_id' => $category->id,
            ])
            ->assertSuccessful();

        $rec = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('category_id', $category->id)
            ->first();

        $this->assertNotNull($rec);
        $this->assertFalse((bool) $rec->is_filter_hidden);
        $this->assertTrue((bool) $rec->is_primary);
    }

    public function test_visibility_select_show_in_filters_false_clears_primary(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandUserCategory();

        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'vis_select_off',
            'system_label' => 'Vis Select Off',
            'type' => 'select',
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

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson("/app/api/tenant/metadata/fields/{$fieldId}/visibility", [
                'show_in_filters' => false,
                'is_primary' => true,
                'category_id' => $category->id,
            ])
            ->assertSuccessful();

        $rec = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('category_id', $category->id)
            ->first();

        $this->assertNotNull($rec);
        $this->assertTrue((bool) $rec->is_filter_hidden);
        $this->assertFalse((bool) $rec->is_primary);
    }
}
