<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\ProstaffMembership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: pending-invite UIs match email with LOWER(); accept must not use strict PHP string compare.
 */
class BrandGatewayBrandInviteAcceptEmailCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_accept_matches_invitation_email_case_insensitively_and_applies_prostaff(): void
    {
        $tenant = Tenant::create(['name' => 'Co', 'slug' => 'co-gw-case']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Br',
            'slug' => 'br-gw-case',
        ]);
        $this->enableCreatorModuleForTenant($tenant);

        $inviter = User::factory()->create();
        $inviter->tenants()->attach($tenant->id, ['role' => 'admin']);

        $token = Str::random(64);
        BrandInvitation::create([
            'brand_id' => $brand->id,
            'email' => 'Invited@Example.COM',
            'role' => 'contributor',
            'metadata' => [
                'assign_prostaff_after_accept' => true,
                'prostaff_target_uploads' => 5,
                'prostaff_period_type' => 'month',
            ],
            'token' => $token,
            'invited_by' => $inviter->id,
            'sent_at' => now(),
        ]);

        $invitee = User::create([
            'email' => 'invited@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'I',
            'last_name' => 'N',
        ]);

        $this->actingAs($invitee)
            ->post(route('gateway.invite.accept', ['token' => $token]))
            ->assertRedirect();

        $invitation = BrandInvitation::where('token', $token)->first();
        $this->assertNotNull($invitation);
        $this->assertNotNull($invitation->accepted_at);

        $this->assertTrue($invitee->fresh()->belongsToTenant($tenant->id));

        $this->assertNotNull(
            ProstaffMembership::query()
                ->where('user_id', $invitee->id)
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->first()
        );
    }
}
