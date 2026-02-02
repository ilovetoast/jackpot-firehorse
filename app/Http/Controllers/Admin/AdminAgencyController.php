<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventType;
use App\Enums\OwnershipTransferStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\AgencyPartnerAccess;
use App\Models\AgencyPartnerReferral;
use App\Models\AgencyPartnerReward;
use App\Models\AgencyTier;
use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Agency Controller
 * 
 * Phase AG-11 — Admin Agency Management & Oversight
 * 
 * Provides admin-only visibility and management for agencies.
 * Does NOT affect agency behavior, rewards, billing, or transfers.
 */
class AdminAgencyController extends Controller
{
    /**
     * Display the agencies index page.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAdmin();
        
        $query = Tenant::where('is_agency', true);
        
        // Search filter
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }
        
        // Tier filter
        if ($tierId = $request->get('tier')) {
            $query->where('agency_tier_id', $tierId);
        }
        
        // Approval status filter
        if ($request->has('approved')) {
            $approved = $request->get('approved') === 'true';
            if ($approved) {
                $query->whereNotNull('agency_approved_at');
            } else {
                $query->whereNull('agency_approved_at');
            }
        }
        
        $agencies = $query
            ->with(['agencyTier'])
            ->withCount([
                'agencyPartnerRewards as activated_clients_count',
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->appends($request->except('page'));
        
        // Get incubated and pending transfer counts
        $agencyIds = $agencies->pluck('id');
        
        $incubatedCounts = Tenant::whereIn('incubated_by_agency_id', $agencyIds)
            ->selectRaw('incubated_by_agency_id, COUNT(*) as count')
            ->groupBy('incubated_by_agency_id')
            ->pluck('count', 'incubated_by_agency_id');
        
        $pendingTransferCounts = OwnershipTransfer::whereHas('tenant', function ($q) use ($agencyIds) {
                $q->whereIn('incubated_by_agency_id', $agencyIds);
            })
            ->where('status', OwnershipTransferStatus::PENDING_BILLING)
            ->join('tenants', 'ownership_transfers.tenant_id', '=', 'tenants.id')
            ->selectRaw('tenants.incubated_by_agency_id, COUNT(*) as count')
            ->groupBy('tenants.incubated_by_agency_id')
            ->pluck('count', 'incubated_by_agency_id');
        
        return Inertia::render('Admin/Agencies/Index', [
            'agencies' => $agencies->map(function ($agency) use ($incubatedCounts, $pendingTransferCounts) {
                return [
                    'id' => $agency->id,
                    'name' => $agency->name,
                    'slug' => $agency->slug,
                    'tier' => $agency->agencyTier?->name ?? 'None',
                    'tier_id' => $agency->agency_tier_id,
                    'activated_clients' => $agency->activated_client_count ?? 0,
                    'incubated_clients' => $incubatedCounts[$agency->id] ?? 0,
                    'pending_transfers' => $pendingTransferCounts[$agency->id] ?? 0,
                    'is_approved' => $agency->agency_approved_at !== null,
                    'approved_at' => $agency->agency_approved_at?->toISOString(),
                    'created_at' => $agency->created_at->format('M d, Y'),
                ];
            }),
            'pagination' => [
                'current_page' => $agencies->currentPage(),
                'last_page' => $agencies->lastPage(),
                'per_page' => $agencies->perPage(),
                'total' => $agencies->total(),
            ],
            'tiers' => AgencyTier::orderBy('tier_order')->get(['id', 'name']),
            'filters' => [
                'search' => $request->get('search', ''),
                'tier' => $request->get('tier', ''),
                'approved' => $request->get('approved', ''),
            ],
        ]);
    }

    /**
     * Display the agency detail page.
     */
    public function show(Tenant $tenant): Response
    {
        $this->authorizeAdmin();
        
        if (!$tenant->is_agency) {
            abort(404, 'This company is not an agency.');
        }
        
        // Load agency tier
        $tenant->load('agencyTier');
        
        // Get incubated clients
        $incubatedClients = Tenant::where('incubated_by_agency_id', $tenant->id)
            ->get(['id', 'name', 'slug', 'incubated_at', 'incubation_expires_at'])
            ->map(function ($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'slug' => $client->slug,
                    'incubated_at' => $client->incubated_at?->toISOString(),
                    'incubation_expires_at' => $client->incubation_expires_at?->toISOString(),
                ];
            });
        
