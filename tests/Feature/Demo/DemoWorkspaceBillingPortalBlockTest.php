<?php

namespace Tests\Feature\Demo;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoWorkspaceBillingPortalBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_portal_blocked_for_demo_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Billing',
            'slug' => 'demo-billing',
            'is_demo' => true,
        ]);
        $brand = $tenant->defaultBrand;
        $this->assertNotNull($brand);

        $owner = User::create([
            'email' => 'owner-billing@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($owner)
            ->from(route('billing'))
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('billing.portal'))
            ->assertSessionHasErrors(['billing' => DemoTenantService::DISABLED_MESSAGE]);
    }
}
