<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\CollectionUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orphan detection and delete-from-company cleanup.
 *
 * - Orphan: user not in tenant but has brand_user and/or collection_user for this tenant
 * - Removing from brand revokes collection access for that brand's collections
 * - Delete from company removes all relations (tenant_user, brand_user, collection_user)
 */
class OrphanAndDeleteFromCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $adminUser;
    protected User $orphanUser;
    protected Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->orphanUser = User::create([
            'email' => 'orphan@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Orphan',
            'last_name' => 'User',
        ]);

        $this->collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Test Collection',
            'slug' => 'test-collection',
            'visibility' => 'private',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
    }

    /** @test */
    public function orphan_with_brand_assignment_appears_on_team_page(): void
    {
        // User was in tenant and had brand assignment; then removed from tenant only -> orphan (brand_user remains)
        $this->orphanUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->orphanUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);
        $this->orphanUser->tenants()->detach($this->tenant->id);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get(route('companies.team'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Companies/Team')
            ->has('members')
            ->where('members', fn ($members) => collect($members)->contains(fn ($m) =>
                (int) $m['id'] === $this->orphanUser->id && $m['is_orphaned'] === true
            ))
        );
    }

    /** @test */
    public function remove_from_brand_revokes_collection_access_for_that_brand(): void
    {
        $this->orphanUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->orphanUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);
        CollectionUser::create([
            'user_id' => $this->orphanUser->id,
            'collection_id' => $this->collection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        $this->assertDatabaseHas('collection_user', [
            'user_id' => $this->orphanUser->id,
            'collection_id' => $this->collection->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->delete(route('brands.users.remove', ['brand' => $this->brand->id, 'user' => $this->orphanUser->id]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('collection_user', [
            'user_id' => $this->orphanUser->id,
            'collection_id' => $this->collection->id,
        ]);
    }

    /** @test */
    public function delete_from_company_removes_tenant_brand_and_collection_relations(): void
    {
        $this->orphanUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->orphanUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);
        CollectionUser::create([
            'user_id' => $this->orphanUser->id,
            'collection_id' => $this->collection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->delete("/app/companies/{$this->tenant->id}/team/{$this->orphanUser->id}/delete-from-company");

        $response->assertRedirect(route('companies.team'));
        $response->assertSessionHas('success');

        $this->assertFalse(
            $this->orphanUser->tenants()->where('tenants.id', $this->tenant->id)->exists(),
            'User must be detached from tenant'
        );
        $this->assertDatabaseMissing('brand_user', [
            'user_id' => $this->orphanUser->id,
            'brand_id' => $this->brand->id,
        ]);
        $this->assertDatabaseMissing('collection_user', [
            'user_id' => $this->orphanUser->id,
            'collection_id' => $this->collection->id,
        ]);
    }

    /** @test */
    public function delete_from_company_cleans_orphan_with_only_brand_assignment(): void
    {
        // Orphan: not in tenant, has brand_user
        $this->orphanUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);
        $this->assertFalse($this->orphanUser->tenants()->where('tenants.id', $this->tenant->id)->exists());

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->delete("/app/companies/{$this->tenant->id}/team/{$this->orphanUser->id}/delete-from-company");

        $response->assertRedirect(route('companies.team'));
        $this->assertDatabaseMissing('brand_user', [
            'user_id' => $this->orphanUser->id,
            'brand_id' => $this->brand->id,
        ]);
    }

    /** @test */
    public function delete_from_company_cleans_orphan_with_only_collection_access(): void
    {
        // Collection-only orphan: not in tenant, no brand_user, has collection_user
        CollectionUser::create([
            'user_id' => $this->orphanUser->id,
            'collection_id' => $this->collection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get(route('companies.team'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('members', fn ($members) => collect($members)->contains(fn ($m) =>
                (int) $m['id'] === $this->orphanUser->id && $m['is_orphaned'] === true && empty($m['brand_assignments'])
            ))
        );

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->delete("/app/companies/{$this->tenant->id}/team/{$this->orphanUser->id}/delete-from-company");

        $response->assertRedirect(route('companies.team'));
        $this->assertDatabaseMissing('collection_user', [
            'user_id' => $this->orphanUser->id,
            'collection_id' => $this->collection->id,
        ]);
    }
}
