<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MetadataSystemFieldHybridRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_reveal_system_seeded_field_visibility_clears_provision_source(): void
    {
        $tenant = Tenant::factory()->create();
        $brand = Brand::factory()->create(['tenant_id' => $tenant->id]);

        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'hybrid_test_field',
            'system_label' => 'Hybrid test',
            'type' => 'text',
            'applies_to' => 'all',
            'scope' => 'system',
            'tenant_id' => null,
            'is_active' => true,
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'test',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'population_mode' => 'manual',
            'show_on_upload' => true,
            'show_on_edit' => true,
            'show_in_filters' => true,
            'readonly' => false,
            'is_primary' => false,
            'archived_at' => null,
            'ai_eligible' => false,
            'display_widget' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category = Category::query()->create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Hybrid test cat',
            'slug' => 'hybrid-test-'.uniqid('', true),
            'is_system' => true,
        ]);

        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => $category->brand_id,
            'category_id' => $category->id,
            'is_hidden' => true,
            'is_upload_hidden' => true,
            'is_filter_hidden' => true,
            'is_edit_hidden' => true,
            'is_primary' => null,
            'is_required' => null,
            'provision_source' => 'system_seed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(TenantMetadataVisibilityService::class);
        $this->assertSame(1, $svc->countPendingSystemSeededFieldRows($tenant->id));

        $updated = $svc->revealSystemSeededFieldVisibilityForTenant($tenant->id);
        $this->assertSame(1, $updated);
        $this->assertSame(0, $svc->countPendingSystemSeededFieldRows($tenant->id));

        $row = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $fieldId)
            ->first();

        $this->assertNull($row->provision_source);
        $this->assertFalse((bool) $row->is_hidden);
    }
}
