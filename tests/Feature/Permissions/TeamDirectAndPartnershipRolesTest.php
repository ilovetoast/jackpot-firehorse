<?php

namespace Tests\Feature\Permissions;

use App\Mail\InviteMember;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamDirectAndPartnershipRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    private function clientWithBrand(): array
    {
        $tenant = Tenant::create([
            'name' => 'Client Co',
            'slug' => 'client-co-'.uniqid(),
        ]);
        $brand = $tenant->defaultBrand;
        $this->assertNotNull($brand);

        return [$tenant, $brand];
    }

    #[Test]
    public function owner_can_invite_direct_member(): void
    {
        Mail::fake();
        [$tenant, $brand] = $this->clientWithBrand();
        $owner = User::create([
            'email' => 'owner-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->from(route('companies.team'))
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'newmember-'.uniqid().'@example.com',
                'role' => 'member',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasNoErrors();
        Mail::assertSent(InviteMember::class);
    }

    #[Test]
    public function admin_can_invite_direct_admin(): void
    {
        Mail::fake();
        [$tenant, $brand] = $this->clientWithBrand();
        $admin = User::create([
            'email' => 'adm-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'D',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'otheradmin-'.uniqid().'@example.com',
                'role' => 'admin',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasNoErrors();
        Mail::assertSent(InviteMember::class);
    }

    #[Test]
    public function owner_cannot_invite_owner_role(): void
    {
        Mail::fake();
        [$tenant, $brand] = $this->clientWithBrand();
        $owner = User::create([
            'email' => 'own-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'x-'.uniqid().'@example.com',
                'role' => 'owner',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasErrors('role');
        Mail::assertNothingSent();
    }

    #[Test]
    public function admin_cannot_invite_agency_admin_via_generic_invite(): void
    {
        Mail::fake();
        [$tenant, $brand] = $this->clientWithBrand();
        $admin = User::create([
            'email' => 'adm2-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'D',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'ag-'.uniqid().'@example.com',
                'role' => 'agency_admin',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasErrors('role');
        Mail::assertNothingSent();
    }

    #[Test]
    public function admin_cannot_invite_agency_partner_via_generic_invite(): void
    {
        Mail::fake();
        [$tenant, $brand] = $this->clientWithBrand();
        $admin = User::create([
            'email' => 'adm3-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'D',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'pt-'.uniqid().'@example.com',
                'role' => 'agency_partner',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasErrors('role');
        Mail::assertNothingSent();
    }

    #[Test]
    public function admin_cannot_promote_direct_user_to_agency_admin_via_update_tenant_role(): void
    {
        [$tenant, $brand] = $this->clientWithBrand();
        $admin = User::create([
            'email' => 'adm4-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'D',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $member = User::create([
            'email' => 'mem-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'E',
        ]);
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'is_agency_managed' => false, 'agency_tenant_id' => null]);
        $member->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->put(route('companies.team.update-role', [$tenant, $member]), [
                'role' => 'agency_admin',
            ]);

        $response->assertSessionHasErrors('role');
        $this->assertSame('member', strtolower((string) $member->fresh()->getRoleForTenant($tenant)));
    }

    #[Test]
    public function admin_cannot_promote_direct_user_to_agency_partner_via_update_tenant_role(): void
    {
        [$tenant, $brand] = $this->clientWithBrand();
        $admin = User::create([
            'email' => 'adm5-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'D',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $member = User::create([
            'email' => 'mem2-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'E',
        ]);
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'is_agency_managed' => false, 'agency_tenant_id' => null]);
        $member->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->put(route('companies.team.update-role', [$tenant, $member]), [
                'role' => 'agency_partner',
            ]);

        $response->assertSessionHasErrors('role');
    }

    #[Test]
    public function agency_managed_member_cannot_have_tenant_role_changed_via_generic_route(): void
    {
        [$tenant, $brand] = $this->clientWithBrand();
        $agency = Tenant::create([
            'name' => 'Agency',
            'slug' => 'agency-'.uniqid(),
            'is_agency' => true,
        ]);
        $agBrand = $agency->defaultBrand;
        $this->assertNotNull($agBrand);

        $admin = User::create([
            'email' => 'cliadm-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'D',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $managed = User::create([
            'email' => 'managed-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'G',
        ]);
        $managed->tenants()->attach($agency->id, ['role' => 'member']);
        $managed->brands()->attach($agBrand->id, ['role' => 'viewer', 'removed_at' => null]);

        TenantAgency::create([
            'tenant_id' => $tenant->id,
            'agency_tenant_id' => $agency->id,
            'role' => 'agency_partner',
            'brand_assignments' => [['brand_id' => $brand->id, 'role' => 'viewer']],
            'created_by' => $admin->id,
        ]);

        $managed->tenants()->attach($tenant->id, [
            'role' => 'agency_partner',
            'is_agency_managed' => true,
            'agency_tenant_id' => $agency->id,
        ]);
        $managed->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->put(route('companies.team.update-role', [$tenant, $managed]), [
                'role' => 'member',
            ]);

        $response->assertSessionHasErrors('role');
        $this->assertSame('agency_partner', strtolower((string) $managed->fresh()->getRoleForTenant($tenant)));
    }

    #[Test]
    public function partnership_patch_updates_agency_relationship_role(): void
    {
        [$client, $brand] = $this->clientWithBrand();
        $agency = Tenant::create([
            'name' => 'Agency B',
            'slug' => 'agb-'.uniqid(),
            'is_agency' => true,
        ]);
        $agBrand = $agency->defaultBrand;
        $this->assertNotNull($agBrand);

        $admin = User::create([
            'email' => 'cad-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'A',
        ]);
        $admin->tenants()->attach($client->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $link = TenantAgency::create([
            'tenant_id' => $client->id,
            'agency_tenant_id' => $agency->id,
            'role' => 'agency_partner',
            'brand_assignments' => [['brand_id' => $brand->id, 'role' => 'viewer']],
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $client->id, 'brand_id' => $brand->id])
            ->patchJson(route('api.tenant.agencies.update', $link), [
                'role' => 'agency_admin',
                'brand_assignments' => [['brand_id' => $brand->id, 'role' => 'contributor']],
            ]);

        $response->assertOk();
        $this->assertSame('agency_admin', strtolower((string) $link->fresh()->role));
    }

    #[Test]
    public function generic_invite_rejects_user_who_belongs_to_linked_agency(): void
    {
        Mail::fake();
        [$client, $brand] = $this->clientWithBrand();
        $agency = Tenant::create([
            'name' => 'Agency C',
            'slug' => 'agc-'.uniqid(),
            'is_agency' => true,
        ]);
        $agBrand = $agency->defaultBrand;
        $this->assertNotNull($agBrand);

        $admin = User::create([
            'email' => 'cad2-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'A',
        ]);
        $admin->tenants()->attach($client->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        TenantAgency::create([
            'tenant_id' => $client->id,
            'agency_tenant_id' => $agency->id,
            'role' => 'agency_admin',
            'brand_assignments' => [['brand_id' => $brand->id, 'role' => 'viewer']],
            'created_by' => $admin->id,
        ]);

        $staff = User::create([
            'email' => 'staff-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'S',
            'last_name' => 'T',
        ]);
        $staff->tenants()->attach($agency->id, ['role' => 'member']);
        $staff->brands()->attach($agBrand->id, ['role' => 'viewer', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $client->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $client), [
                'email' => $staff->email,
                'role' => 'member',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasErrors('email');
        Mail::assertNothingSent();
    }

    #[Test]
    public function agency_workspace_admin_can_invite_agency_staff(): void
    {
        Mail::fake();
        $agency = Tenant::create([
            'name' => 'Agency D',
            'slug' => 'agd-'.uniqid(),
            'is_agency' => true,
        ]);
        $brand = $agency->defaultBrand;
        $this->assertNotNull($brand);

        $admin = User::create([
            'email' => 'agadm-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'G',
        ]);
        $admin->tenants()->attach($agency->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $agency->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $agency), [
                'email' => 'newstaff-'.uniqid().'@example.com',
                'role' => 'member',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasNoErrors();
        Mail::assertSent(InviteMember::class);
    }

    #[Test]
    public function api_tenant_roles_uses_display_labels_not_raw_keys(): void
    {
        [$tenant, $brand] = $this->clientWithBrand();
        $admin = User::create([
            'email' => 'labeltest-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'L',
            'last_name' => 'T',
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/api/roles/tenant');

        $response->assertOk();
        $roles = collect($response->json('roles'));
        $row = $roles->firstWhere('value', 'agency_admin');
        $this->assertNotNull($row);
        $this->assertSame('Agency manager', $row['label']);
        $this->assertStringNotContainsString('Agency_admin', $row['label']);
    }

    #[Test]
    public function invite_rejects_invalid_brand_role(): void
    {
        Mail::fake();
        [$tenant, $brand] = $this->clientWithBrand();
        $owner = User::create([
            'email' => 'own2-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'badbrand-'.uniqid().'@example.com',
                'role' => 'member',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'not_a_real_brand_role'],
                ],
            ]);

        $response->assertSessionHasErrors('brands.0.role');
        Mail::assertNothingSent();
    }
}
