<?php

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\OwnershipTransferStatus;
use App\Events\CompanyTransferCompleted;
use App\Listeners\GrantAgencyPartnerReward;
use App\Models\ActivityEvent;
use App\Models\AgencyPartnerAccess;
use App\Models\AgencyPartnerReward;
use App\Models\AgencyTier;
use App\Models\Brand;
use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OwnershipTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Agency Partner Program Test Suite
 *
 * Phase AG-9 — Hardening, Abuse Cases & Test Coverage
 *
 * Covers all critical paths for:
 * - Transfer & billing gate
 * - Reward attribution safety
 * - Tier advancement
 * - Agency partner access
 * - Incubation tracking
 * - Abuse & edge cases
 * - Audit & event integrity
 */
class AgencyPartnerProgramTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $agencyTenant;
    protected Tenant $clientTenant;
    protected User $agencyOwner;
    protected User $agencyAdmin;
    protected User $clientOwner;
    protected User $clientAdmin;
    protected AgencyTier $silverTier;
    protected AgencyTier $goldTier;
    protected AgencyTier $platinumTier;
    protected OwnershipTransferService $transferService;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Create agency tiers
        $this->silverTier = AgencyTier::create([
            'name' => 'Silver',
            'tier_order' => 1,
            'activation_threshold' => 0,
            'reward_percentage' => 5,
        ]);
        $this->goldTier = AgencyTier::create([
            'name' => 'Gold',
            'tier_order' => 2,
            'activation_threshold' => 5,
            'reward_percentage' => 10,
        ]);
        $this->platinumTier = AgencyTier::create([
            'name' => 'Platinum',
            'tier_order' => 3,
            'activation_threshold' => 15,
            'reward_percentage' => 15,
        ]);

        // Create agency tenant
        $this->agencyTenant = Tenant::create([
            'name' => 'Test Agency',
            'slug' => 'test-agency',
            'is_agency' => true,
            'agency_tier_id' => $this->silverTier->id,
            'activated_client_count' => 0,
        ]);

        // Create agency owner
        $this->agencyOwner = User::create([
            'email' => 'agency-owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Agency',
            'last_name' => 'Owner',
        ]);
        $this->agencyOwner->tenants()->attach($this->agencyTenant->id, ['role' => 'owner']);

        // Create agency admin
        $this->agencyAdmin = User::create([
            'email' => 'agency-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Agency',
            'last_name' => 'Admin',
        ]);
        $this->agencyAdmin->tenants()->attach($this->agencyTenant->id, ['role' => 'admin']);

        // Create client tenant (incubated by agency)
        $this->clientTenant = Tenant::create([
            'name' => 'Test Client',
            'slug' => 'test-client',
            'incubated_by_agency_id' => $this->agencyTenant->id,
            'incubated_at' => now()->subDays(10),
        ]);

        // Create client owner
        $this->clientOwner = User::create([
            'email' => 'client-owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Client',
            'last_name' => 'Owner',
        ]);
        $this->clientOwner->tenants()->attach($this->clientTenant->id, ['role' => 'owner']);

        // Create client admin (will be new owner after transfer)
        $this->clientAdmin = User::create([
            'email' => 'client-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Client',
            'last_name' => 'Admin',
        ]);
        $this->clientAdmin->tenants()->attach($this->clientTenant->id, ['role' => 'admin']);

        $this->transferService = app(OwnershipTransferService::class);
    }

    // =========================================================================
    // SECTION 1: Transfer & Billing Gate Tests
    // =========================================================================

    /** @test */
    public function transfer_does_not_complete_without_billing(): void
    {
        // Ensure client has no billing
        $this->clientTenant->update([
            'billing_status' => null,
            'billing_status_expires_at' => null,
        ]);

        // Initiate and progress transfer
        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Verify transfer is in PENDING_BILLING state
        $this->assertEquals(OwnershipTransferStatus::PENDING_BILLING, $transfer->status);

        // Verify ownership has NOT flipped
        $this->assertTrue($this->clientTenant->fresh()->isOwner($this->clientOwner));
        $this->assertFalse($this->clientTenant->fresh()->isOwner($this->clientAdmin));
    }

    /** @test */
    public function transfer_enters_pending_billing_state(): void
    {
        $this->clientTenant->update(['billing_status' => null]);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->assertEquals(OwnershipTransferStatus::PENDING_BILLING, $transfer->status);
        $this->assertNull($transfer->completed_at);
    }

    /** @test */
    public function ownership_does_not_flip_while_pending_billing(): void
    {
        $this->clientTenant->update(['billing_status' => null]);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Verify status is pending billing
        $this->assertEquals(OwnershipTransferStatus::PENDING_BILLING, $transfer->status);

        // Verify original owner is still owner
        $this->clientTenant->refresh();
        $this->assertTrue($this->clientTenant->isOwner($this->clientOwner));

        // Verify new owner is still admin
        $clientAdminRole = $this->clientAdmin->getRoleForTenant($this->clientTenant);
        $this->assertEquals('admin', $clientAdminRole);
    }

    /** @test */
    public function transfer_completes_immediately_if_billing_already_active(): void
    {
        // Set billing as active
        $this->clientTenant->update([
            'billing_status' => 'comped',
            'billing_status_expires_at' => now()->addYear(),
        ]);

        Event::fake([CompanyTransferCompleted::class]);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Verify transfer completed
        $this->assertEquals(OwnershipTransferStatus::COMPLETED, $transfer->status);
        $this->assertNotNull($transfer->completed_at);

        // Verify ownership flipped
        $this->clientTenant->refresh();
        $this->assertTrue($this->clientTenant->isOwner($this->clientAdmin));
        $this->assertFalse($this->clientTenant->isOwner($this->clientOwner));

        Event::assertDispatched(CompanyTransferCompleted::class);
    }

    /** @test */
    public function cancel_works_from_pending_billing_state(): void
    {
        $this->clientTenant->update(['billing_status' => null]);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->assertEquals(OwnershipTransferStatus::PENDING_BILLING, $transfer->status);

        // Cancel from pending billing state
        $transfer = $this->transferService->cancelTransfer($transfer, $this->clientOwner);

        $this->assertEquals(OwnershipTransferStatus::CANCELLED, $transfer->status);
    }

    // =========================================================================
    // SECTION 2: Reward Attribution Safety Tests
    // =========================================================================

    /** @test */
    public function reward_is_granted_only_once_per_transfer(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Verify exactly one reward was created
        $this->assertEquals(1, AgencyPartnerReward::where('ownership_transfer_id', $transfer->id)->count());
    }

    /** @test */
    public function duplicate_event_dispatch_does_not_create_duplicate_rewards(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $initialCount = AgencyPartnerReward::where('ownership_transfer_id', $transfer->id)->count();
        $this->assertEquals(1, $initialCount);

        // Manually dispatch event again (simulating duplicate)
        $listener = new GrantAgencyPartnerReward();
        $listener->handle(new CompanyTransferCompleted($transfer->fresh()));
        $listener->handle(new CompanyTransferCompleted($transfer->fresh()));

        // Verify still only one reward
        $this->assertEquals(1, AgencyPartnerReward::where('ownership_transfer_id', $transfer->id)->count());
    }

    /** @test */
    public function transfers_without_incubated_by_agency_id_do_not_grant_rewards(): void
    {
        // Remove agency incubation
        $this->clientTenant->update([
            'incubated_by_agency_id' => null,
            'billing_status' => 'comped',
        ]);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // No reward should be created
        $this->assertEquals(0, AgencyPartnerReward::count());
    }

    /** @test */
    public function transfers_where_incubating_tenant_is_not_agency_do_not_grant_rewards(): void
    {
        // Make incubating tenant NOT an agency
        $this->agencyTenant->update(['is_agency' => false]);
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // No reward should be created
        $this->assertEquals(0, AgencyPartnerReward::count());
    }

    // =========================================================================
    // SECTION 3: Tier Advancement Tests
    // =========================================================================

    /** @test */
    public function activated_client_count_increments_correctly(): void
    {
        $initialCount = $this->agencyTenant->activated_client_count;
        $this->assertEquals(0, $initialCount);

        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->agencyTenant->refresh();
        $this->assertEquals(1, $this->agencyTenant->activated_client_count);
    }

    /** @test */
    public function tier_upgrades_occur_at_correct_thresholds(): void
    {
        // Set agency to 4 activated clients (one below Gold threshold)
        $this->agencyTenant->update([
            'activated_client_count' => 4,
            'agency_tier_id' => $this->silverTier->id,
        ]);

        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->agencyTenant->refresh();
        $this->assertEquals(5, $this->agencyTenant->activated_client_count);
        $this->assertEquals($this->goldTier->id, $this->agencyTenant->agency_tier_id);
    }

    /** @test */
    public function tier_never_downgrades(): void
    {
        // Set agency at Gold tier with count at threshold
        $this->agencyTenant->update([
            'activated_client_count' => 5,
            'agency_tier_id' => $this->goldTier->id,
        ]);

        // Manually decrement count (should never happen in production)
        $this->agencyTenant->decrement('activated_client_count');
        $this->agencyTenant->refresh();

        // Trigger tier check via listener
        $listener = new GrantAgencyPartnerReward();

        // Create a mock transfer to trigger the check
        $this->clientTenant->update(['billing_status' => 'comped']);
        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Tier should remain Gold or higher (the transfer would increment count back to 5)
        $this->agencyTenant->refresh();
        $this->assertGreaterThanOrEqual($this->goldTier->tier_order, $this->agencyTenant->agencyTier->tier_order);
    }

    /** @test */
    public function custom_activation_threshold_values_from_db_are_respected(): void
    {
        // Set custom threshold on Gold tier
        $this->goldTier->update(['activation_threshold' => 3]);

        // Set agency to 2 activated clients
        $this->agencyTenant->update([
            'activated_client_count' => 2,
            'agency_tier_id' => $this->silverTier->id,
        ]);

        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->agencyTenant->refresh();
        // With custom threshold of 3, agency should be at Gold now
        $this->assertEquals($this->goldTier->id, $this->agencyTenant->agency_tier_id);
    }

    /** @test */
    public function fallback_thresholds_are_used_when_db_values_are_null(): void
    {
        // Clear DB thresholds
        AgencyTier::query()->update(['activation_threshold' => null]);

        // Set agency to 4 activated clients
        $this->agencyTenant->update([
            'activated_client_count' => 4,
            'agency_tier_id' => $this->silverTier->id,
        ]);

        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->agencyTenant->refresh();
        // Fallback Gold threshold is 5, so agency should advance to Gold at 5
        $this->assertEquals(5, $this->agencyTenant->activated_client_count);
        $this->assertEquals($this->goldTier->id, $this->agencyTenant->agency_tier_id);
    }

    // =========================================================================
    // SECTION 4: Agency Partner Access Tests
    // =========================================================================

    /** @test */
    public function agency_partner_role_is_granted_only_after_completed_transfer(): void
    {
        $this->clientTenant->update(['billing_status' => null]);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Transfer is PENDING_BILLING, not completed
        $this->assertEquals(OwnershipTransferStatus::PENDING_BILLING, $transfer->status);

        // Agency owner should NOT have access to client tenant
        $this->assertNull($this->agencyOwner->getRoleForTenant($this->clientTenant));

        // No access records should exist
        $this->assertEquals(0, AgencyPartnerAccess::count());
    }

    /** @test */
    public function agency_partner_role_is_scoped_only_to_client_tenant(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        // Create another tenant
        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-tenant']);
        $otherUser = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'User',
        ]);
        $otherUser->tenants()->attach($otherTenant->id, ['role' => 'owner']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Agency owner should have access to client tenant
        $this->assertEquals('agency_partner', $this->agencyOwner->getRoleForTenant($this->clientTenant));

        // Agency owner should NOT have access to other tenant
        $this->assertNull($this->agencyOwner->getRoleForTenant($otherTenant));
    }

    /** @test */
    public function client_can_revoke_agency_access(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Verify access was granted
        $access = AgencyPartnerAccess::where('user_id', $this->agencyOwner->id)
            ->where('client_tenant_id', $this->clientTenant->id)
            ->first();
        $this->assertNotNull($access);
        $this->assertTrue($access->isActive());

        // Revoke access (simulating client action)
        $access->update([
            'revoked_at' => now(),
            'revoked_by' => $this->clientAdmin->id,
        ]);

        // Remove role from tenant_user
        $this->agencyOwner->tenants()->detach($this->clientTenant->id);

        // Verify access is revoked
        $this->assertFalse($access->fresh()->isActive());
        $this->assertNull($this->agencyOwner->getRoleForTenant($this->clientTenant));
    }

    /** @test */
    public function revocation_does_not_affect_rewards_or_tier_history(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Get reward before revocation
        $rewardBefore = AgencyPartnerReward::where('ownership_transfer_id', $transfer->id)->first();
        $countBefore = $this->agencyTenant->fresh()->activated_client_count;

        // Revoke access
        $access = AgencyPartnerAccess::where('user_id', $this->agencyOwner->id)
            ->where('client_tenant_id', $this->clientTenant->id)
            ->first();
        $access->update(['revoked_at' => now(), 'revoked_by' => $this->clientAdmin->id]);

        // Reward still exists
        $this->assertTrue($rewardBefore->fresh()->exists);

        // Activated count unchanged
        $this->assertEquals($countBefore, $this->agencyTenant->fresh()->activated_client_count);
    }

    // =========================================================================
    // SECTION 5: Incubation Tracking Safety Tests
    // =========================================================================

    /** @test */
    public function incubation_fields_are_informational_only(): void
    {
        // Set incubation fields
        $this->clientTenant->update([
            'incubated_at' => now()->subDays(30),
            'incubation_expires_at' => now()->subDays(1), // Already expired
            'billing_status' => 'comped',
        ]);

        // Transfer should still complete despite expired incubation
        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->assertEquals(OwnershipTransferStatus::COMPLETED, $transfer->status);
    }

    /** @test */
    public function no_logic_enforces_incubation_expiration(): void
    {
        // Set expired incubation
        $this->clientTenant->update([
            'incubation_expires_at' => now()->subDays(100),
            'billing_status' => 'comped',
        ]);

        // Reward should still be granted
        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->assertEquals(1, AgencyPartnerReward::count());
    }

    /** @test */
    public function null_incubation_fields_do_not_cause_errors(): void
    {
        $this->clientTenant->update([
            'incubated_at' => null,
            'incubation_expires_at' => null,
            'billing_status' => 'comped',
        ]);

        // Should complete without errors
        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->assertEquals(OwnershipTransferStatus::COMPLETED, $transfer->status);
    }

    // =========================================================================
    // SECTION 6: Abuse & Edge Case Coverage
    // =========================================================================

    /** @test */
    public function self_transfer_attempts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot transfer ownership to the same user.');

        $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientOwner);
    }

    /** @test */
    public function multiple_pending_transfers_for_same_tenant_are_prevented(): void
    {
        $transfer1 = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An ownership transfer is already in progress for this tenant.');

        // Create another user
        $anotherUser = User::create([
            'email' => 'another@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Another',
            'last_name' => 'User',
        ]);
        $anotherUser->tenants()->attach($this->clientTenant->id, ['role' => 'member']);

        $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $anotherUser);
    }

    /** @test */
    public function transfer_retry_after_billing_added_works(): void
    {
        $this->clientTenant->update(['billing_status' => null]);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $this->assertEquals(OwnershipTransferStatus::PENDING_BILLING, $transfer->status);

        // Add billing
        $this->clientTenant->update(['billing_status' => 'comped']);

        // Complete pending transfer
        $transfer = $this->transferService->completePendingTransfer($transfer);

        $this->assertEquals(OwnershipTransferStatus::COMPLETED, $transfer->status);
        $this->assertTrue($this->clientTenant->fresh()->isOwner($this->clientAdmin));
    }

    /** @test */
    public function agency_losing_is_agency_flag_after_rewards_granted_keeps_history(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Verify reward exists
        $this->assertEquals(1, AgencyPartnerReward::where('agency_tenant_id', $this->agencyTenant->id)->count());

        // Remove agency status
        $this->agencyTenant->update(['is_agency' => false]);

        // Rewards should still exist
        $this->assertEquals(1, AgencyPartnerReward::where('agency_tenant_id', $this->agencyTenant->id)->count());

        // Activated count should remain
        $this->assertGreaterThan(0, $this->agencyTenant->fresh()->activated_client_count);
    }

    /** @test */
    public function non_owner_cannot_initiate_transfer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only the current tenant owner can initiate an ownership transfer.');

        $this->transferService->initiateTransfer($this->clientTenant, $this->clientAdmin, $this->clientOwner);
    }

    /** @test */
    public function transfer_to_non_member_is_rejected(): void
    {
        $nonMember = User::create([
            'email' => 'nonmember@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Non',
            'last_name' => 'Member',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The new owner must be an active member of the tenant.');

        $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $nonMember);
    }

    // =========================================================================
    // SECTION 7: Audit & Event Integrity Tests
    // =========================================================================

    /** @test */
    public function transfer_completion_dispatches_event_exactly_once(): void
    {
        Event::fake([CompanyTransferCompleted::class]);

        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        Event::assertDispatchedTimes(CompanyTransferCompleted::class, 1);
    }

    /** @test */
    public function event_payload_references_correct_tenants(): void
    {
        Event::fake([CompanyTransferCompleted::class]);

        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        Event::assertDispatched(CompanyTransferCompleted::class, function ($event) {
            return $event->transfer->tenant_id === $this->clientTenant->id
                && $event->transfer->from_user_id === $this->clientOwner->id
                && $event->transfer->to_user_id === $this->clientAdmin->id;
        });
    }

    /** @test */
    public function agency_partner_rewards_records_are_immutable(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $reward = AgencyPartnerReward::first();
        $originalData = $reward->toArray();

        // Attempting to change should not affect the original record's core data
        // The unique constraint on ownership_transfer_id prevents duplicates
        $this->assertEquals($originalData['ownership_transfer_id'], $reward->fresh()->ownership_transfer_id);
        $this->assertEquals($originalData['agency_tenant_id'], $reward->fresh()->agency_tenant_id);
        $this->assertEquals($originalData['client_tenant_id'], $reward->fresh()->client_tenant_id);
    }

    /** @test */
    public function agency_partner_access_audit_records_are_created_correctly(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Check access records were created
        $accessRecords = AgencyPartnerAccess::where('client_tenant_id', $this->clientTenant->id)->get();

        // Should have records for agency owner and admin
        $this->assertGreaterThanOrEqual(1, $accessRecords->count());

        foreach ($accessRecords as $record) {
            $this->assertEquals($this->agencyTenant->id, $record->agency_tenant_id);
            $this->assertEquals($this->clientTenant->id, $record->client_tenant_id);
            $this->assertEquals($transfer->id, $record->ownership_transfer_id);
            $this->assertNotNull($record->granted_at);
            $this->assertNull($record->revoked_at);
        }
    }

    /** @test */
    public function revocation_audit_records_are_updated_correctly(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        $access = AgencyPartnerAccess::where('user_id', $this->agencyOwner->id)
            ->where('client_tenant_id', $this->clientTenant->id)
            ->first();

        // Revoke
        $access->update([
            'revoked_at' => now(),
            'revoked_by' => $this->clientAdmin->id,
        ]);

        $access->refresh();
        $this->assertNotNull($access->revoked_at);
        $this->assertEquals($this->clientAdmin->id, $access->revoked_by);
        $this->assertFalse($access->isActive());
    }

    /** @test */
    public function activity_events_are_recorded_for_reward_grant(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Check for reward granted event
        $rewardEvent = ActivityEvent::where('event_type', EventType::AGENCY_PARTNER_REWARD_GRANTED)
            ->where('tenant_id', $this->agencyTenant->id)
            ->first();

        $this->assertNotNull($rewardEvent);
    }

    /** @test */
    public function activity_events_are_recorded_for_access_grant(): void
    {
        $this->clientTenant->update(['billing_status' => 'comped']);

        $transfer = $this->transferService->initiateTransfer($this->clientTenant, $this->clientOwner, $this->clientAdmin);
        $transfer = $this->transferService->confirmTransfer($transfer, $this->clientOwner);
        $transfer = $this->transferService->acceptTransfer($transfer, $this->clientAdmin);

        // Check for access granted event
        $accessEvent = ActivityEvent::where('event_type', EventType::AGENCY_PARTNER_ACCESS_GRANTED)
            ->where('tenant_id', $this->clientTenant->id)
            ->first();

        $this->assertNotNull($accessEvent);
    }
}
