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
    public function remove_from_brand_with_remove_from_company_detaches_tenant_when_only_brand(): void
    {
        $this->orphanUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->orphanUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $uri = route('brands.users.remove', ['brand' => $this->brand->id, 'user' => $this->orphanUser->id]);
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->call(
                'DELETE',
                $uri,
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['remove_from_company' => true])
            );

        $response->assertRedirect();
        $this->assertFalse(
            $this->orphanUser->fresh()->tenants()->where('tenants.id', $this->tenant->id)->exists(),
            'User should be removed from company when remove_from_company is true and this was their only brand'
        );
        $this->assertNotNull(
            \Illuminate\Support\Facades\DB::table('brand_user')
                ->where('user_id', $this->orphanUser->id)
                ->where('brand_id', $this->brand->id)
                ->value('removed_at'),
            'Brand membership should be soft-removed'
        );
    }

    /** @test */
    public function remove_from_brand_rejects_remove_from_company_when_user_has_other_brands(): void
    {
        $brandB = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand B',
            'slug' => 'brand-b',
        ]);
        $this->orphanUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->orphanUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);
        $this->orphanUser->brands()->attach($brandB->id, ['role' => 'viewer', 'removed_at' => null]);

        $uri = route('brands.users.remove', ['brand' => $this->brand->id, 'user' => $this->orphanUser->id]);
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->call(
                'DELETE',
                $uri,
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['remove_from_company' => true])
            );

        $response->assertRedirect();
        $response->assertSessionHasErrors('brand');
        $this->assertTrue(
            $this->orphanUser->fresh()->tenants()->where('tenants.id', $this->tenant->id)->exists(),
            'User should remain on company when remove_from_company is rejected'
        );
        $this->assertNull(
            \Illuminate\Support\Facades\DB::table('brand_user')
                ->where('user_id', $this->orphanUser->id)
                ->where('brand_id', $this->brand->id)
                ->value('removed_at'),
            'User should still be an active member of this brand when request is rejected'
        );
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
