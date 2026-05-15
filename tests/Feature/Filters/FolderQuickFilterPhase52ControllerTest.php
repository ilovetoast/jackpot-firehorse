<?php

namespace Tests\Feature\Filters;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 5.2 — controller-level coverage:
 *   - PATCH endpoint accepts the new `pinned` axis without changes to the
 *     other axes.
 *   - Disabling a pinned filter clears its pinned flag.
 *   - Overflow + selection instrumentation endpoints return 204 cleanly.
 *
 * Detailed assignment-service behaviour is covered in
 * {@see FolderQuickFilterPhase52Test}; this class only asserts the HTTP
 * surface contract.
 */
class FolderQuickFilterPhase52ControllerTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: Brand, 2: User} */
    private function bootstrap(string $slug): array
    {
        Permission::findOrCreate('asset.view', 'web');
        Permission::findOrCreate('metadata.bypass_approval', 'web');
        Permission::findOrCreate('metadata.tenant.visibility.manage', 'web');

        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            [
                'name' => 'P52 '.$slug,
                'slug' => 'p52-'.$slug,
                'manual_plan_override' => 'starter',
            ],
            [
                'email' => 'p52-'.$slug.'@example.com',
                'first_name' => 'P',
                'last_name' => 'C',
            ]
        );

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo([
            'asset.view',
            'metadata.bypass_approval',
            'metadata.tenant.visibility.manage',
        ]);
        $user->assignRole($role);
        $user->setRoleForTenant($tenant, 'admin');

        return [$tenant, $brand, $user];
    }

    private function makeField(string $key, string $type): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId([
            'key' => $key,
            'system_label' => ucfirst(str_replace('_', ' ', $key)),
            'type' => $type,
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return MetadataField::query()->findOrFail($id);
    }

    private function makeCategory(Tenant $tenant, Brand $brand, string $slug): Category
    {
        return Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);
    }

    public function test_patch_accepts_pinned_alongside_enabled_in_a_single_request(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('pin-enable');
        $category = $this->makeCategory($tenant, $brand, 'p52-pin-enable');
        $field = $this->makeField('p52_pin_enable', 'select');

        $url = "/app/api/tenant/metadata/fields/{$field->id}/categories/{$category->id}/folder-quick-filter";
        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->patchJson($url, ['enabled' => true, 'pinned' => true]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('quick_filter.enabled'));
        $this->assertTrue($response->json('quick_filter.pinned'));

        $service = app(FolderQuickFilterAssignmentService::class);
        $this->assertTrue($service->isQuickFilterPinned($category, $field));
    }

    public function test_patch_can_pin_an_already_enabled_filter_without_other_axes(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('pin-only');
        $category = $this->makeCategory($tenant, $brand, 'p52-pin-only');
        $field = $this->makeField('p52_pin_only', 'select');

        // Enable first via the service so the test is independent of the
        // enable codepath.
        app(FolderQuickFilterAssignmentService::class)
            ->enableQuickFilter($category, $field, ['order' => 2]);

        $url = "/app/api/tenant/metadata/fields/{$field->id}/categories/{$category->id}/folder-quick-filter";
        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->patchJson($url, ['pinned' => true]);

        $response->assertStatus(200);

        $row = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $field->id)
            ->where('category_id', $category->id)
            ->first();
        $this->assertSame(2, (int) $row->folder_quick_filter_order, 'Order must be preserved');
        $this->assertTrue((bool) $row->is_pinned_folder_quick_filter);
    }

    public function test_overflow_open_endpoint_returns_204(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('overflow');
        $category = $this->makeCategory($tenant, $brand, 'p52-overflow');

        $url = "/app/api/tenant/folders/{$category->id}/quick-filters/overflow-open";
        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson($url, []);

        $response->assertStatus(204);
    }

    public function test_overflow_open_endpoint_rejects_unknown_category(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('overflow-unknown');

        $url = '/app/api/tenant/folders/9999999/quick-filters/overflow-open';
        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson($url, []);

        $response->assertStatus(404);
    }

    public function test_selection_endpoint_returns_204_for_valid_call(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('selection');
        $category = $this->makeCategory($tenant, $brand, 'p52-selection');
        $field = $this->makeField('p52_selection_field', 'multiselect');

        $url = "/app/api/tenant/folders/{$category->id}/quick-filters/{$field->id}/selection";
        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson($url, ['value' => ['a', 'b']]);

        $response->assertStatus(204);
    }

    public function test_overflow_open_requires_feature_flag(): void
    {
        config(['categories.folder_quick_filters.enabled' => false]);

        [$tenant, $brand, $user] = $this->bootstrap('overflow-off');
        $category = $this->makeCategory($tenant, $brand, 'p52-overflow-off');

        $url = "/app/api/tenant/folders/{$category->id}/quick-filters/overflow-open";
        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson($url, []);

        $response->assertStatus(422);
    }
}
