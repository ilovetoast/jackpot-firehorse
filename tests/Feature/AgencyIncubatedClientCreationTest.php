<?php

namespace Tests\Feature;

use App\Models\AgencyTier;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyIncubatedClientCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_owner_can_create_incubated_client_company(): void
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
            'slug' => 'agency-co',
            'is_agency' => true,
            'agency_tier_id' => $tier->id,
        ]);

        $user = User::factory()->create();
        $user->tenants()->attach($agency->id, ['role' => 'owner']);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $agency->id])
            ->post(route('agency.incubated-clients.store'), [
                'company_name' => 'New Client Inc',
                'incubation_target_plan_key' => 'pro',
            ]);

        $response->assertRedirect(route('agency.dashboard'));
        $response->assertSessionHas('success');

        $client = Tenant::where('slug', 'new-client-inc')->first();
        $this->assertNotNull($client);
        $this->assertSame($agency->id, $client->incubated_by_agency_id);
        $this->assertNotNull($client->incubated_at);
        $this->assertNotNull($client->incubation_expires_at);

        $this->assertTrue(
            TenantAgency::where('tenant_id', $client->id)
                ->where('agency_tenant_id', $agency->id)
                ->exists()
        );

        $this->assertSame(
            'owner',
            $user->fresh()->getRoleForTenant($client)
        );
        $this->assertSame('pro', $client->incubation_target_plan_key);
    }

    public function test_agency_member_without_permission_cannot_create_incubated_client(): void
    {
        $tier = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
        ]);

        $agency = Tenant::create([
            'name' => 'Agency Co',
            'slug' => 'agency-co-2',
            'is_agency' => true,
            'agency_tier_id' => $tier->id,
        ]);

        $user = User::factory()->create();
        $user->tenants()->attach($agency->id, ['role' => 'member']);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $agency->id])
            ->post(route('agency.incubated-clients.store'), [
                'company_name' => 'Should Fail',
            ])
            ->assertForbidden();
    }

    public function test_non_agency_tenant_cannot_create_incubated_client(): void
    {
        $company = Tenant::create([
            'name' => 'Regular Co',
            'slug' => 'regular-co',
            'is_agency' => false,
        ]);

        $user = User::factory()->create();
        $user->tenants()->attach($company->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $company->id])
            ->post(route('agency.incubated-clients.store'), [
                'company_name' => 'Nope',
            ])
            ->assertForbidden();
    }
}
