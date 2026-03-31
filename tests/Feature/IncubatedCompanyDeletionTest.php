<?php

namespace Tests\Feature;

use App\Models\AgencyTier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Incubated client companies: agency steward (owner on client) must be able to delete the tenant
 * when remaining members are only agency-managed staff from the incubating agency.
 */
class IncubatedCompanyDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_incubating_agency_owner_can_delete_incubated_client_company(): void
    {
        $tier = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
            'incubation_window_days' => 90,
        ]);

        $agency = Tenant::create([
            'name' => 'Agency Co',
            'slug' => 'agency-co-del',
            'is_agency' => true,
            'agency_tier_id' => $tier->id,
        ]);

        $owner = User::factory()->create();
        $owner->tenants()->attach($agency->id, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['tenant_id' => $agency->id])
            ->post(route('agency.incubated-clients.store'), [
                'company_name' => 'Delete Me Inc',
                'incubation_target_plan_key' => 'pro',
            ])
            ->assertRedirect(route('agency.dashboard'));

        $client = Tenant::where('slug', 'delete-me-inc')->first();
        $this->assertNotNull($client);
        $this->assertSame($agency->id, $client->incubated_by_agency_id);

        $pivot = DB::table('tenant_user')
            ->where('tenant_id', $client->id)
            ->where('user_id', $owner->id)
            ->first();
        $this->assertNotNull($pivot);
        $this->assertSame('owner', $pivot->role);
        $this->assertSame(1, (int) $pivot->is_agency_managed, 'Incubator pivot should stay agency-managed after owner promotion');
        $this->assertSame($agency->id, (int) $pivot->agency_tenant_id);

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $client->id])
            ->delete(route('companies.destroy'));

        $response->assertRedirect(route('companies.index'));
        $response->assertSessionHas('success');
        $this->assertNull(Tenant::find($client->id));
    }

    public function test_incubating_agency_owner_can_delete_when_client_pivot_lacks_agency_managed_flags(): void
    {
        $tier = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
            'incubation_window_days' => 90,
        ]);

        $agency = Tenant::create([
            'name' => 'Agency Co',
            'slug' => 'agency-co-del-flags',
            'is_agency' => true,
            'agency_tier_id' => $tier->id,
        ]);

        $owner = User::factory()->create();
        $owner->tenants()->attach($agency->id, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['tenant_id' => $agency->id])
            ->post(route('agency.incubated-clients.store'), [
                'company_name' => 'Delete Me Flags Inc',
                'incubation_target_plan_key' => 'pro',
            ])
            ->assertRedirect(route('agency.dashboard'));

        $client = Tenant::where('slug', 'delete-me-flags-inc')->first();
        $this->assertNotNull($client);

        // Legacy / corrupted rows: owner promotion preserved role=owner but flags were cleared.
        DB::table('tenant_user')
            ->where('tenant_id', $client->id)
            ->where('user_id', $owner->id)
            ->update(['is_agency_managed' => false, 'agency_tenant_id' => null]);

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $client->id])
            ->delete(route('companies.destroy'));

        $response->assertRedirect(route('companies.index'));
        $response->assertSessionHas('success');
        $this->assertNull(Tenant::find($client->id));
    }

    public function test_incubating_agency_workspace_admin_can_delete_incubated_client_as_steward(): void
    {
        $tier = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
            'incubation_window_days' => 90,
        ]);

        $agency = Tenant::create([
            'name' => 'Agency Steward Admin',
            'slug' => 'agency-steward-admin',
            'is_agency' => true,
            'agency_tier_id' => $tier->id,
        ]);

        $agencyAdmin = User::factory()->create();
        $agencyAdmin->tenants()->attach($agency->id, ['role' => 'admin']);

        $this->actingAs($agencyAdmin)
            ->withSession(['tenant_id' => $agency->id])
            ->post(route('agency.incubated-clients.store'), [
                'company_name' => 'Steward Admin Client',
                'incubation_target_plan_key' => 'pro',
            ])
            ->assertRedirect(route('agency.dashboard'));

        $client = Tenant::where('slug', 'steward-admin-client')->first();
        $this->assertNotNull($client);

        $pivot = DB::table('tenant_user')
            ->where('tenant_id', $client->id)
            ->where('user_id', $agencyAdmin->id)
            ->first();
        $this->assertNotNull($pivot);
        $this->assertSame(1, (int) $pivot->is_agency_managed);

        $response = $this->actingAs($agencyAdmin)
            ->withSession(['tenant_id' => $client->id])
            ->delete(route('companies.destroy'));

        $response->assertRedirect(route('companies.index'));
        $this->assertNull(Tenant::find($client->id));
    }

    public function test_incubated_client_delete_still_works_when_second_agency_user_is_on_client(): void
    {
        $tier = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
            'incubation_window_days' => 90,
        ]);

        $agency = Tenant::create([
            'name' => 'Agency Two',
            'slug' => 'agency-two-del',
            'is_agency' => true,
            'agency_tier_id' => $tier->id,
        ]);

        $owner = User::factory()->create();
        $owner->tenants()->attach($agency->id, ['role' => 'owner']);

        $teammate = User::factory()->create();
        $teammate->tenants()->attach($agency->id, ['role' => 'admin']);

        $this->actingAs($owner)
            ->withSession(['tenant_id' => $agency->id])
            ->post(route('agency.incubated-clients.store'), [
                'company_name' => 'Two Agency Users Client',
                'incubation_target_plan_key' => 'pro',
            ])
            ->assertRedirect(route('agency.dashboard'));

        $client = Tenant::where('slug', 'two-agency-users-client')->first();
        $this->assertNotNull($client);

        $this->assertTrue(
            $owner->fresh()->tenants()->where('tenants.id', $client->id)->exists()
        );
        $this->assertTrue(
            $teammate->fresh()->tenants()->where('tenants.id', $client->id)->exists()
        );

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $client->id])
            ->delete(route('companies.destroy'));

        $response->assertRedirect(route('companies.index'));
        $this->assertNull(Tenant::find($client->id));
    }

    public function test_delete_blocked_when_non_agency_member_exists_on_incubated_client(): void
    {
        $tier = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
            'incubation_window_days' => 90,
        ]);

        $agency = Tenant::create([
            'name' => 'Agency Three',
            'slug' => 'agency-three-del',
            'is_agency' => true,
            'agency_tier_id' => $tier->id,
        ]);

        $owner = User::factory()->create();
        $owner->tenants()->attach($agency->id, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['tenant_id' => $agency->id])
            ->post(route('agency.incubated-clients.store'), [
                'company_name' => 'Has External Member',
                'incubation_target_plan_key' => 'pro',
            ])
            ->assertRedirect(route('agency.dashboard'));

        $client = Tenant::where('slug', 'has-external-member')->first();
        $this->assertNotNull($client);

        $outsider = User::factory()->create();
        $outsider->tenants()->attach($client->id, [
            'role' => 'member',
            'is_agency_managed' => false,
            'agency_tenant_id' => null,
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $client->id])
            ->delete(route('companies.destroy'));

        $response->assertRedirect();
        $response->assertSessionHasErrors('error');
        $this->assertNotNull(Tenant::find($client->id));
    }
}
