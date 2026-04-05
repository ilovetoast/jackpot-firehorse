<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\CollectionUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase C12.0: Private collection invitations — collection-only access (no brand membership).
 * - Inviting creates collection_user grant, NOT brand membership
 * - Accept does NOT add brand membership
 * - Collection-only user can view only the invited collection; blocked from brand pages
 * - Revoking access removes visibility
 * - Normal brand members unaffected
 */
class CollectionAccessC12Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $adminUser;

    protected User $inviteeUser;

    protected Collection $privateCollection;

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

        $this->inviteeUser = User::create([
            'email' => 'invitee@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Invitee',
            'last_name' => 'User',
        ]);
        $this->inviteeUser->tenants()->attach($this->tenant->id, ['role' => 'viewer']);

        $this->privateCollection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Private Collection',
            'slug' => 'private-collection',
            'visibility' => 'private',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);
    }

    public function test_inviting_to_private_collection_creates_collection_access_grant_not_brand_membership(): void
    {
        $this->assertFalse(
            $this->inviteeUser->brands()->where('brands.id', $this->brand->id)->whereNull('brand_user.removed_at')->exists(),
            'Invitee must not have brand membership before invite'
        );

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->post('/app/collections/'.$this->privateCollection->id.'/access-invite', [
                'email' => $this->inviteeUser->email,
                '_token' => csrf_token(),
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('collection_invitations', [
            'collection_id' => $this->privateCollection->id,
            'email' => $this->inviteeUser->email,
        ]);
        $this->assertDatabaseMissing('brand_user', [
            'user_id' => $this->inviteeUser->id,
            'brand_id' => $this->brand->id,
        ]);
    }

    public function test_gateway_login_redirects_to_collection_invite_when_url_intended_is_set(): void
    {
        $invitation = CollectionInvitation::create([
            'collection_id' => $this->privateCollection->id,
            'email' => $this->inviteeUser->email,
            'token' => 'login-handoff-token',
            'invited_by_user_id' => $this->adminUser->id,
            'sent_at' => now(),
        ]);
        $acceptUrl = route('collection-invite.accept', ['token' => $invitation->token]);

        $response = $this->withSession(['url.intended' => $acceptUrl])
            ->post(route('gateway.login'), [
                'email' => $this->inviteeUser->email,
                'password' => 'password',
            ]);

        $response->assertRedirect($acceptUrl);
        $response->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($this->inviteeUser);
    }

    public function test_gateway_login_sends_collection_only_user_to_collection_landing_without_intended_url(): void
    {
        CollectionUser::create([
            'user_id' => $this->inviteeUser->id,
            'collection_id' => $this->privateCollection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        $response = $this->post(route('gateway.login'), [
            'email' => $this->inviteeUser->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('collection-invite.landing', ['collection' => $this->privateCollection->id]));
        $this->assertAuthenticatedAs($this->inviteeUser);
    }

    public function test_gateway_index_redirects_collection_only_user_when_brand_picker_would_be_empty(): void
    {
        CollectionUser::create([
            'user_id' => $this->inviteeUser->id,
            'collection_id' => $this->privateCollection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($this->inviteeUser)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('gateway'));

        $response->assertRedirect(route('collection-invite.landing', ['collection' => $this->privateCollection->id]));
    }

    public function test_accepting_invite_creates_collection_user_grant_and_does_not_create_brand_membership(): void
    {
        $invitation = CollectionInvitation::create([
            'collection_id' => $this->privateCollection->id,
            'email' => $this->inviteeUser->email,
            'token' => 'test-token-123',
            'invited_by_user_id' => $this->adminUser->id,
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($this->inviteeUser)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post(route('collection-invite.accept.submit', ['token' => $invitation->token]));

        $response->assertRedirect();
        $this->assertDatabaseHas('collection_user', [
            'user_id' => $this->inviteeUser->id,
            'collection_id' => $this->privateCollection->id,
        ]);
        $this->assertNotNull(CollectionUser::where('user_id', $this->inviteeUser->id)->where('collection_id', $this->privateCollection->id)->first()?->accepted_at);

        $inviteeHasBrand = $this->inviteeUser->brands()
            ->where('brands.id', $this->brand->id)
            ->wherePivotNull('removed_at')
            ->exists();
        $this->assertFalse($inviteeHasBrand, 'Accepting collection invite must NOT create brand membership');
    }

    public function test_collection_only_user_can_view_invited_collection(): void
    {
        CollectionUser::create([
            'user_id' => $this->inviteeUser->id,
            'collection_id' => $this->privateCollection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($this->inviteeUser)
            ->withSession([
                'tenant_id' => $this->tenant->id,
                'collection_id' => $this->privateCollection->id,
            ])
            ->get(route('collection-invite.landing', ['collection' => $this->privateCollection->id]));

        $response->assertOk();
    }

    /** @test */
    public function collection_only_user_sees_collection_only_shared_props_on_view_page(): void
    {
        CollectionUser::create([
            'user_id' => $this->inviteeUser->id,
            'collection_id' => $this->privateCollection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        // Ensure invitee has tenant but NO brand (collection-only)
        $this->inviteeUser->tenants()->syncWithoutDetaching([$this->tenant->id => ['role' => 'viewer']]);
        $this->assertFalse(
            $this->inviteeUser->activeBrandMembership($this->brand) !== null,
            'Invitee must not have brand membership for this test'
        );

        $response = $this->actingAs($this->inviteeUser)
            ->withSession([
                'tenant_id' => $this->tenant->id,
                'collection_id' => $this->privateCollection->id,
            ])
            ->get(route('collection-invite.view', ['collection' => $this->privateCollection->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('collection_only', true)
            ->has('collection_only_collection')
            ->where('collection_only_collection.id', $this->privateCollection->id)
        );
    }

    public function test_revoking_access_removes_grant(): void
    {
        $grant = CollectionUser::create([
            'user_id' => $this->inviteeUser->id,
            'collection_id' => $this->privateCollection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->delete(route('collections.grants.revoke', ['collection' => $this->privateCollection->id, 'collection_user' => $grant->id]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('collection_user', [
            'id' => $grant->id,
        ]);
    }

    public function test_access_invite_json_succeeds_for_restricted_collection(): void
    {
        Mail::fake();

        $restricted = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted Collection',
            'slug' => 'restricted-collection',
            'visibility' => 'restricted',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->postJson('/app/collections/'.$restricted->id.'/access-invite', [
                'email' => 'restricted-invite@example.com',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('collection_invitations', [
            'collection_id' => $restricted->id,
            'email' => 'restricted-invite@example.com',
        ]);
    }

    public function test_access_invite_rejected_for_brand_visibility_collection(): void
    {
        $brandOnly = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Brand Wide Collection',
            'slug' => 'brand-wide-collection',
            'visibility' => 'brand',
            'is_public' => false,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->postJson('/app/collections/'.$brandOnly->id.'/access-invite', [
                'email' => 'someone@example.com',
            ]);

        $response->assertStatus(403);
    }

    public function test_normal_brand_member_unchanged_by_collection_grants(): void
    {
        $brandMember = User::create([
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Member',
            'last_name' => 'User',
        ]);
        $brandMember->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $brandMember->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);

        CollectionUser::create([
            'user_id' => $brandMember->id,
            'collection_id' => $this->privateCollection->id,
            'invited_by_user_id' => $this->adminUser->id,
            'accepted_at' => now(),
        ]);

        $this->assertTrue(
            $brandMember->activeBrandMembership($this->brand) !== null,
            'Brand member must still have brand membership'
        );
        $this->assertTrue(
            $brandMember->collectionAccessGrants()->where('collection_id', $this->privateCollection->id)->exists(),
            'Brand member can also have collection grant'
        );
    }
}
