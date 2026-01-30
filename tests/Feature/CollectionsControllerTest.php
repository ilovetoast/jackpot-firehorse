<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collections controller tests (Collections C4).
 * Minimal backend assertions: page renders; unauthorized user cannot load assets for a collection.
 */
class CollectionsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_collections_index_renders_without_crash(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $user = User::create([
            'email' => 'u@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'U',
            'last_name' => 'U',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/collections');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Collections/Index')
            ->has('collections')
            ->has('assets')
            ->has('selected_collection')
        );
    }

    public function test_unauthorized_user_cannot_load_assets_for_collection(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'C1',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $userNotInBrand = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'O',
        ]);
        $userNotInBrand->tenants()->attach($tenant->id, ['role' => 'member']);
        // Not attached to brand — cannot view collection

        $response = $this->actingAs($userNotInBrand)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/collections?collection=' . $collection->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Collections/Index')
            ->where('selected_collection', null)
            ->where('assets', [])
        );
    }
}
