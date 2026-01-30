<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C11: Visual signals and micro polish for collections.
 * Verifies: public/is_public and assets_count signals in index; empty public page safe; no C10 regression.
 */
class CollectionSignalsC11Test extends TestCase
{
    use RefreshDatabase;

    public function test_collections_index_includes_public_and_assets_count_signals(): void
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

        $publicCollection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Public C',
            'slug' => 'public-c',
            'visibility' => 'brand',
            'is_public' => true,
        ]);
        $privateCollection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Private C',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/collections');

        $response->assertStatus(200);
        // Order by name: "Private C" then "Public C"
        $response->assertInertia(fn ($page) => $page
            ->component('Collections/Index')
            ->has('public_collections_enabled')
            ->has('collections', 2)
            ->where('collections.0.id', $privateCollection->id)
            ->where('collections.0.name', 'Private C')
            ->where('collections.0.is_public', false)
            ->where('collections.0.assets_count', 0)
            ->where('collections.1.id', $publicCollection->id)
            ->where('collections.1.name', 'Public C')
            ->where('collections.1.is_public', true)
            ->where('collections.1.assets_count', 0)
        );
    }

    public function test_empty_public_collection_renders_safely(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $tenant->update(['manual_plan_override' => 'enterprise']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Empty Public',
            'slug' => 'empty-public',
            'visibility' => 'brand',
            'is_public' => true,
        ]);

        $response = $this->get('/b/' . $brand->slug . '/collections/empty-public');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Collection')
            ->has('collection')
            ->has('assets')
            ->where('assets', [])
            ->where('collection.name', 'Empty Public')
        );
    }

    public function test_collections_index_selected_collection_has_expected_shape(): void
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

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Selected',
            'slug' => 'selected',
            'visibility' => 'brand',
            'is_public' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/collections?collection=' . $collection->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Collections/Index')
            ->has('selected_collection')
            ->where('selected_collection.id', $collection->id)
            ->where('selected_collection.name', 'Selected')
            ->where('selected_collection.is_public', true)
            ->has('selected_collection.slug')
        );
    }
}
