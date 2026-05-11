<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAdminBrandSetupGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_member_cannot_access_brand_guidelines_index(): void
    {
        Permission::firstOrCreate(['name' => 'asset.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view brand', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'brand_settings.manage', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Co', 'slug' => 'co']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b',
        ]);

        $member = User::factory()->create();
        $member->tenants()->attach($tenant->id, ['role' => 'member']);
        $member->brands()->attach($brand->id, ['role' => 'brand_manager', 'removed_at' => null]);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::all());
        $member->assignRole($role);

        $this->actingAs($member)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('brands.guidelines.index', ['brand' => $brand->id]))
            ->assertRedirect(route('overview'));
    }

    public function test_tenant_admin_can_access_onboarding_status_json(): void
    {
        $tenant = Tenant::create(['name' => 'Co2', 'slug' => 'co2']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B2',
            'slug' => 'b2',
        ]);

        $admin = User::factory()->create();
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/onboarding/status')
            ->assertOk()
            ->assertJsonStructure(['progress', 'checklist']);
    }

    public function test_tenant_member_cannot_access_onboarding_status_json(): void
    {
        $tenant = Tenant::create(['name' => 'Co3', 'slug' => 'co3']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B3',
            'slug' => 'b3',
        ]);

        $member = User::factory()->create();
        $member->tenants()->attach($tenant->id, ['role' => 'member']);
        $member->brands()->attach($brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->actingAs($member)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/onboarding/status')
            ->assertForbidden();
    }
}
