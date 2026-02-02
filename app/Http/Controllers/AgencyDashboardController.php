<?php

namespace App\Http\Controllers;

use App\Models\AgencyPartnerReferral;
use App\Models\AgencyPartnerReward;
use App\Models\AgencyTier;
use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Enums\OwnershipTransferStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency Dashboard Controller
 * 
 * Phase AG-7 — Agency Dashboard & Credits Visibility
 * Phase AG-8 — UX Polish & Transfer Nudges (Non-Blocking)
 * Phase AG-10 — Partner Marketing & Referral Attribution (Foundational)
 * 
 * Provides read-only visibility into agency partner program status.
 * Only accessible to tenants with is_agency = true.
 * 
 * AG-8: Added computed flags for UI nudges (informational only, no enforcement).
 * AG-10: Added referral tracking (attribution only, no rewards).
 */
class AgencyDashboardController extends Controller
{
    /**
     * Display the agency dashboard.
     * 
     * READ-ONLY: No mutation actions allowed.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();
        
        if (!$tenant) {
            abort(403, 'Tenant must be selected.');
        }
        
        // Only allow access to agency tenants
        if (!$tenant->is_agency) {
            abort(403, 'This page is only available to agency partners.');
        }
        
        // Get agency tier info
        $agencyTier = $tenant->agencyTier;
        $tierName = $agencyTier?->name ?? 'None';
        $tierOrder = $agencyTier?->tier_order ?? 0;
        $activatedCount = $tenant->activated_client_count ?? 0;
        
        // Get next tier threshold
        $nextTier = null;
        $nextTierThreshold = null;
        $activationsToNextTier = null;
        if ($agencyTier) {
            $nextTier = AgencyTier::where('tier_order', '>', $agencyTier->tier_order)
                ->orderBy('tier_order', 'asc')
                ->first();
            
            if ($nextTier) {
                $nextTierThreshold = $nextTier->activation_threshold ?? null;
                // Phase AG-8: Compute activations needed for tier progress nudge
                if ($nextTierThreshold !== null) {
                    $activationsToNextTier = max(0, $nextTierThreshold - $activatedCount);
                }
            }
        }
        
        // Phase AG-8: Get incubation window from agency tier (informational only)
        $incubationWindowDays = $agencyTier?->incubation_window_days;
        
        // Get reward ledger (all rewards for this agency)
        $rewards = AgencyPartnerReward::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($reward) {
                return [
                    'id' => $reward->id,
                    'client_name' => $reward->clientTenant->name ?? 'Unknown',
                    'reward_type' => $reward->reward_type,
                    'reward_value' => $reward->reward_value,
                    'created_at' => $reward->created_at->toISOString(),
                    'created_at_human' => $reward->created_at->diffForHumans(),
                ];
            });
        
        // Get incubated clients (tenants incubated by this agency)
        $incubatedClients = Tenant::where('incubated_by_agency_id', $tenant->id)
            ->whereNull('incubated_at') // Not yet transferred/activated
            ->orWhere(function ($query) use ($tenant) {
                $query->where('incubated_by_agency_id', $tenant->id)
                      ->whereNotNull('incubated_at')
                      ->whereDoesntHave('ownershipTransfers', function ($q) {
                          $q->where('status', OwnershipTransferStatus::COMPLETED);
                      });
            })
            ->get(['id', 'name', 'slug', 'incubated_at', 'incubation_expires_at'])
            ->map(function ($client) {
                // Phase AG-8: Compute days remaining for incubation window awareness
                $daysRemaining = null;
                $expiringsSoon = false;
                if ($client->incubation_expires_at) {
                    $daysRemaining = (int) now()->diffInDays($client->incubation_expires_at, false);
                    // Negative means already expired, but we don't enforce - just show warning
                    $expiringsSoon = $daysRemaining >= 0 && $daysRemaining < 7;
                }
                
                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'slug' => $client->slug,
                    'incubated_at' => $client->incubated_at?->toISOString(),
                    'incubation_expires_at' => $client->incubation_expires_at?->toISOString(),
                    // Phase AG-8: Computed flags for UI nudges (no enforcement)
                    'days_remaining' => $daysRemaining,
                    'expiring_soon' => $expiringsSoon,
                ];
            });
        
        // Get activated clients (clients with completed transfers)
        $activatedClients = AgencyPartnerReward::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($reward) {
                return [
                    'id' => $reward->clientTenant->id,
                    'name' => $reward->clientTenant->name,
                    'slug' => $reward->clientTenant->slug,
                    'activated_at' => $reward->created_at->toISOString(),
                    'activated_at_human' => $reward->created_at->diffForHumans(),
                ];
            });
        
        // Get pending transfers (waiting for billing)
        $pendingTransfers = OwnershipTransfer::whereHas('tenant', function ($query) use ($tenant) {
                $query->where('incubated_by_agency_id', $tenant->id);
            })
            ->where('status', OwnershipTransferStatus::PENDING_BILLING)
            ->with(['tenant'])
            ->orderBy('accepted_at', 'desc')
            ->get()
            ->map(function ($transfer) {
                return [
                    'id' => $transfer->id,
                    'client_name' => $transfer->tenant->name,
                    'client_slug' => $transfer->tenant->slug,
                    'accepted_at' => $transfer->accepted_at?->toISOString(),
                    'accepted_at_human' => $transfer->accepted_at?->diffForHumans(),
                ];
            });
        
        // Phase AG-10: Get referrals (separate from incubation)
        $referrals = AgencyPartnerReferral::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($referral) {
                return [
                    'id' => $referral->id,
                    'client_name' => $referral->clientTenant->name ?? 'Unknown',
                    'client_slug' => $referral->clientTenant->slug ?? null,
                    'source' => $referral->source,
                    'activated_at' => $referral->activated_at?->toISOString(),
                    'activated_at_human' => $referral->activated_at?->diffForHumans(),
                    'is_activated' => $referral->isActivated(),
                    'created_at' => $referral->created_at->toISOString(),
                    'created_at_human' => $referral->created_at->diffForHumans(),
                ];
            });
        
        // Separate activated and pending referrals
        $activatedReferrals = $referrals->where('is_activated', true)->values();
        $pendingReferrals = $referrals->where('is_activated', false)->values();
        
        return Inertia::render('Agency/Dashboard', [
            'tenant' => $tenant,
            'agency' => [
                'tier' => [
                    'name' => $tierName,
                    'order' => $tierOrder,
                    'reward_percentage' => $agencyTier?->reward_percentage,
                    // Phase AG-8: Incubation window for informational display
                    'incubation_window_days' => $incubationWindowDays,
                ],
                'activated_client_count' => $activatedCount,
                'next_tier' => $nextTier ? [
                    'name' => $nextTier->name,
                    'threshold' => $nextTierThreshold,
                    'progress_percentage' => $nextTierThreshold 
                        ? min(($activatedCount / $nextTierThreshold) * 100, 100) 
                        : 100,
                    // Phase AG-8: Computed activations needed for tier progress nudge
                    'activations_to_next_tier' => $activationsToNextTier,
                ] : null,
            ],
            'rewards' => $rewards,
            'clients' => [
                'incubated' => $incubatedClients,
                'activated' => $activatedClients,
                'pending_transfers' => $pendingTransfers,
            ],
            // Phase AG-10: Referral tracking (attribution only, no rewards)
            'referrals' => [
                'total' => $referrals->count(),
                'activated' => $activatedReferrals,
                'pending' => $pendingReferrals,
            ],
        ]);
    }
}
