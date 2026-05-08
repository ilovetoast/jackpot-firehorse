<?php

namespace Tests\Feature\Demo;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DemoWorkspaceTeamInviteBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_invite_blocked_for_demo_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Inc',
            'slug' => 'demo-inc',
            'is_demo' => true,
        ]);
        $brand = $tenant->defaultBrand;
        $this->assertNotNull($brand);

        $owner = User::create([
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'invitee@example.com',
                'role' => 'member',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertSessionHasErrors(['email' => \App\Services\Demo\DemoTenantService::DISABLED_MESSAGE]);
    }

    public function test_team_invite_not_blocked_for_normal_tenant(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name' => 'Real Inc',
            'slug' => 'real-inc',
        ]);
        $brand = $tenant->defaultBrand;
        $this->assertNotNull($brand);

        $owner = User::create([
            'email' => 'owner2@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->post(route('companies.team.invite', $tenant), [
                'email' => 'invitee2@example.com',
                'role' => 'member',
                'brands' => [
                    ['brand_id' => $brand->id, 'role' => 'viewer'],
                ],
            ]);

        $response->assertRedirect(route('companies.team'));
        Mail::assertSent(\App\Mail\InviteMember::class);
    }
}
