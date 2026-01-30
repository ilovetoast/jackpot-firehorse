<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C11.1: Minimal collection edit surface.
 * Verifies: authorized user can update name/description; unauthorized cannot; public toggle respects feature gate.
 */
class CollectionEditC111Test extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_update_collection_name_and_description(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'dmin',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Original',
            'description' => 'Old desc',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->putJson("/app/collections/{$collection->id}", [
                'name' => 'Updated Name',
                'description' => 'New description',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('collection.name', 'Updated Name');
        $response->assertJsonPath('collection.description', 'New description');

        $collection->refresh();
        $this->assertSame('Updated Name', $collection->name);
        $this->assertSame('New description', $collection->description);
    }

    public function test_unauthorized_user_cannot_update_collection(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $viewer = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'V',
            'last_name' => 'iewer',
        ]);
        $viewer->tenants()->attach($tenant->id, ['role' => 'member']);
        $viewer->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'C1',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $response = $this->actingAs($viewer)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->putJson("/app/collections/{$collection->id}", [
                'name' => 'Hacked',
                'description' => 'Nope',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(403);

        $collection->refresh();
        $this->assertSame('C1', $collection->name);
    }

    public function test_is_public_cannot_be_enabled_when_feature_disabled(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'dmin',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'C1',
            'visibility' => 'brand',
            'is_public' => false,
        ]);
        $this->assertFalse($collection->is_public);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->putJson("/app/collections/{$collection->id}", [
                'name' => 'C1',
                'is_public' => true,
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('collection.is_public', false);

        $collection->refresh();
        $this->assertFalse($collection->is_public);
    }

    public function test_collections_index_passes_can_update_collection(): void
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

        $admin = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'dmin',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'member']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $viewer = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'V',
            'last_name' => 'iewer',
        ]);
        $viewer->tenants()->attach($tenant->id, ['role' => 'member']);
        $viewer->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $adminResponse = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/collections?collection=' . $collection->id);

        $adminResponse->assertStatus(200);
        $adminResponse->assertInertia(fn ($page) => $page
            ->where('can_update_collection', true)
            ->where('selected_collection.id', $collection->id)
        );

        $viewerResponse = $this->actingAs($viewer)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/collections?collection=' . $collection->id);

        $viewerResponse->assertStatus(200);
        $viewerResponse->assertInertia(fn ($page) => $page
            ->where('can_update_collection', false)
        );
    }
}
