<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\CompanyTransferCompleted;
use App\Models\AgencyPartnerAccess;
use App\Models\AgencyPartnerReward;
use App\Models\AgencyTier;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Grant Agency Partner Reward Listener
 *
 * Phase AG-4 — Partner Reward Attribution
 * Phase AG-5 — Post-Transfer Agency Partner Access
 *
 * Grants partner rewards to agencies when their incubated clients
 * complete a company transfer with active billing.
 *
 * Also grants agency partner access to the agency's owner/admins
 * on the client tenant.
 *
 * Requirements:
 * - NOT queued (immediate execution for auditability)
 * - Idempotent (checks for existing reward and access)
 * - Non-blocking (failures are logged but never throw)
 * - Strict eligibility checks
 */
class GrantAgencyPartnerReward
{
    /**
     * Tier advancement thresholds (DEPRECATED - now read from database).
     * Phase AG-6: Thresholds are now stored in agency_tiers.activation_threshold.
     * This constant is kept as fallback only.
     */
    private const TIER_THRESHOLDS_FALLBACK = [
        'Silver' => 0,
        'Gold' => 5,
        'Platinum' => 15,
    ];

    /**
     * Handle the event.
     *
     * @param CompanyTransferCompleted $event
     * @return void
     */
    public function handle(CompanyTransferCompleted $event): void
    {
        $transfer = $event->transfer;

        try {
            // 1. Get the client tenant
            $clientTenant = $transfer->tenant;
            if (!$clientTenant) {
                Log::debug('[GrantAgencyPartnerReward] No client tenant found', [
                    'transfer_id' => $transfer->id,
                ]);
                return;
            }

            // 2. Check if client was incubated by an agency
            $agencyTenantId = $clientTenant->incubated_by_agency_id;
            if (!$agencyTenantId) {
                Log::debug('[GrantAgencyPartnerReward] Client not incubated by agency', [
                    'transfer_id' => $transfer->id,
                    'client_tenant_id' => $clientTenant->id,
                ]);
                return;
            }

            // 3. Load the agency tenant
            $agencyTenant = \App\Models\Tenant::find($agencyTenantId);
            if (!$agencyTenant) {
                Log::warning('[GrantAgencyPartnerReward] Agency tenant not found', [
                    'transfer_id' => $transfer->id,
                    'agency_tenant_id' => $agencyTenantId,
                ]);
                return;
            }

            // 4. Verify agency tenant is actually an agency
            if (!$agencyTenant->is_agency) {
                Log::warning('[GrantAgencyPartnerReward] Incubating tenant is not an agency', [
                    'transfer_id' => $transfer->id,
                    'agency_tenant_id' => $agencyTenantId,
                ]);
                return;
            }

            // 5. Verify billing is active (redundant check for safety)
            if (!$this->hasBillingActive($clientTenant)) {
                Log::warning('[GrantAgencyPartnerReward] Client billing not active at reward time', [
                    'transfer_id' => $transfer->id,
                    'client_tenant_id' => $clientTenant->id,
                ]);
                return;
            }

            // 6. Check for existing reward (idempotency)
            $existingReward = AgencyPartnerReward::where('ownership_transfer_id', $transfer->id)->first();
            if ($existingReward) {
                Log::debug('[GrantAgencyPartnerReward] Reward already exists', [
                    'transfer_id' => $transfer->id,
                    'reward_id' => $existingReward->id,
                ]);
                return;
            }

            // 7. All checks passed - grant the reward
            DB::transaction(function () use ($transfer, $agencyTenant, $clientTenant) {
                // Create reward ledger entry
                $reward = AgencyPartnerReward::create([
                    'agency_tenant_id' => $agencyTenant->id,
                    'client_tenant_id' => $clientTenant->id,
                    'ownership_transfer_id' => $transfer->id,
                    'reward_type' => 'company_activation',
                    'reward_value' => null, // No cash value in this phase
                ]);

                // Increment activated client count
                $agencyTenant->increment('activated_client_count');

                // Check for tier advancement
                $this->checkTierAdvancement($agencyTenant->fresh());

                Log::info('[GrantAgencyPartnerReward] Reward granted', [
                    'reward_id' => $reward->id,
                    'agency_tenant_id' => $agencyTenant->id,
                    'client_tenant_id' => $clientTenant->id,
                    'transfer_id' => $transfer->id,
                    'activated_client_count' => $agencyTenant->fresh()->activated_client_count,
                ]);

                // Record activity event
                ActivityRecorder::record(
                    $agencyTenant,
                    EventType::AGENCY_PARTNER_REWARD_GRANTED,
                    $reward,
                    'system',
                    null,
                    [
                        'client_tenant_id' => $clientTenant->id,
                        'transfer_id' => $transfer->id,
                        'reward_type' => 'company_activation',
                    ]
                );

                // Phase AG-5: Grant agency partner access
                $this->grantAgencyPartnerAccess($agencyTenant, $clientTenant, $transfer);
            });
        } catch (\Exception $e) {
            // Never throw - reward granting must never block transfer completion
            Log::error('[GrantAgencyPartnerReward] Listener failed', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Check if tenant has active billing.
     * Duplicated from OwnershipTransferService for isolation.
     *
     * @param \App\Models\Tenant $tenant
     * @return bool
     */
    private function hasBillingActive(\App\Models\Tenant $tenant): bool
    {
        // Check if tenant has an active subscription via Cashier
        if ($tenant->subscribed('default')) {
            return true;
        }

        // Check if tenant has a valid payment method attached
        if ($tenant->hasDefaultPaymentMethod()) {
            return true;
        }

        // Check manual billing status overrides
        if (in_array($tenant->billing_status, ['paid', 'comped'])) {
            if (!$tenant->billing_status_expires_at || $tenant->billing_status_expires_at->isFuture()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if agency should advance to a higher tier.
     * No downgrades. Tier upgrades happen immediately.
     *
     * Phase AG-6: Now reads thresholds from agency_tiers.activation_threshold.
     *
     * @param \App\Models\Tenant $agencyTenant
     * @return void
     */
    private function checkTierAdvancement(\App\Models\Tenant $agencyTenant): void
    {
        $activatedCount = $agencyTenant->activated_client_count;
        $currentTierId = $agencyTenant->agency_tier_id;

        // Phase AG-6: Get all tiers with their thresholds from database
        $tiers = AgencyTier::orderBy('tier_order', 'asc')->get();
        
        // Determine target tier based on activation_threshold (or fallback to tier_order)
        $targetTier = null;
        foreach ($tiers as $tier) {
            // Use activation_threshold if set, otherwise fall back to static thresholds
            $threshold = $tier->activation_threshold ?? self::TIER_THRESHOLDS_FALLBACK[$tier->name] ?? 0;
            
            if ($activatedCount >= $threshold) {
                $targetTier = $tier;
            }
        }

        if (!$targetTier) {
            Log::warning('[GrantAgencyPartnerReward] No eligible tier found', [
                'activated_client_count' => $activatedCount,
            ]);
            return;
        }

        // Only upgrade, never downgrade
        if ($currentTierId === null || $targetTier->tier_order > ($agencyTenant->agencyTier->tier_order ?? 0)) {
            $oldTierName = $agencyTenant->agencyTier?->name ?? 'none';
            
            $agencyTenant->update(['agency_tier_id' => $targetTier->id]);

            Log::info('[GrantAgencyPartnerReward] Tier advanced', [
                'agency_tenant_id' => $agencyTenant->id,
                'from_tier' => $oldTierName,
                'to_tier' => $targetTier->name,
                'activated_client_count' => $activatedCount,
                'threshold_used' => $targetTier->activation_threshold,
            ]);

            // Record activity event for tier advancement
            ActivityRecorder::record(
                $agencyTenant,
                EventType::AGENCY_TIER_ADVANCED,
                $agencyTenant,
                'system',
                null,
                [
                    'from_tier' => $oldTierName,
                    'to_tier' => $targetTier->name,
                    'activated_client_count' => $activatedCount,
                    'threshold_used' => $targetTier->activation_threshold,
                ]
            );
        }
    }

    /**
     * Grant agency partner access to agency users on client tenant.
     *
     * Phase AG-5: Post-Transfer Agency Partner Access
     *
     * Assigns agency_partner role to agency's owner and admins on the client tenant.
     * Creates audit trail in agency_partner_access table.
     *
     * @param \App\Models\Tenant $agencyTenant
     * @param \App\Models\Tenant $clientTenant
     * @param \App\Models\OwnershipTransfer $transfer
     * @return void
     */
    private function grantAgencyPartnerAccess(
        \App\Models\Tenant $agencyTenant,
        \App\Models\Tenant $clientTenant,
        \App\Models\OwnershipTransfer $transfer
    ): void {
        try {
            // Get agency owner and admins
            $agencyUsers = $agencyTenant->users()
                ->wherePivotIn('role', ['owner', 'admin'])
                ->get();

            if ($agencyUsers->isEmpty()) {
                Log::warning('[GrantAgencyPartnerReward] No agency owner/admins found for access grant', [
                    'agency_tenant_id' => $agencyTenant->id,
                    'client_tenant_id' => $clientTenant->id,
                ]);
                return;
            }

            $grantedCount = 0;
            foreach ($agencyUsers as $user) {
                // Check if user already has access (idempotency)
                $existingAccess = AgencyPartnerAccess::where('user_id', $user->id)
                    ->where('client_tenant_id', $clientTenant->id)
                    ->whereNull('revoked_at')
                    ->first();

                if ($existingAccess) {
                    Log::debug('[GrantAgencyPartnerReward] User already has partner access', [
                        'user_id' => $user->id,
                        'client_tenant_id' => $clientTenant->id,
                    ]);
                    continue;
                }

                // Check if user already has a role on the client tenant
                $existingRole = $user->getRoleForTenant($clientTenant);
                if ($existingRole) {
                    Log::debug('[GrantAgencyPartnerReward] User already has role on client tenant', [
                        'user_id' => $user->id,
                        'client_tenant_id' => $clientTenant->id,
                        'existing_role' => $existingRole,
                    ]);
                    continue;
                }

                // Assign agency_partner role on client tenant
                $user->setRoleForTenant($clientTenant, 'agency_partner');

                // Create audit trail
                $access = AgencyPartnerAccess::create([
                    'agency_tenant_id' => $agencyTenant->id,
                    'client_tenant_id' => $clientTenant->id,
                    'user_id' => $user->id,
                    'ownership_transfer_id' => $transfer->id,
                    'granted_at' => now(),
                ]);

                // Record activity event
                ActivityRecorder::record(
                    $clientTenant,
                    EventType::AGENCY_PARTNER_ACCESS_GRANTED,
                    $access,
                    'system',
                    null,
                    [
                        'agency_tenant_id' => $agencyTenant->id,
                        'user_id' => $user->id,
                        'transfer_id' => $transfer->id,
                    ]
                );

                $grantedCount++;
            }

            Log::info('[GrantAgencyPartnerReward] Agency partner access granted', [
                'agency_tenant_id' => $agencyTenant->id,
                'client_tenant_id' => $clientTenant->id,
                'users_granted' => $grantedCount,
            ]);
        } catch (\Exception $e) {
            // Never throw - access granting must never block reward attribution
            Log::error('[GrantAgencyPartnerReward] Failed to grant partner access', [
                'agency_tenant_id' => $agencyTenant->id,
                'client_tenant_id' => $clientTenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
