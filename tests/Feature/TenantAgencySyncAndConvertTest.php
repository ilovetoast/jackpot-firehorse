<?php

namespace Tests\Feature;

use App\Models\AgencyTier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantAgencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantAgencySyncAndConvertTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAgencyAndTier(): Tenant
    {
        $tier = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
            'incubation_window_days' => 90,
        ]);

        return Tenant::create([
            'name' => 'Test Agency',
            'slug' => 'test-agency-'.uniqid(),
            'is_agency' => true,
            'agency_tier_id' => $tier->id,
        ]);
    }

    public function test_sync_adds_agency_staff_who_were_not_on_client_yet(): void
    {
        $agency = $this->makeAgencyAndTier();
        $client = Tenant::create([
            'name' => 'Client Co',
            'slug' => 'client-co-'.uniqid(),
            'is_agency' => false,
        ]);

        $agencyOwner = User::factory()->create();
        $agencyOwner->tenants()->attach($agency->id, ['role' => 'owner']);

        $clientOwner = User::factory()->create();
        $clientOwner->tenants()->attach($client->id, ['role' => 'owner']);

        $service = app(TenantAgencyService::class);
        $link = $service->attach($client, $agency, 'member', [], $clientOwner);

        $lateJoiner = User::factory()->create();
        $lateJoiner->tenants()->attach($agency->id, ['role' => 'member']);

        $agencyAdmin = User::factory()->create();
        $agencyAdmin->tenants()->attach($agency->id, ['role' => 'admin']);

        $response = $this->actingAs($agencyAdmin)
            ->withSession(['tenant_id' => $agency->id])
            ->postJson("/app/api/agency/tenant-agencies/{$link->id}/sync-users");

        $response->assertOk();
        $response->assertJsonPath('added', 1);
        $response->assertJsonPath('skipped_existing_membership', 1);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $client->id,
            'user_id' => $lateJoiner->id,
            'is_agency_managed' => 1,
            'agency_tenant_id' => $agency->id,
        ]);
    }

    public function test_client_admin_can_convert_direct_member_to_agency_managed(): void
    {
        $agency = $this->makeAgencyAndTier();
        $client = Tenant::create([
            'name' => 'Client Co 2',
            'slug' => 'client-co-2-'.uniqid(),
            'is_agency' => false,
        ]);

        $agencyStaff = User::factory()->create();
        $agencyStaff->tenants()->attach($agency->id, ['role' => 'owner']);

        $clientOwner = User::factory()->create();
        $clientOwner->tenants()->attach($client->id, ['role' => 'owner']);

        $elena = User::factory()->create();
        $elena->tenants()->attach($client->id, [
            'role' => 'admin',
            'is_agency_managed' => false,
            'agency_tenant_id' => null,
        ]);

        $service = app(TenantAgencyService::class);
        $service->attach($client, $agency, 'member', [], $clientOwner);

        $pivotBefore = DB::table('tenant_user')
            ->where('tenant_id', $client->id)
            ->where('user_id', $elena->id)
            ->first();
        $this->assertNotNull($pivotBefore);
        $this->assertSame(0, (int) $pivotBefore->is_agency_managed);

        $response = $this->actingAs($clientOwner)
            ->withSession(['tenant_id' => $client->id])
            ->postJson("/app/api/companies/users/{$elena->id}/agency-managed", [
                'agency_tenant_id' => $agency->id,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $pivotAfter = DB::table('tenant_user')
            ->where('tenant_id', $client->id)
            ->where('user_id', $elena->id)
            ->first();
        $this->assertNotNull($pivotAfter);
        $this->assertSame(1, (int) $pivotAfter->is_agency_managed);
        $this->assertSame($agency->id, (int) $pivotAfter->agency_tenant_id);
    }

    public function test_sync_returns_404_when_link_belongs_to_another_agency(): void
    {
        $agencyA = $this->makeAgencyAndTier();
        $agencyB = $this->makeAgencyAndTier();
        $client = Tenant::create([
            'name' => 'Client X',
            'slug' => 'client-x-'.uniqid(),
            'is_agency' => false,
        ]);

        $ownerA = User::factory()->create();
        $ownerA->tenants()->attach($agencyA->id, ['role' => 'owner']);
        $ownerB = User::factory()->create();
        $ownerB->tenants()->attach($agencyB->id, ['role' => 'owner']);

        $clientOwner = User::factory()->create();
        $clientOwner->tenants()->attach($client->id, ['role' => 'owner']);

        $service = app(TenantAgencyService::class);
        $link = $service->attach($client, $agencyA, 'member', [], $clientOwner);

        $this->actingAs($ownerB)
            ->withSession(['tenant_id' => $agencyB->id])
            ->postJson("/app/api/agency/tenant-agencies/{$link->id}/sync-users")
            ->assertNotFound();
    }
}
