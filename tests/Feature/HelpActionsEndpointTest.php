<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpActionsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_json_shape_for_authenticated_tenant_session(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-help']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-help']);
        $user = User::create([
            'email' => 'help-endpoint@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'E',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions');

        $response->assertOk()
            ->assertJsonStructure(['query', 'results', 'common']);
        $this->assertNull($response->json('query'));
    }

    public function test_non_string_q_does_not_error(): void
    {
        $tenant = Tenant::create(['name' => 'T2', 'slug' => 't-help2']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B2', 'slug' => 'b-help2']);
        $user = User::create([
            'email' => 'help-endpoint2@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'E',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q[]=x')
            ->assertOk()
            ->assertJsonPath('query', null);
    }

    public function test_guest_cannot_access_help_actions(): void
    {
        $response = $this->getJson('/app/help/actions');
        $this->assertContains($response->status(), [302, 401], 'Unauthenticated help request should redirect or reject');
    }
}
