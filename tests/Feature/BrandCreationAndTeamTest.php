<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brand creation adds creator as admin; team page shows all tenant brands.
 */
class BrandCreationAndTeamTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $adminUser;
    protected User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand One',
            'slug' => 'brand-one',
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->memberUser = User::create([
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Member',
            'last_name' => 'User',
        ]);
        $this->memberUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->memberUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);
    }

    public function test_brand_creator_is_added_as_brand_admin(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->post(route('brands.store'), [
                'name' => 'New Brand',
                'slug' => 'new-brand',
            ]);

        $response->assertRedirect(route('brands.index'));

        $newBrand = Brand::where('tenant_id', $this->tenant->id)->where('slug', 'new-brand')->first();
        $this->assertNotNull($newBrand);

        $pivot = \DB::table('brand_user')
            ->where('user_id', $this->adminUser->id)
            ->where('brand_id', $newBrand->id)
            ->whereNull('removed_at')
            ->first();

        $this->assertNotNull($pivot, 'Creator must be added to brand_user');
        $this->assertSame('admin', $pivot->role, 'Creator must have admin role on created brand');
    }

    public function test_team_page_returns_all_tenant_brands(): void
    {
        $brandTwo = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand Two',
            'slug' => 'brand-two',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get(route('companies.team'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Companies/Team')
            ->has('brands')
            ->where('brands', fn ($brands) => count($brands) === 2)
        );
    }

    public function test_team_page_shows_all_brands_for_member_with_partial_access(): void
    {
        Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand Two',
            'slug' => 'brand-two',
        ]);
        // memberUser only has access to brand One (viewer), NOT brand Two

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get(route('companies.team'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Companies/Team')
            ->has('brands')
            ->where('brands', fn ($brands) => count($brands) === 2)
            ->where('members', fn ($members) => collect($members)->contains(fn ($m) =>
                (int) $m['id'] === $this->memberUser->id && count($m['brand_assignments'] ?? []) === 1
            ))
        );
    }
}