        // Get activated clients (via rewards)
        $activatedClients = AgencyPartnerReward::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant', 'ownershipTransfer'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($reward) {
                return [
                    'id' => $reward->clientTenant->id ?? null,
                    'name' => $reward->clientTenant->name ?? 'Unknown',
                    'slug' => $reward->clientTenant->slug ?? null,
                    'activated_at' => $reward->created_at->toISOString(),
                    'activated_at_human' => $reward->created_at->diffForHumans(),
                    'transfer_id' => $reward->ownership_transfer_id,
                ];
            });
        
        // Get pending billing transfers
        $pendingTransfers = OwnershipTransfer::whereHas('tenant', function ($q) use ($tenant) {
                $q->where('incubated_by_agency_id', $tenant->id);
            })
            ->where('status', OwnershipTransferStatus::PENDING_BILLING)
            ->with(['tenant', 'fromUser', 'toUser'])
            ->orderBy('accepted_at', 'desc')
            ->get()
            ->map(function ($transfer) {
                return [
                    'id' => $transfer->id,
                    'client_name' => $transfer->tenant->name,
                    'client_slug' => $transfer->tenant->slug,
                    'from_user' => $transfer->fromUser->name ?? 'Unknown',
                    'to_user' => $transfer->toUser->name ?? 'Unknown',
                    'accepted_at' => $transfer->accepted_at?->toISOString(),
                    'accepted_at_human' => $transfer->accepted_at?->diffForHumans(),
                ];
            });
        
        // Get referrals
        $referrals = AgencyPartnerReferral::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($referral) {
                return [
                    'id' => $referral->id,
                    'client_name' => $referral->clientTenant->name ?? 'Unknown',
                    'source' => $referral->source,
                    'activated_at' => $referral->activated_at?->toISOString(),
                    'is_activated' => $referral->isActivated(),
                    'created_at' => $referral->created_at->toISOString(),
                ];
            });
        
        // Get partner access grants
        $accessGrants = AgencyPartnerAccess::where('agency_tenant_id', $tenant->id)
            ->with(['clientTenant', 'user'])
            ->orderBy('granted_at', 'desc')
            ->get()
            ->map(function ($access) {
                return [
                    'id' => $access->id,
                    'client_name' => $access->clientTenant->name ?? 'Unknown',
                    'user_name' => $access->user->name ?? 'Unknown',
                    'granted_at' => $access->granted_at->toISOString(),
                    'revoked_at' => $access->revoked_at?->toISOString(),
                    'is_active' => $access->isActive(),
                ];
            });
        
        // Get recent activity
        $recentActivity = ActivityEvent::where('tenant_id', $tenant->id)
            ->whereIn('event_type', [
                EventType::AGENCY_PARTNER_REWARD_GRANTED,
                EventType::AGENCY_TIER_ADVANCED,
                EventType::AGENCY_PARTNER_ACCESS_GRANTED,
                EventType::AGENCY_PARTNER_ACCESS_REVOKED,
                EventType::AGENCY_PARTNER_REFERRAL_ACTIVATED,
            ])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($event) {
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'metadata' => $event->metadata,
                    'created_at' => $event->created_at->toISOString(),
                    'created_at_human' => $event->created_at->diffForHumans(),
                ];
            });
        
        // Get all tiers for tier change dropdown
        $tiers = AgencyTier::orderBy('tier_order')->get();
        
        // Calculate tier progression
        $currentTier = $tenant->agencyTier;
        $nextTier = $currentTier 
            ? AgencyTier::where('tier_order', '>', $currentTier->tier_order)->orderBy('tier_order')->first()
            : $tiers->first();
        
        return Inertia::render('Admin/Agencies/Show', [
            'agency' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_agency' => $tenant->is_agency,
                'tier' => $currentTier ? [
                    'id' => $currentTier->id,
                    'name' => $currentTier->name,
                    'order' => $currentTier->tier_order,
                    'reward_percentage' => $currentTier->reward_percentage,
                    'activation_threshold' => $currentTier->activation_threshold,
                ] : null,
                'next_tier' => $nextTier ? [
                    'id' => $nextTier->id,
                    'name' => $nextTier->name,
                    'threshold' => $nextTier->activation_threshold,
                ] : null,
                'activated_client_count' => $tenant->activated_client_count ?? 0,
                'is_approved' => $tenant->agency_approved_at !== null,
                'approved_at' => $tenant->agency_approved_at?->toISOString(),
                'approved_by' => $tenant->agency_approved_by,
                'created_at' => $tenant->created_at->format('M d, Y'),
            ],
            'clients' => [
                'incubated' => $incubatedClients,
                'activated' => $activatedClients,
                'pending_transfers' => $pendingTransfers,
            ],
            'referrals' => $referrals,
            'access_grants' => $accessGrants,
            'recent_activity' => $recentActivity,
            'tiers' => $tiers->map(function ($tier) {
                return [
                    'id' => $tier->id,
                    'name' => $tier->name,
                    'order' => $tier->tier_order,
                    'activation_threshold' => $tier->activation_threshold,
                ];
            }),
        ]);
    }

    /**
     * Approve agency status.
     */
    public function approve(Tenant $tenant): JsonResponse
    {
        $this->authorizeAdmin();
        
        if (!$tenant->is_agency) {
            return response()->json(['error' => 'This company is not an agency.'], 400);
        }
        
        if ($tenant->agency_approved_at) {
            return response()->json(['error' => 'Agency is already approved.'], 400);
        }
        
        $tenant->update([
            'agency_approved_at' => now(),
            'agency_approved_by' => Auth::id(),
        ]);
        
        // Log activity
        ActivityRecorder::record(
            $tenant,
            'agency.approved',
            $tenant,
            Auth::user(),
            null,
            ['approved_by' => Auth::id()]
        );
        
        return response()->json(['success' => true]);
    }

    /**
     * Revoke agency approval.
     */
    public function revokeApproval(Tenant $tenant): JsonResponse
    {
        $this->authorizeAdmin();
        
        if (!$tenant->is_agency) {
            return response()->json(['error' => 'This company is not an agency.'], 400);
        }
        
        if (!$tenant->agency_approved_at) {
            return response()->json(['error' => 'Agency is not approved.'], 400);
        }
        
        $tenant->update([
            'agency_approved_at' => null,
            'agency_approved_by' => null,
        ]);
        
        // Log activity
        ActivityRecorder::record(
            $tenant,
            'agency.approval_revoked',
            $tenant,
            Auth::user(),
            null,
            ['revoked_by' => Auth::id()]
        );
        
        return response()->json(['success' => true]);
    }

    /**
     * Update agency tier.
     */
    public function updateTier(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeAdmin();
        
        if (!$tenant->is_agency) {
            return response()->json(['error' => 'This company is not an agency.'], 400);
        }
        
        $validated = $request->validate([
            'tier_id' => 'required|exists:agency_tiers,id',
        ]);
        
        $oldTierId = $tenant->agency_tier_id;
        $newTier = AgencyTier::find($validated['tier_id']);
        
        $tenant->update([
            'agency_tier_id' => $newTier->id,
        ]);
        
        // Log activity
        ActivityRecorder::record(
            $tenant,
            'agency.tier_changed',
            $tenant,
            Auth::user(),
            null,
            [
                'old_tier_id' => $oldTierId,
                'new_tier_id' => $newTier->id,
                'new_tier_name' => $newTier->name,
                'changed_by' => Auth::id(),
            ]
        );
        
        return response()->json(['success' => true, 'tier' => $newTier->name]);
    }

    /**
     * Toggle agency status (grant/revoke is_agency).
     */
    public function toggleAgencyStatus(Tenant $tenant): JsonResponse
    {
        $this->authorizeAdmin();
        
        $wasAgency = $tenant->is_agency;
        
        $tenant->update([
            'is_agency' => !$wasAgency,
            // Clear approval if revoking agency status
            'agency_approved_at' => !$wasAgency ? null : $tenant->agency_approved_at,
            'agency_approved_by' => !$wasAgency ? null : $tenant->agency_approved_by,
        ]);
        
        // Log activity
        ActivityRecorder::record(
            $tenant,
            $wasAgency ? 'agency.status_revoked' : 'agency.status_granted',
            $tenant,
            Auth::user(),
            null,
            ['changed_by' => Auth::id()]
        );
        
        return response()->json([
            'success' => true,
            'is_agency' => $tenant->is_agency,
        ]);
    }

    /**
     * Get agency stats for admin dashboard.
     */
    public function stats(): JsonResponse
    {
        $this->authorizeAdmin();
        
        $stats = cache()->remember('admin_agency_stats', 300, function () {
            $totalAgencies = Tenant::where('is_agency', true)->count();
            
            $tierCounts = Tenant::where('is_agency', true)
                ->selectRaw('agency_tier_id, COUNT(*) as count')
                ->groupBy('agency_tier_id')
                ->pluck('count', 'agency_tier_id');
            
            $tiers = AgencyTier::orderBy('tier_order')->get();
            $byTier = [];
            foreach ($tiers as $tier) {
                $byTier[$tier->name] = $tierCounts[$tier->id] ?? 0;
            }
            
            $totalActivatedClients = Tenant::where('is_agency', true)->sum('activated_client_count');
            $totalIncubatedClients = Tenant::whereNotNull('incubated_by_agency_id')->count();
            $pendingApproval = Tenant::where('is_agency', true)->whereNull('agency_approved_at')->count();
            
            return [
                'total_agencies' => $totalAgencies,
                'by_tier' => $byTier,
                'total_activated_clients' => (int) $totalActivatedClients,
                'total_incubated_clients' => $totalIncubatedClients,
                'pending_approval' => $pendingApproval,
            ];
        });
        
        return response()->json($stats);
    }

    /**
     * Authorize admin access.
     */
    protected function authorizeAdmin(): void
    {
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }
    }
}
