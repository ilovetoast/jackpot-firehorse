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
 * Phase 4 / 5 — covers the value picker endpoint that powers the sidebar
 * quick filter flyout. Asserts gates, shape, and (Phase 5) the presence of
 * count payload fields. Per-value count correctness lives in
 * `AssetMetadataFacetCountProviderTest`.
 */
class FolderQuickFilterValueEndpointTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The endpoint shape tests below assert structure regardless of
        // count provider behavior. Disable the cache layer so test
        // assertions are not influenced by stale entries from a prior test.
        config(['categories.folder_quick_filters.facet_cache_enabled' => false]);
    }

    /** @return array{0: Tenant, 1: Brand, 2: User} */
    private function bootstrap(string $slugSuffix): array
    {
        Permission::findOrCreate('asset.view', 'web');
        Permission::findOrCreate('metadata.bypass_approval', 'web');

        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            [
                'name' => 'P4 '.$slugSuffix,
                'slug' => 'p4-'.$slugSuffix,
                'manual_plan_override' => 'starter',
            ],
            [
                'email' => 'p4-values-'.$slugSuffix.'@example.com',
                'first_name' => 'P',
                'last_name' => 'V',
            ]
        );

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.view', 'metadata.bypass_approval']);
        $user->assignRole($role);
        $user->setRoleForTenant($tenant, 'admin');

        return [$tenant, $brand, $user];
    }

    private function makeField(string $key, string $type, array $overrides = []): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId(array_merge([
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
        ], $overrides));

        return MetadataField::query()->findOrFail($id);
    }

    /** Insert N options for a field. Returns ordered list of values inserted. */
    private function makeOptions(MetadataField $field, array $valueLabel): void
    {
        foreach ($valueLabel as $value => $label) {
            DB::table('metadata_options')->insert([
                'metadata_field_id' => $field->id,
                'value' => $value,
                'system_label' => $label,
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
            'requires_approval' => false,
        ]);
    }

    private function url(Category $folder, MetadataField $field): string
    {
        return "/app/api/tenant/folders/{$folder->id}/quick-filters/{$field->id}/values";
    }

    public function test_returns_options_for_select_field(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('select');
        $folder = $this->makeCategory($tenant, $brand, 'photography-select');
        $field = $this->makeField('p4_photo_type', 'select', ['system_label' => 'Photo type']);
        $this->makeOptions($field, [
            'studio' => 'Studio',
            'outdoor' => 'Outdoor',
            'lifestyle' => 'Lifestyle',
        ]);
        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folder, $field);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folder, $field));

        $response->assertStatus(200);
        $payload = $response->json();

        $this->assertSame($field->id, $payload['field']['id']);
        $this->assertSame('p4_photo_type', $payload['field']['key']);
        $this->assertSame('Photo type', $payload['field']['label']);
        $this->assertSame('select', $payload['field']['type']);

        $values = $payload['values'];
        $this->assertSame(['lifestyle', 'outdoor', 'studio'], array_column($values, 'value'));
        foreach ($values as $row) {
            $this->assertSame(false, $row['selected']);
            // Phase 5: counts are present and integer-typed. The folder has
            // no assets in this test so every visible value is 0.
            $this->assertArrayHasKey('count', $row);
            $this->assertSame(0, $row['count']);
        }
        $this->assertFalse($payload['has_more']);
        $this->assertTrue($payload['counts_available']);
    }

    public function test_returns_options_for_multiselect_field(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('multi');
        $folder = $this->makeCategory($tenant, $brand, 'photography-multi');
        $field = $this->makeField('p4_subject', 'multiselect', ['system_label' => 'Subject']);
        $this->makeOptions($field, [
            'people' => 'People',
            'product' => 'Product',
        ]);
        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folder, $field);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folder, $field));

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertSame('multiselect', $payload['field']['type']);
        $this->assertCount(2, $payload['values']);
    }

    public function test_returns_yes_no_for_boolean_field(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('bool');
        $folder = $this->makeCategory($tenant, $brand, 'photography-bool');
        $field = $this->makeField('p4_starred', 'boolean', ['system_label' => 'Starred']);
        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folder, $field);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folder, $field));

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertSame('boolean', $payload['field']['type']);
        // Phase 5 — boolean rows now carry a count alongside the canonical
        // Yes/No shape. Empty folder → both counts are 0.
        $this->assertSame(
            [
                ['value' => true, 'label' => 'Yes', 'selected' => false, 'count' => 0],
                ['value' => false, 'label' => 'No', 'selected' => false, 'count' => 0],
            ],
            $payload['values']
        );
        $this->assertFalse($payload['has_more']);
        $this->assertTrue($payload['counts_available']);
    }

    public function test_rejects_ineligible_filter(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('inelig');
        $folder = $this->makeCategory($tenant, $brand, 'photography-inelig');
        $field = $this->makeField('p4_inelig', 'select');
        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folder, $field);

        // Type changes to text → eligibility now fails. Endpoint must refuse.
        DB::table('metadata_fields')->where('id', $field->id)->update(['type' => 'text']);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folder, $field));

        $response->assertStatus(422);
        $this->assertSame('ineligible_filter', $response->json('error'));
    }

    public function test_rejects_filter_not_enabled_as_quick_filter_for_folder(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('notenabled');
        $folder = $this->makeCategory($tenant, $brand, 'photography-notenabled');
        // Field exists & is eligible, but no quick filter assignment.
        $field = $this->makeField('p4_notenabled', 'select');
        $this->makeOptions($field, ['a' => 'A']);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folder, $field));

        $response->assertStatus(422);
        $this->assertSame('not_enabled_for_folder', $response->json('error'));
    }

    public function test_rejects_when_feature_disabled(): void
    {
        config(['categories.folder_quick_filters.enabled' => false]);

        [$tenant, $brand, $user] = $this->bootstrap('disabled');
        $folder = $this->makeCategory($tenant, $brand, 'photography-disabled');
        $field = $this->makeField('p4_disabled', 'select');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folder, $field));

        $response->assertStatus(422);
        $this->assertSame('feature_disabled', $response->json('error'));
    }

    public function test_respects_max_visible_values_and_sets_has_more(): void
    {
        config(['categories.folder_quick_filters.max_visible_values_per_filter' => 3]);

        [$tenant, $brand, $user] = $this->bootstrap('maxvis');
        $folder = $this->makeCategory($tenant, $brand, 'photography-maxvis');
        $field = $this->makeField('p4_maxvis', 'multiselect');
        $this->makeOptions($field, [
            'a' => 'Alpha',
            'b' => 'Bravo',
            'c' => 'Charlie',
            'd' => 'Delta',
            'e' => 'Echo',
        ]);
        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folder, $field);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folder, $field));

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertCount(3, $payload['values']);
        $this->assertTrue($payload['has_more']);
        $this->assertSame(3, $payload['limit']);
    }

    public function test_excludes_options_hidden_at_category_scope(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('hidden');
        $folder = $this->makeCategory($tenant, $brand, 'photography-hidden');
        $field = $this->makeField('p4_hidden', 'select');
        $this->makeOptions($field, [
            'visible' => 'Visible',
            'hidden_one' => 'Hidden one',
        ]);

        // Hide one option specifically for this folder.
        $hiddenOption = DB::table('metadata_options')
            ->where('metadata_field_id', $field->id)
            ->where('value', 'hidden_one')
            ->first();
        DB::table('metadata_option_visibility')->insert([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $folder->id,
            'metadata_option_id' => $hiddenOption->id,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folder, $field);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folder, $field));

        $response->assertStatus(200);
        $values = $response->json('values');
        $this->assertSame(['visible'], array_column($values, 'value'));
    }

    public function test_returns_404_for_category_outside_tenant(): void
    {
        [$tenantA, $brandA, $userA] = $this->bootstrap('cross-a');
        [$tenantB, $brandB, $_] = $this->bootstrap('cross-b');
        $folderB = $this->makeCategory($tenantB, $brandB, 'photography-cross-b');
        $field = $this->makeField('p4_cross', 'select');
        $this->makeOptions($field, ['x' => 'X']);
        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folderB, $field);

        // tenantA user tries to read tenantB's folder.
        $response = $this->actingAs($userA)
            ->withSession(['tenant_id' => $tenantA->id, 'brand_id' => $brandA->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->url($folderB, $field));

        $response->assertStatus(404);
    }
}
