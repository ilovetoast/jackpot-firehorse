<?php

namespace Tests\Feature\Permissions;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end test: Team management requires team.manage permission.
 * Verifies routing, middleware, controller, and AuthPermissionService all behave correctly.
 */
class TeamManagementPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    #[Test]
    public function team_management_returns_403_without_permission(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $brand = $tenant->defaultBrand;

        // Add owner first — Tenant::owner() auto-promotes first user to owner if none exists
        $owner = User::create([
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Owner',
            'last_name' => 'User',
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $member = User::create([
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Member',
            'last_name' => 'User',
        ]);
        $member->tenants()->attach($tenant->id, ['role' => 'member']);
        $member->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $response = $this->actingAs($member)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('companies.team'));

        $response->assertStatus(403);
    }

    #[Test]
    public function team_management_returns_200_with_tenant_admin(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $brand = $tenant->defaultBrand;

        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/companies/team');

        $response->assertStatus(200);
    }

    #[Test]
    public function team_management_returns_200_with_tenant_owner(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $brand = $tenant->defaultBrand;

        $user = User::create([
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Owner',
            'last_name' => 'User',
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/companies/team');

        $response->assertStatus(200);
    }
}
