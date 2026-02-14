<?php

namespace Tests\Feature\Presence;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Presence API: Redis-based tenant/brand online indicator.
 * Visible only to tenant admin/owner and brand manager.
 */
class PresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        Redis::fake();
    }

    #[Test]
    public function admin_sees_themselves_online(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);
        $brand = $tenant->defaultBrand;

        $admin = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('presence.heartbeat'), ['page' => '/app/assets'])
            ->assertNoContent();

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('presence.online'));

        $response->assertOk();
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertEquals($admin->id, $data[0]['id']);
        $this->assertEquals($admin->name, $data[0]['name']);
    }

    #[Test]
    public function viewer_cannot_access_online(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);
        $brand = $tenant->defaultBrand;

        $viewer = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Viewer',
            'last_name' => 'User',
        ]);
        $viewer->tenants()->attach($tenant->id, ['role' => 'member']);
        $viewer->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->actingAs($viewer)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('presence.heartbeat'))
            ->assertNoContent();

        $this->actingAs($viewer)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('presence.online'))
            ->assertForbidden();
    }

    #[Test]
    public function cross_tenant_isolation(): void
    {
        $tenantA = Tenant::create(['name' => 'Company A', 'slug' => 'company-a']);
        $brandA = $tenantA->defaultBrand;

        $tenantB = Tenant::create(['name' => 'Company B', 'slug' => 'company-b']);
        $brandB = $tenantB->defaultBrand;

        $adminA = User::create([
            'email' => 'admin-a@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'A',
        ]);
        $adminA->tenants()->attach($tenantA->id, ['role' => 'admin']);
        $adminA->brands()->attach($brandA->id, ['role' => 'admin', 'removed_at' => null]);

        $adminB = User::create([
            'email' => 'admin-b@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'B',
        ]);
        $adminB->tenants()->attach($tenantB->id, ['role' => 'admin']);
        $adminB->brands()->attach($brandB->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($adminA)
            ->withSession(['tenant_id' => $tenantA->id, 'brand_id' => $brandA->id])
            ->post(route('presence.heartbeat'))
            ->assertNoContent();

        $this->actingAs($adminB)
            ->withSession(['tenant_id' => $tenantB->id, 'brand_id' => $brandB->id])
            ->post(route('presence.heartbeat'))
            ->assertNoContent();

        $responseA = $this->actingAs($adminA)
            ->withSession(['tenant_id' => $tenantA->id, 'brand_id' => $brandA->id])
            ->get(route('presence.online'));

        $responseA->assertOk();
        $dataA = $responseA->json();
        $this->assertCount(1, $dataA);
        $this->assertEquals($adminA->id, $dataA[0]['id']);

        $responseB = $this->actingAs($adminB)
            ->withSession(['tenant_id' => $tenantB->id, 'brand_id' => $brandB->id])
            ->get(route('presence.online'));

        $responseB->assertOk();
        $dataB = $responseB->json();
        $this->assertCount(1, $dataB);
        $this->assertEquals($adminB->id, $dataB[0]['id']);
    }
}
