<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GatewayDetachedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_index_redirects_authenticated_user_with_no_tenants_to_no_companies(): void
    {
        $user = User::create([
            'email' => 'solo@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Solo',
            'last_name' => 'User',
        ]);

        $response = $this->actingAs($user)->get(route('gateway'));

        $response->assertRedirect(route('errors.no-companies'));
    }

    public function test_gateway_index_clears_stale_session_when_user_has_no_tenant_memberships(): void
    {
        $tenant = Tenant::create([
            'name' => 'Former Co',
            'slug' => 'former-co',
        ]);

        $user = User::create([
            'email' => 'removed@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Removed',
            'last_name' => 'Member',
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'tenant_id' => $tenant->id,
                'brand_id' => 999,
                'collection_id' => 888,
            ])
            ->get(route('gateway'));

        $response->assertRedirect(route('errors.no-companies'));
        $response->assertSessionMissing('tenant_id');
        $response->assertSessionMissing('brand_id');
        $response->assertSessionMissing('collection_id');
    }

    public function test_detached_user_can_access_profile_without_tenant_session(): void
    {
        $user = User::create([
            'email' => 'profile-only@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Profile',
            'last_name' => 'Only',
        ]);

        $this->actingAs($user)
            ->get(route('profile.index'))
            ->assertOk();
    }

    public function test_detached_user_can_access_companies_index_without_tenant_session(): void
    {
        $user = User::create([
            'email' => 'companies-only@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Companies',
            'last_name' => 'Only',
        ]);

        $this->actingAs($user)
            ->get(route('companies.index'))
            ->assertOk();
    }

    public function test_no_companies_page_lists_pending_workspace_invites_by_email(): void
    {
        $tenant = Tenant::create([
            'name' => 'St Croix',
            'slug' => 'st-croix',
        ]);

        $inviter = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);

        $invitee = User::create([
            'email' => 'Pending@Example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Pending',
            'last_name' => 'Invitee',
        ]);

        TenantInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => 'pending@example.com',
            'role' => 'member',
            'token' => Str::random(64),
            'invited_by' => $inviter->id,
            'brand_assignments' => null,
            'sent_at' => now(),
        ]);

        $this->actingAs($invitee)
            ->get(route('errors.no-companies'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/NoCompanies')
                ->where('pending_workspace_invites.0.company_name', 'St Croix')
                ->where('pending_workspace_invites.0.brand_name', null)
                ->where('pending_workspace_invites.0.is_creator_invite', false));
    }

    public function test_no_companies_page_lists_pending_brand_invites_and_creator_prostaff_invites_by_email(): void
    {
        $tenant = Tenant::create([
            'name' => 'Host Tenant',
            'slug' => 'host-tenant',
        ]);

        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Steeleimage',
            'slug' => 'steeleimage',
        ]);

        $inviter = User::create([
            'email' => 'brand-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Brand',
            'last_name' => 'Admin',
        ]);

        $invitee = User::create([
            'email' => 'InvitedCreator@Example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Invited',
            'last_name' => 'Creator',
        ]);

        BrandInvitation::create([
            'brand_id' => $brand->id,
            'email' => 'invitedcreator@example.com',
            'role' => 'contributor',
            'metadata' => [
                'assign_prostaff_after_accept' => true,
                'prostaff_target_uploads' => 12,
            ],
            'token' => Str::random(64),
            'invited_by' => $inviter->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($invitee)
            ->get(route('errors.no-companies'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/NoCompanies')
                ->where('pending_workspace_invites.0.brand_name', 'Steeleimage')
                ->where('pending_workspace_invites.0.company_name', null)
                ->where('pending_workspace_invites.0.is_creator_invite', true));
    }

    public function test_no_companies_page_lists_pending_normal_brand_invite_by_email(): void
    {
        $tenant = Tenant::create([
            'name' => 'Host Tenant',
            'slug' => 'host-tenant-2',
        ]);

        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Plain Brand',
            'slug' => 'plain-brand',
        ]);

        $inviter = User::create([
            'email' => 'brand-admin-2@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Brand',
            'last_name' => 'Admin2',
        ]);

        $invitee = User::create([
            'email' => 'member-invite@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Member',
            'last_name' => 'Invitee',
        ]);

        BrandInvitation::create([
            'brand_id' => $brand->id,
            'email' => $invitee->email,
            'role' => 'contributor',
            'metadata' => null,
            'token' => Str::random(64),
            'invited_by' => $inviter->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($invitee)
            ->get(route('errors.no-companies'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/NoCompanies')
                ->where('pending_workspace_invites.0.brand_name', 'Plain Brand')
                ->where('pending_workspace_invites.0.is_creator_invite', false));
    }
}
