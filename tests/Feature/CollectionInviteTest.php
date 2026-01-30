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
 * Collection invites (C7). Internal users only.
 * Admin can invite; viewer cannot. Invited user cannot see until accept; declined = no access.
 */
class CollectionInviteTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $adminUser;
    protected User $viewerUser;
    protected User $otherUserInTenant;
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

        $this->otherUserInTenant = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'User',
        ]);
        $this->otherUserInTenant->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->otherUserInTenant->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->policy = new CollectionPolicy();
    }

    public function test_admin_can_invite_existing_user(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->postJson('/app/collections/' . $collection->id . '/invite', [
                'user_id' => $this->otherUserInTenant->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('member.user_id', $this->otherUserInTenant->id);
        $response->assertJsonPath('member.invited_at', fn ($v) => $v !== null);
        $response->assertJsonPath('member.accepted_at', null);

        $this->assertDatabaseHas('collection_members', [
            'collection_id' => $collection->id,
            'user_id' => $this->otherUserInTenant->id,
        ]);
        $member = CollectionMember::where('collection_id', $collection->id)->where('user_id', $this->otherUserInTenant->id)->first();
        $this->assertNotNull($member->invited_at);
        $this->assertNull($member->accepted_at);
    }

    public function test_viewer_cannot_invite(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->viewerUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->postJson('/app/collections/' . $collection->id . '/invite', [
                'user_id' => $this->otherUserInTenant->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('collection_members', [
            'collection_id' => $collection->id,
            'user_id' => $this->otherUserInTenant->id,
        ]);
    }

    public function test_invited_user_cannot_see_collection_before_accept(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
        CollectionMember::create([
            'collection_id' => $collection->id,
            'user_id' => $this->otherUserInTenant->id,
            'invited_at' => now(),
            'accepted_at' => null, // not yet accepted
        ]);
        $collection->load(['brand', 'members']);

        $this->assertFalse($this->policy->view($this->otherUserInTenant, $collection));

        $response = $this->actingAs($this->otherUserInTenant)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->get('/app/collections?collection=' . $collection->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('selected_collection', null)
            ->where('assets', [])
        );
    }

    public function test_accepted_user_can_see_restricted_collection(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
        CollectionMember::create([
            'collection_id' => $collection->id,
            'user_id' => $this->otherUserInTenant->id,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);
        $collection->load(['brand', 'members']);

        $this->assertTrue($this->policy->view($this->otherUserInTenant, $collection));

        $response = $this->actingAs($this->otherUserInTenant)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->get('/app/collections?collection=' . $collection->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('selected_collection.id', $collection->id)
            ->etc()
        );
    }

    public function test_declined_invite_does_not_grant_access(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
        CollectionMember::create([
            'collection_id' => $collection->id,
            'user_id' => $this->otherUserInTenant->id,
            'invited_at' => now(),
            'accepted_at' => null,
        ]);

        $response = $this->actingAs($this->otherUserInTenant)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->postJson('/app/collections/' . $collection->id . '/decline');

        $response->assertStatus(200);
        $response->assertJson(['declined' => true]);
        $this->assertDatabaseMissing('collection_members', [
            'collection_id' => $collection->id,
            'user_id' => $this->otherUserInTenant->id,
        ]);

        $collection->load(['brand', 'members']);
        $this->assertFalse($this->policy->view($this->otherUserInTenant, $collection));
    }

    public function test_user_not_in_brand_still_cannot_see_assets_via_collection(): void
    {
        $userNotInBrand = User::create([
            'email' => 'outsider@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Out',
            'last_name' => 'Side',
        ]);
        $userNotInBrand->tenants()->attach($this->tenant->id, ['role' => 'member']);
        // Not attached to brand

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Brand Visible',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $response = $this->actingAs($userNotInBrand)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->get('/app/collections?collection=' . $collection->id);

        // User not in brand: either 200 with no collection/assets (policy denies view), or 302 (middleware redirect)
        if ($response->status() === 200) {
            $response->assertInertia(fn ($page) => $page
                ->where('selected_collection', null)
                ->where('assets', [])
            );
        } else {
            $response->assertStatus(302);
        }
    }
}
