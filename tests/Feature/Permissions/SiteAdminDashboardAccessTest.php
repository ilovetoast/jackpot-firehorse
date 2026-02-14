<?php

namespace Tests\Feature\Permissions;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteAdminDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    #[Test]
    public function site_admin_can_access_admin_dashboard(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $user = User::create([
            'email' => 'siteadmin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Site',
            'last_name' => 'Admin',
        ]);

        $user->assignRole('site_admin');
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/app/admin');

        $response->assertStatus(200);
    }

    #[Test]
    public function site_admin_sees_admin_dashboard_in_nav_props(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $user = User::create([
            'email' => 'siteadmin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Site',
            'last_name' => 'Admin',
        ]);

        $user->assignRole('site_admin');
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        // Admin page shares auth props (including site_roles) used by AppNav for Admin Dashboard link
        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/app/admin');

        $response->assertStatus(200);
        $props = $response->inertiaPage()['props'] ?? [];
        $siteRoles = $props['auth']['user']['site_roles'] ?? [];
        $this->assertContains('site_admin', $siteRoles, 'Site admin should have site_admin in site_roles for Admin Dashboard nav visibility');
    }
}
