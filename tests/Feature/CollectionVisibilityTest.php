<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\CollectionMember;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\CollectionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collection visibility + membership tests (Collections C6).
 *
 * Ensures CollectionPolicy::view respects visibility (brand / restricted / private)
 * and collection_members. Unauthorized users never see assets via collection.
 */
class CollectionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $adminUser;
    protected User $viewerUser;
    protected CollectionPolicy $policy;

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
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->viewerUser = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Viewer',
            'last_name' => 'User',
        ]);
        $this->viewerUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->viewerUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->policy = new CollectionPolicy();
    }

    public function test_brand_admin_sees_brand_visibility_collection(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Brand Visible',
            'visibility' => 'brand',
            'is_public' => false,
        ]);
        $collection->setRelation('brand', $this->brand);

        $this->assertTrue($this->policy->view($this->adminUser, $collection));
    }

    public function test_brand_viewer_sees_brand_visibility_collection(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Brand Visible',
            'visibility' => 'brand',
            'is_public' => false,
        ]);
        $collection->setRelation('brand', $this->brand);

        $this->assertTrue($this->policy->view($this->viewerUser, $collection));
    }

    public function test_non_member_cannot_see_restricted_collection(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
        $collection->setRelation('brand', $this->brand);
        $collection->setRelation('members', collect([]));

        // Viewer is in brand but not creator and not in collection_members
        $this->assertFalse($this->policy->view($this->viewerUser, $collection));
    }

    public function test_member_can_see_restricted_collection(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
        $collection->setRelation('brand', $this->brand);

        CollectionMember::create([
            'collection_id' => $collection->id,
            'user_id' => $this->viewerUser->id,
            'invited_at' => now(),
            'accepted_at' => now(), // C7: only accepted members can view
        ]);
        $collection->load('members');

        $this->assertTrue($this->policy->view($this->viewerUser, $collection));
    }

    public function test_creator_can_see_private_collection(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Private',
            'visibility' => 'private',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
        $collection->setRelation('brand', $this->brand);
        $collection->setRelation('members', collect([]));

        $this->assertTrue($this->policy->view($this->adminUser, $collection));
    }

    public function test_non_member_cannot_see_private_collection(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Private',
            'visibility' => 'private',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
        $collection->setRelation('brand', $this->brand);
        $collection->setRelation('members', collect([]));

        $this->assertFalse($this->policy->view($this->viewerUser, $collection));
    }

    public function test_unauthorized_user_never_sees_assets_via_collection(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
        // Viewer is in brand but not a member of this restricted collection

        $response = $this->actingAs($this->viewerUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/collections?collection=' . $collection->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Collections/Index')
            ->where('selected_collection', null)
            ->where('assets', [])
        );
    }
}
