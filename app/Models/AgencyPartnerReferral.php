<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agency Partner Referral Model
 * 
 * Phase AG-10 — Partner Marketing & Referral Attribution
 * 
 * Tracks referral-based client attributions separately from incubation.
 * One row per referred client tenant.
 * 
 * NOTE: This is separate from incubation. A tenant can be:
 * - Incubated only (built by agency, transferred)
 * - Referred only (signed up via referral, not built by agency)
 * - Both (incubated AND referred)
 * 
 * Rewards are NOT granted from referrals in this phase.
 */
class AgencyPartnerReferral extends Model
{
    /**
     * Disable Laravel's timestamps (we only use created_at).
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'agency_tenant_id',
        'client_tenant_id',
        'source',
        'activated_at',
        'ownership_transfer_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the agency tenant that made the referral.
     */
    public function agencyTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'agency_tenant_id');
    }

    /**
     * Get the client tenant that was referred.
     */
    public function clientTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'client_tenant_id');
    }

    /**
     * Get the ownership transfer that activated this referral (if any).
     */
    public function ownershipTransfer(): BelongsTo
    {
        return $this->belongsTo(OwnershipTransfer::class);
    }

    /**
     * Check if this referral has been activated.
     */
    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Check if this referral is pending (not yet activated).
     */
    public function isPending(): bool
    {
        return $this->activated_at === null;
    }
}
