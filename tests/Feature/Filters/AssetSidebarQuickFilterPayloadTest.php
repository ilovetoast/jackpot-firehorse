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
 * Phase 3 — verifies that AssetController::index ships a per-category
 * `quick_filters` array shaped for the sidebar. Drives the rendering layer
 * since we have no JS test infra.
 *
 * Uses `format=json` so we assert plain JSON without booting Inertia. We use
 * the activated-admin trait because creating a *second* brand on a fresh
 * tenant exceeds single-brand plan limits and EnsureGatewayEntry would 302
 * to the same URL in a redirect loop.
 */
class AssetSidebarQuickFilterPayloadTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: Brand, 2: User} */
    private function bootstrap(string $slugSuffix): array
    {
        Permission::findOrCreate('asset.view', 'web');
        Permission::findOrCreate('metadata.bypass_approval', 'web');

        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            [
                'name' => 'P3 '.$slugSuffix,
                'slug' => 'p3-'.$slugSuffix,
                'manual_plan_override' => 'starter',
            ],
            [
                'email' => 'sidebar-'.$slugSuffix.'@example.com',
                'first_name' => 'S',
                'last_name' => 'B',
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

    private function getPayload(User $user, Tenant $tenant, Brand $brand): array
    {
        return $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/app/assets?format=json')
            ->json();
    }

    public function test_sidebar_payload_includes_quick_filters_per_category(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('payload');
        $folder = $this->makeCategory($tenant, $brand, 'photography-test');

        $field = $this->makeField('photo_type_p3', 'select', ['system_label' => 'Photo type']);
        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folder, $field, ['order' => 0]);

        $payload = $this->getPayload($user, $tenant, $brand);
        $categories = $payload['categories'] ?? [];
        $this->assertIsArray($categories);

        $match = collect($categories)->firstWhere('id', $folder->id);
        $this->assertNotNull($match, 'Active brand category must appear in sidebar payload');
        $this->assertArrayHasKey('quick_filters', $match);
        $this->assertCount(1, $match['quick_filters']);

        $row = $match['quick_filters'][0];
        $this->assertSame($field->id, $row['metadata_field_id']);
        $this->assertSame('photo_type_p3', $row['field_key']);
        $this->assertSame('Photo type', $row['label']);
        $this->assertSame('select', $row['field_type']);
        $this->assertSame(0, $row['order']);
        $this->assertSame('manual', $row['source']);
    }

    public function test_sidebar_payload_orders_quick_filters_by_explicit_order_then_alpha(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('order');
        $folder = $this->makeCategory($tenant, $brand, 'photography-order');

        $alpha = $this->makeField('photo_alpha_p3', 'select', ['system_label' => 'Alpha']);
        $bravo = $this->makeField('photo_bravo_p3', 'select', ['system_label' => 'Bravo']);
        $charlie = $this->makeField('photo_charlie_p3', 'select', ['system_label' => 'Charlie']);

        $svc = app(FolderQuickFilterAssignmentService::class);
        $svc->enableQuickFilter($folder, $charlie, ['order' => 0]);
        $svc->enableQuickFilter($folder, $alpha, ['order' => 1]);
        $svc->enableQuickFilter($folder, $bravo); // null order → alpha tail

        $payload = $this->getPayload($user, $tenant, $brand);
        $match = collect($payload['categories'] ?? [])->firstWhere('id', $folder->id);
        $this->assertNotNull($match);
        $keys = array_column($match['quick_filters'], 'field_key');

        $this->assertSame(
            ['photo_charlie_p3', 'photo_alpha_p3', 'photo_bravo_p3'],
            $keys
        );
    }

    public function test_sidebar_payload_excludes_ineligible_filters(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('inelig');
        $folder = $this->makeCategory($tenant, $brand, 'photography-inelig');

        $field = $this->makeField('photo_inelig_p3', 'select');
        app(FolderQuickFilterAssignmentService::class)->enableQuickFilter($folder, $field);

        // Field type changes to text — assignment row stays but it must not
        // surface in the payload.
        DB::table('metadata_fields')->where('id', $field->id)->update(['type' => 'text']);

        $payload = $this->getPayload($user, $tenant, $brand);
        $match = collect($payload['categories'] ?? [])->firstWhere('id', $folder->id);
        $this->assertNotNull($match);
        $this->assertSame([], $match['quick_filters']);
    }

    public function test_sidebar_payload_renders_empty_array_when_feature_disabled(): void
    {
        config(['categories.folder_quick_filters.enabled' => false]);

        [$tenant, $brand, $user] = $this->bootstrap('disabled');
        $folder = $this->makeCategory($tenant, $brand, 'photography-disabled');

        $payload = $this->getPayload($user, $tenant, $brand);
        $match = collect($payload['categories'] ?? [])->firstWhere('id', $folder->id);
        $this->assertNotNull($match);
        $this->assertArrayHasKey('quick_filters', $match);
        $this->assertSame([], $match['quick_filters']);
    }

    public function test_sidebar_payload_renders_empty_array_for_categories_without_quick_filters(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('empty');
        $folder = $this->makeCategory($tenant, $brand, 'documents-empty');

        $payload = $this->getPayload($user, $tenant, $brand);
        $match = collect($payload['categories'] ?? [])->firstWhere('id', $folder->id);
        $this->assertNotNull($match);
        $this->assertSame([], $match['quick_filters']);
    }

    public function test_more_than_max_visible_filters_are_still_payload_so_overflow_can_render(): void
    {
        // The frontend slices to max_visible_per_folder; the payload itself
        // must include the full list so the "+N more" row knows the count.
        [$tenant, $brand, $user] = $this->bootstrap('overflow');
        $folder = $this->makeCategory($tenant, $brand, 'photography-overflow');

        $svc = app(FolderQuickFilterAssignmentService::class);
        for ($i = 0; $i < 5; $i++) {
            $f = $this->makeField('photo_overflow_p3_'.$i, 'select');
            $svc->enableQuickFilter($folder, $f, ['order' => $i]);
        }

        $payload = $this->getPayload($user, $tenant, $brand);
        $match = collect($payload['categories'] ?? [])->firstWhere('id', $folder->id);
        $this->assertNotNull($match);
        $this->assertCount(5, $match['quick_filters']);
    }
}
