<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionCreateTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $adminUser;
    protected User $contributorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);

        $this->adminUser = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'A',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->contributorUser = User::create([
            'email' => 'contributor@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'C',
        ]);
        $this->contributorUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributorUser->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);
    }

    public function test_admin_can_create_collection(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', [
                'name' => 'New Collection',
                'description' => 'Optional description',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('collection.name', 'New Collection');
        $response->assertJsonPath('collection.description', 'Optional description');

        $this->assertDatabaseHas('collections', [
            'brand_id' => $this->brand->id,
            'name' => 'New Collection',
            'created_by' => $this->adminUser->id,
        ]);
    }

    public function test_contributor_cannot_create_collection(): void
    {
        $response = $this->actingAs($this->contributorUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', [
                'name' => 'Forbidden',
                'description' => null,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('collections', [
            'brand_id' => $this->brand->id,
            'name' => 'Forbidden',
        ]);
    }

    public function test_create_enforces_unique_name_per_brand(): void
    {
        Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Existing',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', [
                'name' => 'Existing',
                'description' => null,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }
}
