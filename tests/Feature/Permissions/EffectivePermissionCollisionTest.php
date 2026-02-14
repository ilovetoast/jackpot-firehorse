<?php

namespace Tests\Feature\Permissions;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EffectivePermissionCollisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    #[Test]
    public function tenant_admin_and_brand_admin_have_both_permission_sets(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);

        // Assign tenant admin role (stored in tenant_user pivot)
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);

        // Assign brand admin role (stored in brand_user pivot)
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $service = app(\App\Services\AuthPermissionService::class);

        $effective = $service->effectivePermissions($user, $tenant, $brand);

        $this->assertContains('team.manage', $effective);
        $this->assertContains('asset.view', $effective);
        $this->assertContains('company_settings.view', $effective);
    }

    #[Test]
    public function brand_admin_without_tenant_admin_does_not_get_company_permissions(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company-scope',
        ]);

        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand-scope',
        ]);

        $user = User::create([
            'email' => 'brand-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Brand',
            'last_name' => 'Admin',
        ]);

        // No tenant admin role — attach as member (or no role)
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        // Brand admin role only
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $service = app(\App\Services\AuthPermissionService::class);

        $effective = $service->effectivePermissions($user, $tenant, $brand);

        $this->assertNotContains('team.manage', $effective);
        $this->assertContains('asset.view', $effective);
    }

    #[Test]
    public function brand_from_different_tenant_does_not_grant_permissions(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $brandA = Brand::create(['tenant_id' => $tenantA->id, 'name' => 'Brand A', 'slug' => 'brand-a']);
        $brandB = Brand::create(['tenant_id' => $tenantB->id, 'name' => 'Brand B', 'slug' => 'brand-b']);

        $user = User::create([
            'email' => 'cross@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Cross',
            'last_name' => 'User',
        ]);

        $user->tenants()->attach($tenantA->id, ['role' => 'member']);
        $user->brands()->attach($brandB->id, ['role' => 'admin', 'removed_at' => null]); // Brand B is in Tenant B

        $service = app(\App\Services\AuthPermissionService::class);

        // User is in Tenant A (member), but has admin role in Brand B (Tenant B)
        // effectivePermissions(tenantA, brandB) should NOT include brand B's permissions (brand not in tenant)
        // Member has asset.view from tenant role, but brand_settings.manage comes only from brand admin
        $effective = $service->effectivePermissions($user, $tenantA, $brandB);

        $this->assertNotContains('brand_settings.manage', $effective, 'Brand from different tenant must not grant permissions');
    }

    #[Test]
    public function auth_permission_service_can_checks_effective_permissions(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'Test Brand', 'slug' => 'test-brand']);

        $admin = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $viewer = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Viewer',
            'last_name' => 'User',
        ]);
        $viewer->tenants()->attach($tenant->id, ['role' => 'member']);
        $viewer->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $service = app(\App\Services\AuthPermissionService::class);

        $this->assertTrue($service->can($admin, 'team.manage', $tenant, $brand));
        $this->assertTrue($service->can($admin, 'asset.view', $tenant, $brand));

        $this->assertFalse($service->can($viewer, 'team.manage', $tenant, $brand));
        $this->assertTrue($service->can($viewer, 'asset.view', $tenant, $brand));
    }
}
