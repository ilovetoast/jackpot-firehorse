<?php

namespace Tests\Feature;

use App\Models\AgencyTier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncubationAdminExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    public function test_site_admin_can_extend_incubation_deadline_within_tier_cap(): void
    {
        $silver = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
            'incubation_window_days' => 30,
            'max_support_extension_days' => 14,
        ]);

        $agency = Tenant::create([
            'name' => 'Agency',
            'slug' => 'agency-x',
            'is_agency' => true,
            'agency_tier_id' => $silver->id,
        ]);

        $client = Tenant::create([
            'name' => 'Client',
            'slug' => 'client-x',
            'incubated_by_agency_id' => $agency->id,
            'incubated_at' => now()->subDays(20),
            'incubation_expires_at' => now()->subDay(),
            'incubation_target_plan_key' => 'pro',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('site_admin');
        $admin->tenants()->attach($agency->id, ['role' => 'member']);

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $agency->id])
            ->postJson(route('admin.api.companies.incubation.extend', $client), [
                'extend_days' => 10,
                'reason' => 'Test',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Incubation deadline extended.');

        $client->refresh();
        $this->assertTrue($client->incubation_expires_at->isFuture());
    }

    public function test_extension_rejects_days_above_tier_cap(): void
    {
        $silver = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
            'incubation_window_days' => 30,
            'max_support_extension_days' => 14,
        ]);

        $agency = Tenant::create([
            'name' => 'Agency',
            'slug' => 'agency-y',
            'is_agency' => true,
            'agency_tier_id' => $silver->id,
        ]);

        $client = Tenant::create([
            'name' => 'Client',
            'slug' => 'client-y',
            'incubated_by_agency_id' => $agency->id,
            'incubated_at' => now()->subDays(20),
            'incubation_expires_at' => now()->subDay(),
            'incubation_target_plan_key' => 'pro',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('site_admin');
        $admin->tenants()->attach($agency->id, ['role' => 'member']);

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $agency->id])
            ->postJson(route('admin.api.companies.incubation.extend', $client), [
                'extend_days' => 20,
            ])
            ->assertStatus(422);
    }
}
