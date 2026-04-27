<?php

namespace Tests\Feature\Permissions;

use App\Mail\InviteMember;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end test: Team management requires team.manage permission.
 * Verifies routing, middleware, controller, and AuthPermissionService all behave correctly.
 */
class TeamManagementPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    #[Test]
    public function team_management_redirects_without_permission(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $brand = $tenant->defaultBrand;

        // Add owner first — Tenant::owner() auto-promotes first user to owner if none exists
        $owner = User::create([
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Owner',
            'last_name' => 'User',
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $member = User::create([
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Member',
            'last_name' => 'User',
        ]);
        $member->tenants()->attach($tenant->id, ['role' => 'member']);
        $member->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $response = $this->actingAs($member)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('companies.team'));

        // Non-JSON /app/* 403s are rendered as a redirect with a toast (see bootstrap/app.php).
        $response->assertRedirect(route('assets.index'));
        $response->assertSessionHas(
            'warning',
            'Only administrators and owners can access team management.'
        );
    }

    #[Test]
    public function team_management_returns_200_with_tenant_admin(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $brand = $tenant->defaultBrand;

        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/companies/team');

        $response->assertStatus(200);
    }

    #[Test]
    public function team_management_returns_200_with_tenant_owner(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $brand = $tenant->defaultBrand;

        $user = User::create([
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Owner',
            'last_name' => 'User',
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/companies/team');

        $response->assertStatus(200);
    }

    #[Test]
    public function agency_admin_cannot_assign_elevated_company_role_on_invite(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company-agcy-inv',
        ]);
        $brand = $tenant->defaultBrand;
        $this->assertNotNull($brand);

        $agencyAdmin = User::create([
            'email' => 'agcyadmin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Agency',
            'last_name' => 'Admin',
        ]);
        $agencyAdmin->tenants()->attach($tenant->id, ['role' => 'agency_admin']);
        $agencyAdmin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($agencyAdmin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->from(route('companies.team'))
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'newperson@example.com',
                'role' => 'agency_admin',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasErrors('role');
        Mail::assertNothingSent();
    }

    #[Test]
    public function tenant_admin_can_invite_with_admin_company_role(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name' => 'Test Co Invite',
            'slug' => 'test-co-invite',
        ]);
        $brand = $tenant->defaultBrand;
        $this->assertNotNull($brand);

        $admin = User::create([
            'email' => 'tadmin-inv@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'T',
            'last_name' => 'Admin',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->from(route('companies.team'))
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'invitedadmin@example.com',
                'role' => 'admin',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('companies.team'));
        Mail::assertSent(InviteMember::class);
    }
}
