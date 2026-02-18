<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthPermissionService;
use App\Support\Roles\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permission Unification Tests
 *
 * - assets.delete in PermissionMap but NOT in Spatie DB → backend allows delete, effective_permissions includes it
 * - Custom Spatie permission not in PermissionMap → effective_permissions still includes it
 */
class PermissionUnificationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);
    }

    public function test_assets_delete_in_permission_map_backend_allows_delete(): void
    {
        $this->assertTrue(in_array('assets.delete', PermissionMap::tenantPermissions()['admin'] ?? []));

        $service = app(AuthPermissionService::class);
        $canDelete = $service->can($this->user, 'assets.delete', $this->tenant, $this->brand);

        $this->assertTrue($canDelete, 'Admin should have assets.delete via PermissionMap even if not in Spatie DB');
    }

    public function test_effective_permissions_includes_assets_delete(): void
    {
        $service = app(AuthPermissionService::class);
        $perms = $service->effectivePermissions($this->user, $this->tenant, $this->brand);

        $this->assertContains('assets.delete', $perms, 'effective_permissions should include assets.delete for admin');
    }

    public function test_effective_permissions_includes_custom_spatie_permission(): void
    {
        $customPerm = 'custom.test.permission';
        $role = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web']
        );
        $permModel = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => $customPerm, 'guard_name' => 'web']
        );
        $role->givePermissionTo($permModel);
        $this->user->assignRole('admin');

        $service = app(AuthPermissionService::class);
        $perms = $service->effectivePermissions($this->user, $this->tenant, $this->brand);

        $this->assertContains($customPerm, $perms, 'effective_permissions should include custom Spatie permission');
    }

    public function test_permission_map_all_permissions_returns_unique_list(): void
    {
        $all = PermissionMap::allPermissions();
        $this->assertNotEmpty($all);
        $this->assertIsArray($all);
        $this->assertSame($all, array_values(array_unique($all)));
        $this->assertContains('assets.delete', $all);
    }
}
