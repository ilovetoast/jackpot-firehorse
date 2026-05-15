<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GatewayAllWorkspacesBrandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('plans.free.limits.max_brands', 50);
    }

    public function test_plain_gateway_lists_brands_across_all_companies_with_session_tenant_first(): void
    {
        $tenantA = Tenant::create(['name' => 'Alpha Co', 'slug' => 'alpha-co']);
        $tenantB = Tenant::create(['name' => 'Beta Co', 'slug' => 'beta-co']);

        $brandA1 = Brand::create(['tenant_id' => $tenantA->id, 'name' => 'A1', 'slug' => 'a1']);
        $brandA2 = Brand::create(['tenant_id' => $tenantA->id, 'name' => 'A2', 'slug' => 'a2']);
        $brandB1 = Brand::create(['tenant_id' => $tenantB->id, 'name' => 'B1', 'slug' => 'b1']);
        $brandB2 = Brand::create(['tenant_id' => $tenantB->id, 'name' => 'B2', 'slug' => 'b2']);

        $user = User::create([
            'email' => 'multi@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'U',
        ]);
        $user->tenants()->attach($tenantA->id, ['role' => 'member']);
        $user->tenants()->attach($tenantB->id, ['role' => 'member']);
        foreach ([$brandA1, $brandA2, $brandB1, $brandB2] as $b) {
            $user->brands()->attach($b->id, ['role' => 'viewer', 'removed_at' => null]);
        }

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenantB->id, 'brand_id' => $brandB1->id])
            ->get(route('gateway'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gateway/Index')
                ->where('mode', 'brand_select')
                ->where('context.brand_picker_scope', 'all_workspaces')
                ->has('context.available_brands', 4)
                ->where('context.available_brands.0.tenant_id', $tenantB->id)
                ->where('context.available_brands.0.tenant_is_agency', false)
                ->where('context.available_brands.2.tenant_id', $tenantA->id)
                ->where('context.available_brands.2.tenant_is_agency', false));
    }

    public function test_all_workspaces_brand_rows_include_tenant_is_agency_flag(): void
    {
        $agency = Tenant::create(['name' => 'Agency Parent', 'slug' => 'agency-parent', 'is_agency' => true]);
        $client = Tenant::create(['name' => 'Client Co', 'slug' => 'client-co', 'is_agency' => false]);

        $brandAg = Brand::create(['tenant_id' => $agency->id, 'name' => 'Agency Brand', 'slug' => 'agency-brand']);
        $brandCl = Brand::create(['tenant_id' => $client->id, 'name' => 'Client Brand', 'slug' => 'client-brand']);

        $user = User::create([
            'email' => 'agency-picker@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'P',
        ]);
        $user->tenants()->attach($agency->id, ['role' => 'member']);
        $user->tenants()->attach($client->id, ['role' => 'member']);
        $user->brands()->attach($brandAg->id, ['role' => 'viewer', 'removed_at' => null]);
        $user->brands()->attach($brandCl->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->actingAs($user)
            ->get(route('gateway'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('context.brand_picker_scope', 'all_workspaces')
                ->has('context.available_brands', 2)
                ->where('context.available_brands.0.tenant_is_agency', true)
                ->where('context.available_brands.1.tenant_is_agency', false));
    }

    public function test_company_query_scopes_brand_list_to_that_workspace(): void
    {
        $tenantA = Tenant::create(['name' => 'Alpha Co 2', 'slug' => 'alpha-co-2']);
        $tenantB = Tenant::create(['name' => 'Beta Co 2', 'slug' => 'beta-co-2']);

        $brandA1 = Brand::create(['tenant_id' => $tenantA->id, 'name' => 'A1b', 'slug' => 'a1b']);
        $brandA2 = Brand::create(['tenant_id' => $tenantA->id, 'name' => 'A2b', 'slug' => 'a2b']);
        $brandB1 = Brand::create(['tenant_id' => $tenantB->id, 'name' => 'B1b', 'slug' => 'b1b']);

        $user = User::create([
            'email' => 'scoped@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'S',
            'last_name' => 'C',
        ]);
        $user->tenants()->attach($tenantA->id, ['role' => 'member']);
        $user->tenants()->attach($tenantB->id, ['role' => 'member']);
        $user->brands()->attach($brandA1->id, ['role' => 'viewer', 'removed_at' => null]);
        $user->brands()->attach($brandA2->id, ['role' => 'viewer', 'removed_at' => null]);
        $user->brands()->attach($brandB1->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenantB->id])
            ->get(route('gateway', ['company' => $tenantA->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('context.brand_picker_scope', 'tenant')
                ->has('context.available_brands', 2));
    }

    public function test_select_brand_sets_session_tenant_from_chosen_brand_without_prior_session_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Alpha Co 3', 'slug' => 'alpha-co-3']);
        $tenantB = Tenant::create(['name' => 'Beta Co 3', 'slug' => 'beta-co-3']);

        $brandA = Brand::create(['tenant_id' => $tenantA->id, 'name' => 'OnlyA', 'slug' => 'only-a']);
        $brandB = Brand::create(['tenant_id' => $tenantB->id, 'name' => 'PickB', 'slug' => 'pick-b']);

        $user = User::create([
            'email' => 'pick@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'P',
            'last_name' => 'K',
        ]);
        $user->tenants()->attach($tenantA->id, ['role' => 'member']);
        $user->tenants()->attach($tenantB->id, ['role' => 'member']);
        $user->brands()->attach($brandA->id, ['role' => 'viewer', 'removed_at' => null]);
        $user->brands()->attach($brandB->id, ['role' => 'viewer', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->from(route('gateway'))
            ->post(route('gateway.select-brand'), ['brand_id' => $brandB->id]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/app/overview');
        $response->assertSessionHas('tenant_id', $tenantB->id);
        $response->assertSessionHas('brand_id', $brandB->id);
    }
}
