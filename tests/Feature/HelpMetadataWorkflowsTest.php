<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HelpMetadataWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * @return array{0: Tenant, 1: \App\Models\Brand, 2: User}
     */
    private function actingOwnerWithAllPermissions(): array
    {
        $tenant = Tenant::create([
            'name' => 'HM',
            'slug' => 't-help-meta',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $user = User::create([
            'email' => 'help-meta@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'M',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->syncPermissions(Permission::all());

        return [$tenant, $brand, $user];
    }

    public function test_search_add_tag_to_asset_prioritizes_single_asset_metadata_over_manage_hub(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('add tag to asset'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('assets.edit_single_metadata', $keys[0]);
        $managePos = array_search('manage.tags_fields_metadata', $keys, true);
        if ($managePos !== false) {
            $this->assertGreaterThan(0, $managePos);
        }
    }

    public function test_search_bulk_add_tags_prioritizes_bulk_metadata_topic(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('bulk add tags'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('assets.bulk_edit_metadata', $keys[0]);
    }

    public function test_search_create_custom_field_prioritizes_manage_metadata_structure_hub(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('create custom field'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('manage.tags_fields_metadata', $keys[0]);
    }

    public function test_search_metadata_coverage_prioritizes_insights_metadata_topic(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('metadata coverage'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('insights.metadata_coverage', $keys[0]);
    }

    public function test_contextual_on_assets_index_surfaces_single_and_bulk_metadata_not_manage_hub(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?route_name='.rawurlencode('assets.index'));

        $response->assertOk();
        $keys = array_column($response->json('contextual'), 'key');
        $this->assertNotContains('manage.tags_fields_metadata', $keys);
        $this->assertContains('assets.edit_single_metadata', $keys);
        $this->assertContains('assets.bulk_edit_metadata', $keys);
        $this->assertSame('assets.edit_single_metadata', $keys[0]);
    }

    public function test_contextual_on_manage_categories_prioritizes_metadata_structure_hub(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?route_name='.rawurlencode('manage.categories'));

        $response->assertOk();
        $keys = array_column($response->json('contextual'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('manage.tags_fields_metadata', $keys[0]);
    }
}
