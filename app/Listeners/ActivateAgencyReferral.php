<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\AgencyReferralActivated;
use App\Events\CompanyTransferCompleted;
use App\Models\AgencyPartnerReferral;
use App\Models\Tenant;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Activate Agency Referral Listener
 *
 * Phase AG-10 — Partner Marketing & Referral Attribution
 *
 * Activates referral attribution when a referred client completes
 * a company transfer with active billing.
 *
 * Requirements:
 * - NOT queued (immediate execution for auditability)
 * - Idempotent (checks for existing activation)
 * - Non-blocking (failures are logged but never throw)
 * - Does NOT grant rewards (attribution only)
 * - Does NOT advance tiers (attribution only)
 */
class ActivateAgencyReferral
{
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
                Log::debug('[ActivateAgencyReferral] No client tenant found', [
                    'transfer_id' => $transfer->id,
                ]);
                return;
            }

            // 2. Check if client was referred by an agency
            $agencyTenantId = $clientTenant->referred_by_agency_id;
            if (!$agencyTenantId) {
                Log::debug('[ActivateAgencyReferral] Client not referred by agency', [
                    'transfer_id' => $transfer->id,
                    'client_tenant_id' => $clientTenant->id,
                ]);
                return;
            }

            // 3. Load the agency tenant
            $agencyTenant = Tenant::find($agencyTenantId);
            if (!$agencyTenant) {
                Log::warning('[ActivateAgencyReferral] Agency tenant not found', [
                    'transfer_id' => $transfer->id,
                    'agency_tenant_id' => $agencyTenantId,
                ]);
                return;
            }

            // 4. Verify agency tenant is actually an agency
            if (!$agencyTenant->is_agency) {
                Log::warning('[ActivateAgencyReferral] Referring tenant is not an agency', [
                    'transfer_id' => $transfer->id,
                    'agency_tenant_id' => $agencyTenantId,
                ]);
                return;
            }

            // 5. Verify billing is active
            if (!$this->hasBillingActive($clientTenant)) {
                Log::warning('[ActivateAgencyReferral] Client billing not active at referral activation time', [
                    'transfer_id' => $transfer->id,
                    'client_tenant_id' => $clientTenant->id,
                ]);
                return;
            }

            // 6. Check for existing referral record
            $referral = AgencyPartnerReferral::where('client_tenant_id', $clientTenant->id)->first();
            
            if ($referral && $referral->isActivated()) {
                Log::debug('[ActivateAgencyReferral] Referral already activated', [
                    'transfer_id' => $transfer->id,
                    'referral_id' => $referral->id,
                ]);
                return;
            }

            // 7. Create or update referral record
            DB::transaction(function () use ($transfer, $agencyTenant, $clientTenant, $referral) {
                if ($referral) {
                    // Update existing referral
                    $referral->update([
                        'activated_at' => now(),
                        'ownership_transfer_id' => $transfer->id,
                    ]);
                } else {
                    // Create new referral record
                    $referral = AgencyPartnerReferral::create([
                        'agency_tenant_id' => $agencyTenant->id,
                        'client_tenant_id' => $clientTenant->id,
                        'source' => $clientTenant->referral_source,
                        'activated_at' => now(),
                        'ownership_transfer_id' => $transfer->id,
                    ]);
                }

                Log::info('[ActivateAgencyReferral] Referral activated', [
                    'referral_id' => $referral->id,
                    'agency_tenant_id' => $agencyTenant->id,
                    'client_tenant_id' => $clientTenant->id,
                    'transfer_id' => $transfer->id,
                ]);

                // Record activity event
                ActivityRecorder::record(
                    $agencyTenant,
                    EventType::AGENCY_PARTNER_REFERRAL_ACTIVATED,
                    $referral,
                    'system',
                    null,
                    [
                        'client_tenant_id' => $clientTenant->id,
                        'transfer_id' => $transfer->id,
                        'source' => $clientTenant->referral_source,
                    ]
                );

                // Emit event for further processing (no rewards in this phase)
                AgencyReferralActivated::dispatch($referral);
            });
        } catch (\Exception $e) {
            // Never throw - referral activation must never block transfer completion
            Log::error('[ActivateAgencyReferral] Listener failed', [
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
     * @param Tenant $tenant
     * @return bool
     */
    private function hasBillingActive(Tenant $tenant): bool
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
}
