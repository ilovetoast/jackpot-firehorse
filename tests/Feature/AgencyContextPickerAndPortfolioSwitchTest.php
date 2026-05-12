<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgencyContextPickerAndPortfolioSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    /**
     * @return array{0: Tenant, 1: \App\Models\Brand}
     */
    private function tenantWithBrand(array $tenantAttrs = []): array
    {
        $tenant = Tenant::create(array_merge([
            'name' => 'Co '.uniqid(),
            'slug' => 'co-'.uniqid(),
        ], $tenantAttrs));
        $brand = $tenant->defaultBrand;
        $this->assertNotNull($brand);

        return [$tenant, $brand];
    }

    #[Test]
    public function normal_user_overview_has_null_agency_context_picker(): void
    {
        [$tenant, $brand] = $this->tenantWithBrand(['is_agency' => false]);
        $user = User::create([
            'email' => 'norm-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'N',
            'last_name' => 'U',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'admin', 'is_agency_managed' => false]);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.agency_context_picker', null));
    }

    #[Test]
    public function agency_user_on_pivot_managed_client_without_tenant_agency_row_gets_agency_context_picker(): void
    {
        [$agency, $agBrand] = $this->tenantWithBrand(['is_agency' => true]);
        [$client, $clientBrand] = $this->tenantWithBrand(['is_agency' => false]);

        $user = User::create([
            'email' => 'pivot-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'P',
            'last_name' => 'V',
        ]);
        $user->tenants()->attach($agency->id, ['role' => 'admin', 'is_agency_managed' => false]);
        $user->tenants()->attach($client->id, [
            'role' => 'admin',
            'is_agency_managed' => true,
            'agency_tenant_id' => $agency->id,
        ]);
        $user->brands()->attach($agBrand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->brands()->attach($clientBrand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $client->id, 'brand_id' => $clientBrand->id])
            ->get(route('overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.agency_context_picker.is_agency_context_picker', true)
                ->where('auth.agency_context_picker.active_tenant_id', $client->id)
                ->where('auth.agency_context_picker.active_brand_id', $clientBrand->id)
                ->has('auth.agency_context_picker.groups'));

        $this->actingAs($user)
            ->withSession(['tenant_id' => $client->id, 'brand_id' => $clientBrand->id])
            ->post(route('companies.switch', $agency), [
                'brand_id' => $agBrand->id,
                'redirect' => '/app/overview',
            ])
            ->assertRedirect();

        $this->assertSame($agency->id, session('tenant_id'));
        $this->assertSame($agBrand->id, session('brand_id'));
    }

    #[Test]
    public function agency_user_on_agency_workspace_receives_agency_context_picker_payload(): void
    {
        [$agency, $agBrand] = $this->tenantWithBrand(['is_agency' => true]);
        [$client, $clientBrand] = $this->tenantWithBrand(['is_agency' => false]);

        $user = User::create([
            'email' => 'ag-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'G',
        ]);
        $user->tenants()->attach($agency->id, ['role' => 'admin', 'is_agency_managed' => false]);
        $user->tenants()->attach($client->id, [
            'role' => 'admin',
            'is_agency_managed' => true,
            'agency_tenant_id' => $agency->id,
        ]);
        $user->brands()->attach($agBrand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->brands()->attach($clientBrand->id, ['role' => 'admin', 'removed_at' => null]);

        TenantAgency::create([
            'tenant_id' => $client->id,
            'agency_tenant_id' => $agency->id,
            'role' => 'agency_admin',
            'brand_assignments' => [['brand_id' => $clientBrand->id, 'role' => 'viewer']],
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $agency->id, 'brand_id' => $agBrand->id])
            ->get(route('overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.agency_context_picker.is_agency_context_picker', true)
                ->where('auth.agency_context_picker.active_tenant_id', $agency->id)
                ->where('auth.agency_context_picker.active_brand_id', $agBrand->id)
                ->has('auth.agency_context_picker.groups'));
    }

    #[Test]
    public function agency_user_can_switch_to_non_agency_managed_workspace_they_belong_to(): void
    {
        [$agency, $agBrand] = $this->tenantWithBrand(['is_agency' => true]);
        [$personal, $personalBrand] = $this->tenantWithBrand(['is_agency' => false]);

        $user = User::create([
            'email' => 'mix-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'X',
        ]);
        $user->tenants()->attach($agency->id, ['role' => 'member', 'is_agency_managed' => false]);
        $user->tenants()->attach($personal->id, ['role' => 'member', 'is_agency_managed' => false]);
        $user->brands()->attach($agBrand->id, ['role' => 'viewer', 'removed_at' => null]);
        $user->brands()->attach($personalBrand->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $agency->id, 'brand_id' => $agBrand->id])
            ->post(route('companies.switch', $personal), [
                'brand_id' => $personalBrand->id,
                'redirect' => '/app/overview',
            ])
            ->assertRedirect();

        $this->assertSame($personal->id, session('tenant_id'));
        $this->assertSame($personalBrand->id, session('brand_id'));
    }

    #[Test]
    public function agency_capable_user_can_switch_to_linked_client_tenant_and_brand(): void
    {
        [$agency, $agBrand] = $this->tenantWithBrand(['is_agency' => true]);
        [$client, $clientBrand] = $this->tenantWithBrand(['is_agency' => false]);

        $user = User::create([
            'email' => 'ok-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'K',
        ]);
        $user->tenants()->attach($agency->id, ['role' => 'admin', 'is_agency_managed' => false]);
        $user->tenants()->attach($client->id, [
            'role' => 'admin',
            'is_agency_managed' => true,
            'agency_tenant_id' => $agency->id,
        ]);
        $user->brands()->attach($agBrand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->brands()->attach($clientBrand->id, ['role' => 'admin', 'removed_at' => null]);

        TenantAgency::create([
            'tenant_id' => $client->id,
            'agency_tenant_id' => $agency->id,
            'role' => 'agency_admin',
            'brand_assignments' => [['brand_id' => $clientBrand->id, 'role' => 'viewer']],
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $agency->id, 'brand_id' => $agBrand->id])
            ->post(route('companies.switch', $client), [
                'brand_id' => $clientBrand->id,
                'redirect' => '/app/overview',
            ])
            ->assertRedirect();

        $this->assertSame($client->id, session('tenant_id'));
        $this->assertSame($clientBrand->id, session('brand_id'));
    }

    #[Test]
    public function company_switch_rejects_brand_not_belonging_to_target_tenant(): void
    {
        [$agency, $agBrand] = $this->tenantWithBrand(['is_agency' => true]);
        [$client, $clientBrand] = $this->tenantWithBrand(['is_agency' => false]);
        [, $otherBrand] = $this->tenantWithBrand(['is_agency' => false]);

        $user = User::create([
            'email' => 'bad-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'B',
            'last_name' => 'D',
        ]);
        $user->tenants()->attach($agency->id, ['role' => 'admin', 'is_agency_managed' => false]);
        $user->tenants()->attach($client->id, [
            'role' => 'admin',
            'is_agency_managed' => true,
            'agency_tenant_id' => $agency->id,
        ]);
        $user->brands()->attach($agBrand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->brands()->attach($clientBrand->id, ['role' => 'admin', 'removed_at' => null]);

        TenantAgency::create([
            'tenant_id' => $client->id,
            'agency_tenant_id' => $agency->id,
            'role' => 'agency_admin',
            'brand_assignments' => [['brand_id' => $clientBrand->id, 'role' => 'viewer']],
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $agency->id, 'brand_id' => $agBrand->id])
            ->post(route('companies.switch', $client), [
                'brand_id' => $otherBrand->id,
                'redirect' => '/app/overview',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function non_agency_user_can_still_switch_between_their_tenants(): void
    {
        [$a, $brandA] = $this->tenantWithBrand(['is_agency' => false]);
        [$b, $brandB] = $this->tenantWithBrand(['is_agency' => false]);

        $user = User::create([
            'email' => 'two-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'T',
            'last_name' => 'W',
        ]);
        $user->tenants()->attach($a->id, ['role' => 'owner', 'is_agency_managed' => false]);
        $user->tenants()->attach($b->id, ['role' => 'owner', 'is_agency_managed' => false]);
        $user->brands()->attach($brandA->id, ['role' => 'admin', 'removed_at' => null]);
        $user->brands()->attach($brandB->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $a->id, 'brand_id' => $brandA->id])
            ->post(route('companies.switch', $b), [
                'brand_id' => $brandB->id,
                'redirect' => '/app/overview',
            ])
            ->assertRedirect();

        $this->assertSame($b->id, session('tenant_id'));
        $this->assertSame($brandB->id, session('brand_id'));
    }
}
